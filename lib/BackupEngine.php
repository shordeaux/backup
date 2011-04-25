<?php

class BackupEngine {
	protected $savedDatabases = array();
	protected $excludedDatabases = array();

	function excludeDatabase($database)
	{
		$this->excludedDatabases[$database] = true;
	}

	function notifySavedDatase($database)
	{
		$this->savedDatabases[$database] = true;
	}

	function listDatabases($login, $password)
	{
		$link = mysql_connect('localhost', $login, $password);
		$db_list = mysql_list_dbs($link);

		while ($row = mysql_fetch_object($db_list)) {
			$result[] = $row->Database;
		}
		mysql_close($link);
		return $result;
	}

	protected $excludedTables = array();

	function getExcludedTables($database){
		return isset($this->excludedTables[$database])?array_keys($this->excludedTables[$database]):array();
	}

	function excludeTable($database, $table){
		if(!isset($this->excludedTables[$database])){
			$this->excludedTables[$database] =array();
		}
		$this->excludedTables[$database][$table] = true;
	}
	function backupDatabases($login, $password, $folder)
	{
		foreach($this->listDatabases($login, $password) as $database) {
			if (!empty($this->excludedDatabases[$database])) {
				// excluded
			} elseif (!empty($this->savedDatabases[$database])) {
				// already saved
			} else {
				$processor = new BackupProcessor($this, $folder . DIRECTORY_SEPARATOR . $database . DIRECTORY_SEPARATOR);
				$lastBackup = $processor->getLatestDatabaseTimestamp($database);
				if (!$lastBackup || $processor->needsBackup($lastBackup)) {
					$processor->backupDatabase($database, $login, $password);
				}
			}
		}
	}

	protected $backupFolder;
	function __construct($backupFolder)
	{
		if (empty($backupFolder) || !is_dir($backupFolder)) {
			throw new Exception('Invalid backup folder: ' . $backupFolder);
		}
		$this->backupFolder = realpath($backupFolder) . DIRECTORY_SEPARATOR;
		$this->addDefaultRule();
	}
	private $rootFolder = null;
	function processRootFolder($path, $depth = 0)
	{
		$path = realpath($path) . DIRECTORY_SEPARATOR;
		if (null == $this->rootFolder) {
			$this->rootFolder = $path;
			$resetRootFolder = true;
		} else {
			$resetRootFolder = false;
		}
		if (0 == $depth) {
			$this->processFolder($path);
		} else {
			$dh = dir($path);
			while ($filename = $dh->read()) {
				$subPath = $path . $filename . DIRECTORY_SEPARATOR;
				if (('.' != $filename) && ('..' != $filename) && is_dir($subPath)) {
					if (empty($this->ignore[$subPath])) {
						$this->processRootFolder($subPath, $depth - 1);
					} else {
						printf("Ignoring %s\n", $subPath);
					}
				}
			}
			$dh->close();
		}
		if ($resetRootFolder) {
			$this->rootFolder = null;
		}
	}

	function processFolder($path)
	{
		$ruleName = $this->getRuleForFolder($path);
		$className = $this->rules[$ruleName]['class'];
		$backupFolder = $this->backupFolder . substr($path, strlen($this->rootFolder));
		$backupProcessor = new $className($this, $backupFolder, $this->rules[$ruleName]['options']);
		$backupProcessor->execute($path);
	}

	protected $paths = array();
	function defineRuleForPath($path, $rule)
	{
		$this->paths[$path] = $rule;
	}

	function getRuleForFolder($path)
	{
		if (isset($this->paths[$path])) {
			return $this->paths[$path];
		}

		return 'default';
	}

	protected $rules = array();
	function addRule($name, $backupProcessorClass, $options = array())
	{
		$this->rules[$name] = array('class' => $backupProcessorClass, 'options' => $options);
	}

	function addDefaultRule()
	{
		$this->addRule('default', 'BackupProcessor');
	}

	protected $ignore = array();
	function excludeFolder($path)
	{
		$this->ignore[realpath($path) . DIRECTORY_SEPARATOR] = true;
	}
}
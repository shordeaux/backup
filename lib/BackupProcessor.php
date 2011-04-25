<?php

class BackupProcessor {
	const FULL_BACKUP_SUFFIX = '_files_full.tgz';
	protected $cmd = array();

	function locateTools()
	{
		$this->cmd['tar'] = 'tar';
		$this->cmd['mysqldump'] = 'mysqldump';
	}

	protected $options = array();
	protected $backupFolder;
	protected $engine;
	function __construct($engine, $backupFolder, $options)
	{
		$this->engine = $engine;
		$this->options = $options;
		if (empty($this->options['backup-delay'])) {
			$this->options['backup-delay'] = 3600 * 12; // 12 hours
		}
		$this->backupFolder = $backupFolder;
		$this->locateTools();
	}

	function getBackupFolder()
	{
		$folder = $this->backupFolder . date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR;
		if (!file_exists($folder)) {
			mkdir($folder, 0777, true);
		}
		return $folder;
	}
	function execute($path)
	{
		printf("---- %s\n", $path);
		$this->backupFiles($path);
	}

	function backupDatabase($databaseName, $login, $password)
	{
		$backupFilename = $this->getBackupFolder() . date('Ymd_His') . '_sql_' . $databaseName . '.sql.gz';

		$cmd = escapeshellcmd($this->cmd['mysqldump']);
		$cmd .= ' --add-drop-table --add-locks --create-options --disable-keys --extended-insert --lock-tables --quick --set-charset';
		$cmd .= sprintf(' --user=%s', escapeshellarg($login));
		$cmd .= sprintf(' --password=%s', escapeshellarg($password));
		foreach($this->engine->getExcludedTables($databaseName) as $tableName){
			$cmd .= sprintf(' --ignore-table=%s.%s', escapeshellarg($databaseName), escapeshellarg($tableName));
		}
		$cmd .= ' ' . escapeshellarg($databaseName);
		$cmd .= '| gzip > ' . $backupFilename;
		printf("Backuping %s into %s\n", $databaseName, $backupFilename);
		//printf("%s\n", $cmd);
		exec($cmd, $out, $return_value);
		// var_dump($return_value);
		// var_dump($out);
	}

	private $_FullBackupFilename = null;
	function getFullBackupFilename()
	{
		if (null == $this->_FullBackupFilename) {
			$this->_FullBackupFilename = $this->getBackupFolder() . date('Ymd_His') . self::FULL_BACKUP_SUFFIX;
		}
		return $this->_FullBackupFilename;
	}

	private $_IncrementalBackupFilename = null;
	function getIncrementalBackupFilename($since)
	{
		if (null == $this->_IncrementalBackupFilename) {
			$this->_IncrementalBackupFilename = $this->getBackupFolder() . date('Ymd_His') . '_files_' . date('Ymd_His', $since) . '.tgz';
		}
		return $this->_IncrementalBackupFilename;
	}

	function hasFullBackup()
	{
		$result = false;
		$dh = dir($this->getBackupFolder());
		while ($filename = $dh->read()) {
			if (preg_match('/([0-9]{8})_([0-9]{6})' . self::FULL_BACKUP_SUFFIX . '$/', $filename, $tokens)) {
				$result = $this->getBackupFolder() . $filename;
			}
		}
		$dh->close();
		return $result;
	}

	protected function getLatestBackupTimestamp($regexp)
	{
		$result = false;
		$dh = dir($this->getBackupFolder());
		while ($filename = $dh->read()) {
			if (preg_match($regexp, $filename, $tokens)) {
				$thisTimestamp = filectime($dh->path . $filename);
				if ($thisTimestamp > $result) {
					$result = $thisTimestamp;
				}
			}
		}
		$dh->close();
		return $result;
	}

	function getLatestIncrementalTimestamp()
	{
		return $this->getLatestBackupTimestamp('/([0-9]{8})_([0-9]{6})_files_([0-9]{8})_([0-9]{6}).tgz$/');
	}

	function getLatestDatabaseTimestamp($databaseName)
	{
		return $this->getLatestBackupTimestamp('/([0-9]{8})_([0-9]{6})_sql_' . $databaseName . '.sql.gz$/');
	}

	function needsBackup($lastBackupTimestamp)
	{
		$age = time() - $lastBackupTimestamp;
		return $age > $this->getBackupDelay();
	}

	function getBackupDelay()
	{
		return $this->options['backup-delay'];
	}

	function backupFiles($path)
	{
		if ($FullBackup = $this->hasFullBackup()) {
			if (!$lastBackupTimestamp = $this->getLatestIncrementalTimestamp()) {
				$lastBackupTimestamp = filectime($FullBackup);
			}
			if ($this->needsBackup($lastBackupTimestamp)) {
				$this->backupFilesIncremental($path);
			}
		} else {
			$this->backupFilesFull($path);
		}
	}

	function getPathToIgnore($rootPath)
	{
		return array();
	}

	function backupFilesFull($path)
	{
		$backupFilename = $this->getFullBackupFilename();

		$cmd = sprintf('%s --create --gzip --file=%s',
		    escapeshellcmd($this->cmd['tar']),
		    escapeshellarg($backupFilename)
		    );

		foreach($this->getPathToIgnore($path) as $uselessItem) {
			$cmd .= sprintf(' --exclude %s', escapeshellarg($uselessItem));
		}
		$cmd .= ' ' . escapeshellarg($path);
		$cmd .= ' &>/dev/null';
		printf("Full backup of %s into %s\n", $path, $backupFilename);
		// printf("%s\n", $cmd);
		exec($cmd, $out, $return_value);
		// var_dump($return_value);
		// var_dump($out);
	}

	function backupFilesIncremental($path)
	{
		$fullBackupFilename = $this->hasFullBackup();
		$since = filectime($fullBackupFilename);
		printf("Incremental backup since %s of %s\n", date('d/m/Y H:i', $since), $path);

		$cmd = sprintf('find %s -newer %s -print | xargs %s --no-recursion --create --gzip --file=%s',
		    escapeshellcmd($path),
		    escapeshellcmd($fullBackupFilename),
		    escapeshellcmd($this->cmd['tar']),
		    escapeshellarg($this->getIncrementalBackupFilename($since))
		    );

		foreach($this->getPathToIgnore($path) as $uselessItem) {
			$cmd .= sprintf(' --exclude %s', escapeshellarg($uselessItem));
		}
		$cmd .= ' ' . escapeshellarg($path);
		// printf("%s\n", $cmd);
		exec($cmd/*. ' 2>/dev/null'*/ , $out, $return_value);
		// var_dump($return_value);
		// var_dump($out);
	}
}
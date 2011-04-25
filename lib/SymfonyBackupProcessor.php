<?php

require_once(dirname(__FILE__).'/BackupProcessor.php');
class SymfonyBackupProcessor extends BackupProcessor {
	function getPathToIgnore($rootPath)
	{
		$result = parent::getPathToIgnore($rootPath);
		$result[] = $rootPath . 'cache';
		// $result[] = $rootPath . DIRECTORY_SEPARATOR . 'log';
		return $result;
	}
	function execute($path)
	{
		parent::execute($path);
		if (list($database, $login, $password) = $this->getDatabaseInfos($path)) {
			$lastBackup = $this->getLatestDatabaseTimestamp($database);
			if (!$lastBackup || $this->needsBackup($lastBackup)) {
				$this->backupDatabase($database, $login, $password);
			}
		}
	}

	protected function extractEnvironmentFromControler($controler)
	{
		$content = file_get_contents($controler);
		if (preg_match('/getApplicationConfiguration\(\'[a-z]+\', \'([a-z]+)\', [a-z]+\)/imsU', $content, $tokens)) {
			return $tokens[1];
		}
		if (preg_match('/define\(\'SF_ENVIRONMENT\', \'([a-z]+)\'\)/imsU', $content, $tokens)) {
			return $tokens[1];
		}
		return false;
	}

	function getEnvironment($rootPath)
	{
		$controler = $rootPath . 'web/index.php';
		if (file_exists($controler)) {
			$result = $this->extractEnvironmentFromControler($controler);
		}
		if (empty($result)) {
			throw new Exception('Unable to find environment');
		}
		// if (!$result) {
		// $result = 'prod';
		// }
		return $result;
	}

	function getDatabaseInfos($rootPath)
	{
		require_once('SymfonyComponents/YAML/sfYaml.php');
		$yamlFile = $rootPath . 'config' . DIRECTORY_SEPARATOR . 'databases.yml';
		if (!file_exists($yamlFile)) {
			throw new Exception('Unable to find databases.yml in ' . $yamlFile);
		}
		$yml = sfYaml::load($yamlFile);
		$env = $this->getEnvironment($rootPath);

		foreach(array($env, 'all') as $envItem) {
			foreach(array('propel', 'doctrine') as $orm) {
				if (isset($yml[$envItem][$orm])) {
					if (preg_match('/^mysql:.*dbname=([a-z0-9._-]+)/i', $yml[$envItem][$orm]['param']['dsn'], $tokens)) {
						$databaseName = $tokens[1];
						$result[] = $databaseName;
						$result[] = $yml[$envItem][$orm]['param']['username'];
						$result[] = $yml[$envItem][$orm]['param']['password'];

						return $result;
					}
				}
			}
		}
		foreach(array($env, 'all') as $envItem) {
			if (preg_match('|^mysql://([a-z0-9._-]+):([a-z0-9._-]+)@localhost/([a-z0-9._-]+)$|i', $yml[$envItem]['propel']['param']['dsn'], $tokens)) {
				$result[] = $tokens[3];
				$result[] = $tokens[1];
				$result[] = $tokens[2];

				return $result;
			}
		}
		throw new Exception('Unable to find database name in ' . $yamlFile);
	}
}
<?php

class MyBackupEngine extends BackupEngine {
	const RULE_SYMFONY = 'symfony';

	function __construct($backupFolder)
	{
		parent::__construct($backupFolder);
		$this->addRule(self::RULE_SYMFONY, 'SymfonyBackupProcessor');
	}

	function getRuleForFolder($path)
	{
		if (file_exists($path . 'symfony')) {
			return self::RULE_SYMFONY;
		}
		return parent::getRuleForFolder($path);
	}
}
<?php

try {
	require_once(dirname(__FILE__).'/lib/MyBackupEngine.php');

    $engine = new MyBackupEngine('/home/backups');
    $engine->excludeFolder('/home/sites/symfony');
    $engine->excludeFolder('/home/sites/kreactiv.fr/.svn');

    $engine->excludeDatabase('information_schema');
    $engine->excludeDatabase('mysql');
    $engine->excludeTable('dalta_extranet', 'orders');
    $engine->excludeTable('dalta_extranet', 'order_products');
    $engine->excludeTable('dalta_extranet', 'customers');

    $engine->processRootFolder('/home/sites', 2);
    $engine->backupDatabases('root', '', '/home/backups/databases');
}
catch(Exception $e) {
    var_dump($e->getMessage());
}
<?php

use Tester\Assert;
use Tester\TestCase;
use DanielJames\Database\Db;

require_once(__DIR__.'/bootstrap.php');

function test_databases() {
    $db = Db::createSqlite(':memory:');
    $version = $db->getCell('SELECT sqlite_version()');

    foreach(glob(__DIR__.'/sqlite/*.sqlite') as $path) {
        if (preg_match('~test-([0-9.]*)\.sqlite$~', $path, $match)) {
            if (version_compare($version, $match[1], '>=')) {
                test_database($path);
            }
        }
    }
}

function test_database($path) {
    $temp_db = new TempDb($path);
    $db = Db::createSqlite($temp_db->path);
    $tables = $db->getCol("SELECT tbl_name FROM sqlite_master WHERE type='table' AND tbl_name NOT LIKE 'sqlite_%'");

    foreach ($tables as $table_name) {
        if (preg_match('~^pk\d+$~', $table_name)) {
            $record = $db->findOne($table_name, 'a = ? AND b = ?', array('a', 'b'));
            Assert::same('c', $record->c);
            $record->b = 'a';
            $record->store();

            // TODO: Not entirely sure if sqlite will maintain order.
            $records = $db->find($table_name);
            Assert::same(2, count($records));
            Assert::same('a', $records[0]->a);
            Assert::same('a', $records[0]->b);
            Assert::same('d', $records[1]->a);
            Assert::same('e', $records[1]->b);
        }
    }
}

class TempDb {
    var $path;

    function __construct($path) {
        $this->path = tempnam(sys_get_temp_dir(), 'testdb-');
        copy($path, $this->path);
    }

    function __destruct() {
        unlink($this->path);
    }
}

test_databases();

<?php

use DanielJames\Database\Db;

require_once(__DIR__.'/../../vendor/autoload.php');

$db = Db::createSqlite(':memory:');
$version = $db->getCell('SELECT sqlite_version()');

$db_path = __DIR__."/test-{$version}.sqlite";
if (is_file($db_path)) { unlink($db_path); }
$db = Db::createSqlite($db_path);

$pk_tables = [];

$db->exec("CREATE TABLE pk1(a STRING, b STRING, c STRING, PRIMARY KEY(a,b))");
$pk_tables[] = 'pk1';

if (version_compare($version, '3.14', '>=')) {
    $db->exec("CREATE TABLE pk2(a STRING, b STRING, c STRING, PRIMARY KEY(a,b)) WITHOUT ROWID");
    $pk_tables[] = 'pk2';
}

foreach ($pk_tables as $table_name) {
    $db->exec("INSERT INTO {$table_name} VALUES('a', 'b', 'c')");
    $db->exec("INSERT INTO {$table_name} VALUES('d', 'e', 'f')");
}

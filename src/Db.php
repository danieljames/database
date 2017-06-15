<?php

namespace DanielJames\Database;

use PDO;
use RuntimeException;
use Exception;
use Iterator;

// This is an incredibly crude little library for database stuff that I
// threw together when I had issues with RedBean in another project.
// Slightly adapted to be used here. It's pretty rubbish, okay?

// Convenience front end, for when you're only using one database.
class Db {
    static $instance = null;

    static function setup($dsn, $username = null, $password = null) {
        self::$instance = self::create($dsn, $username, $password);
        return true;
    }

    static function create($dsn, $username = null, $password = null) {
        return new Db_Impl(new PDO($dsn, $username, $password));
    }

    static function createFromPdo($pdo) {
        return new Db_Impl($pdo);
    }

    static function initSqlite($path) {
        self::$instance = self::createSqlite($path);
        return true;
    }

    static function createSqlite($path) {
        return new Db_Impl(new PDO("sqlite:{$path}"));
    }

    static function transaction($callback) { return self::$instance->transaction($callback); }
    static function begin() { return self::$instance->begin(); }
    static function commit() { return self::$instance->commit(); }
    static function rollback() { return self::$instance->rollback(); }
    static function exec($sql, $query_args=array()) { return self::$instance->exec($sql, $query_args); }
    static function getAll($sql, $query_args=array()) { return self::$instance->getAll($sql, $query_args); }
    static function getCol($sql, $query_args=array()) { return self::$instance->getCol($sql, $query_args); }
    static function getCell($sql, $query_args=array()) { return self::$instance->getCell($sql, $query_args); }
    static function getRow($sql, $query_args=array()) { return self::$instance->getRow($sql, $query_args); }
    static function getIterator($sql, $query_args=array()) { return self::$instance->getIterator($sql, $query_args); }
    static function dispense($table_name) { return self::$instance->dispense($table_name); }
    static function load($table_name, $id) { return self::$instance->load($table_name, $id); }
    static function find($table_name, $query = '', $query_args = array()) { return self::$instance->find($table_name, $query, $query_args); }
    static function findAll($table_name, $order_limit = '') { return self::$instance->findAll($table_name, $order_limit); }
    static function findOne($table_name, $query = '', $query_args = array()) { return self::$instance->findOne($table_name, $query, $query_args); }
    static function findIterator($table_name, $query = '', $query_args = array()) { return self::$instance->findIterator($table_name, $query, $query_args); }
    static function convertToBeans($table_name, $objects) { return self::$instance->convertToBeans($table_name, $objects); }
    static function store($object) { return $object->store(); }
    static function trash($object) { return $object->trash(); }
}

// A database entity.
// Fields are dynamically added.
class Db_Entity {
    var $__meta;

    function store() {
        return $this->__meta->connection->store($this);
    }

    function trash() {
        return $this->__meta->connection->trash($this);
    }

    function __set($name, $value) {
        if ($this->__meta) {
            throw new RuntimeException("Unknown column: {$name}");
        } else {
            $this->$name = $value;
        }
    }

    function __get($name) {
        throw new RuntimeException("Unknown column: {$name}");
    }
}

// A little bit of extra data about the entity.
class Db_EntityMetaData {
    var $connection;
    var $table_name;
    var $is_new;

    function __construct($connection, $table_name, $is_new) {
        $this->connection = $connection;
        $this->table_name = $table_name;
        $this->is_new = $is_new;
    }
}

// Used for automatically filled in fields.
// TODO: Could do a lot better by tracking when a field is set, or perhaps
//       even by not setting such fields in a new entity.
class Db_Default {
    static $instance;
}
Db_Default::$instance = new Db_Default();

// All the work is done here.
class Db_Impl {
    static $entity_object = 'DanielJames\\Database\\Db_Entity';
    var $pdo_connection;

    public function __construct($pdo) {
        $this->pdo_connection = $pdo;
        $this->pdo_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo_connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function getDriverName() {
        return $this->pdo_connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function transaction($callback) {
        static $depth = 0;
        if ($depth == 0) { $this->begin(); }
        ++$depth;
        try { $result = call_user_func($callback); }
        catch(Exception $e) {
            --$depth;
            if ($depth == 0) { $this->rollback(); }
            throw $e;
        }
        --$depth;
        if ($depth == 0) { $this->commit(); }
        return $result;
    }

    public function begin() {
        return $this->pdo_connection->beginTransaction();
    }

    public function commit() {
        return $this->pdo_connection->commit();
    }

    public function rollback() {
        return $this->pdo_connection->rollback();
    }

    public function exec($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        return $statement && $statement->execute($query_args);
    }

    public function getAll($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        else {
            return false;
        }
    }

    public function getIterator($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            return new Db_SelectIterator($statement);
        }
        else {
            throw new RuntimeException("Error creating get iterator");
        }
    }

    public function getCol($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            $col = array();
            while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                $col[] = $row[0];
            }
            return $col;
        }
        else {
            return false;
        }
    }

    public function getCell($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            $row = $statement->fetch(PDO::FETCH_NUM);
            return $row !== false ? $row[0] : null;
        }
        else {
            return false;
        }
    }

    public function getRow($sql, $query_args = array()) {
        $statement = $this->pdo_connection->prepare($sql);
        if ($statement && $statement->execute($query_args)) {
            return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        else {
            return false;
        }
    }

    public function dispense($table_name) {
        switch($this->getDriverName()) {
        case 'sqlite':
            $sql = "PRAGMA table_info(`{$table_name}`)";
            $statement = $this->pdo_connection->prepare($sql);
            $success = $statement && $statement->execute(array());
            if (!$success) { return false; }

            $found = false;
            $object = new self::$entity_object();
            while($column = $statement->fetchObject()) {
                $found = true;
                $name = $column->name;
                $default = trim(strtolower($column->dflt_value));
                if ($default === '') { $default = 'null'; }
                switch($default[0]) {
                case 'n':
                    // Crude attempt at support autoincrementing columns.
                    if ($default === 'null' && $column->pk) {
                        $object->{$name} = Db_Default::$instance;
                    }
                    else {
                        $object->{$name} = null;
                    }
                    break;
                case '"':
                    if (preg_match('@^"(.*)"$@', $column->dflt_value, $matches)) {
                        $object->{$name} = str_replace('""', '"', $matches[1]);
                    }
                    else {
                        throw new RuntimeException("Invalid string default");
                    }
                    break;
                case "'":
                    if (preg_match('@^\'(.*)\'$@', $column->dflt_value, $matches)) {
                        $object->{$name} = str_replace("''", "'", $matches[1]);
                    }
                    else {
                        throw new RuntimeException("Invalid string default");
                    }
                    break;
                case '`':
                    if (preg_match('@^`(.*)`$@', $column->dflt_value, $matches)) {
                        $object->{$name} = str_replace('``', '`', $matches[1]);
                    }
                    else {
                        throw new RuntimeException("Invalid string default");
                    }
                    break;
                case '+': case '-':
                case '0': case '1': case '2': case '3': case '4':
                case '5': case '6': case '7': case '8': case '9':
                    $object->{$name} = $default;
                    break;
                case 'c': // current_date/current_time/current_timestamp
                case '(': // expression
                    $object->{$name} = Db_Default::$instance;
                    break;
                default:
                    Log::warning("Unrecognized default: {$default}");
                    break;
                }
            }
            if (!$found) { throw new RuntimeException("Error finding table: {$table_name}.\n"); }
            break;
        case 'mysql':
            $sql = "DESCRIBE `{$table_name}`";
            $statement = $this->pdo_connection->prepare($sql);
            $success = $statement && $statement->execute(array());
            if (!$success) { return false; }

            $object = new self::$entity_object();
            while($column = $statement->fetchObject()) {
                $name = $column->Field;
                if (preg_match('@\bauto_increment\b@', strtolower($column->Extra))) {
                    $object->{$name} = Db_Default::$instance;
                }
                else if (strtolower($column->Default) == 'current_timestamp' &&
                    (strtolower($column->Type) === 'timestamp' || strtolower($column->Type) === 'datetime'))
                {
                    $object->{$name} = Db_Default::$instance;
                }
                else {
                    $object->{$name} = $column->Default;
                }
            }
            break;
        default:
            echo "Unrecognized database type";
        }
        $object->__meta = new Db_EntityMetaData($this, $table_name, true);

        return $object;
    }

    public function load($table_name, $id) {
        return $this->findOne($table_name, 'id = ?', array($id));
    }

    public function find($table_name, $query = '', array $query_args = array()) {
        $statement = $this->createFindStatement($table_name, $query, $query_args);
        if (!$statement) { return false; }

        $result = array();
        while($object = $this->_fetchBean($table_name, $statement)) {
            $result[] = $object;
        }

        return $result;
    }

    /* Redbean has a 'findAll' method which is identical to 'find'. I guess it's for
     * backwards compatibility. */
    public function findAll($table_name, $query = '', array $query_args = array()) {
        return $this->find($table_name, $query, $query_args);
    }

    public function findOne($table_name, $query = '', array $query_args = array()) {
        $statement = $this->createFindStatement($table_name, $query, $query_args);
        if (!$statement) { return false; }
        return $this->_fetchBean($table_name, $statement);
    }

    public function findIterator($table_name, $query = '', array $query_args = array()) {
        $statement = $this->createFindStatement($table_name, $query, $query_args);
        if (!$statement) {
            throw new RuntimeException("Error creating find iterator");
        }
        return new Db_Iterator($this, $table_name, $statement);
    }

    private function createFindStatement($table_name, $query, array $query_args) {
        $query = trim($query);
        $sql = "SELECT * FROM `{$table_name}`";
        if ($query) {
            if (preg_match('/^(where|join|order|limit)\b/i', $query)) {
                $sql .= $query;
            } else {
                $sql .= "WHERE {$query}";
            }
        }
        $statement = $this->pdo_connection->prepare($sql);
        $success = $statement && $statement->execute($query_args);
        return $success ? $statement : false;
    }

    // Public so that the iterator can use it...
    public function _fetchBean($table_name, $statement) {
        $object = $statement->fetchObject(self::$entity_object);
        if (!$object) { return null; }
        $object->__meta = new Db_EntityMetaData($this, $table_name, false);
        return $object;
    }

    public function convertToBeans($table_name, $objects) {
        $result = array();
        foreach($objects as $array) {
            if (!is_array($array)) {
                throw new RuntimeException("Not an array of arrays in convertToBeans.");
            }
            $object = new self::$entity_object();
            foreach($array as $key => $value) {
                $object->$key = $value;
            }
            $object->__meta = new Db_EntityMetaData(
                $this, $table_name, false);
            $result[] = $object;
        }
        return $result;
    }

    public function store($object) {
        $table_name = $object->__meta->table_name;
        $is_new = $object->__meta->is_new;

        $update = array();
        $default_columns = array();
        $id_name = null;
        $id = null;

        foreach(get_object_vars($object) as $key => $value) {
            switch(strtolower($key)) {
            case 'id':
                $id_name = $key;
                $id = $value;
                break;
            case '__meta':
                break;
            default:
                if ($value instanceof Db_Default) {
                    $default_columns[] = "`{$key}`";
                } else if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
                    $value = clone $value;
                    $value->setTimezone(new \DateTimeZone('UTC'));
                    $update[$key] = $value->format('Y-m-d H:i:s');
                } else {
                    $update[$key] = $value;
                }
                break;
            }
        }

        if (is_null($id_name) || !$id) { throw new RuntimeException("No id."); }

        if ($is_new) {
            if (!$id instanceof Db_Default) {
                $update[$id_name] = $id;
            }

            $sql = "INSERT INTO `{$table_name}` ";
            if (!$update) {
                if ($this->getDriverName() == 'sqlite') {
                    $sql .= "DEFAULT VALUES";
                    $query_args = array();
                }
                else {
                    $sql .= "VALUES()";
                    $query_args = array();
                }
            }
            else {
                $sql .= '(`'.implode('`,`', array_keys($update)).'`) ';
                $sql .= 'VALUES('.str_repeat('?,', count($update) - 1).'?)';
                $query_args = array_values($update);
            }

            $statement = $this->pdo_connection->prepare($sql);
            $success = $statement && $statement->execute($query_args);
            if (!$success) { return false; }
            $object->id = $this->pdo_connection->lastInsertId();
            $object->__meta->is_new = false;

            if ($default_columns) {
                $new_values = $this->getRow('SELECT '.implode(',', $default_columns).
                    " FROM `{$table_name}` WHERE id = ?", array($object->id));
                if (!$new_values) { return false; }
                foreach($new_values as $key => $value) { $object->$key = $value; }
            }

            return true;
        } else {
            // TODO: What if id has been updated?
            if ($default_columns) { throw new RuntimeException("Default in update object.\n"); }

            $sql = "UPDATE `{$table_name}` SET ";
            $sql .= implode(',', array_map(function($name) { return "`{$name}` = ?"; }, array_keys($update)));
            $sql .= " WHERE {$id_name} = ?";
            $query_args = array_values($update);
            $query_args[] = $id;

            $statement = $this->pdo_connection->prepare($sql);
            return $statement && $statement->execute($query_args);
        }
    }

    public function trash($object) {
        $id = $object->id;
        $table_name = $object->__meta->table_name;
        if (!$id) {
            throw new RuntimeException("No id.");
        }
        $sql = "DELETE FROM `{$table_name}` WHERE id = ?";
        $query_args = array($id);

        $statement = $this->pdo_connection->prepare($sql);
        return $statement && $statement->execute($query_args);
    }
}

class Db_Iterator implements Iterator {
    var $db;
    var $table_name;
    var $statement;
    var $index = 0;
    var $current;

    function __construct($db, $table_name, $statement) {
        $this->db = $db;
        $this->table_name = $table_name;
        $this->statement = $statement;
        $this->fetchObject();
    }

    function current() {
        if ($this->current === null) {
            throw new RuntimeException("current() called past end of database iterator.");
        }
        return $this->current;
    }

    function key() {
        return $this->index;
    }

    function next() {
        $this->fetchObject();
        ++$this->index;
    }

    function rewind() {
        if ($this->index) {
            throw new RuntimeException("Db_Iterator doesn't support rewind.");
        }
    }

    function valid() {
        return $this->current !== null;
    }

    private function fetchObject() {
        $this->current = $this->db->_fetchBean($this->table_name, $this->statement);
        if (!$this->current) {
            $this->db = null;
            $this->statement = null;
        }
    }
}

class Db_SelectIterator implements Iterator {
    var $statement;
    var $index = 0;
    var $current;

    function __construct($statement) {
        $this->statement = $statement;
        $this->fetchRow();
    }

    function current() {
        if ($this->current === null) {
            throw new RuntimeException("current() called past end of database iterator.");
        }
        return $this->current;
    }

    function key() {
        return $this->index;
    }

    function next() {
        $this->fetchRow();
        ++$this->index;
    }

    function rewind() {
        if ($this->index) {
            throw new RuntimeException("Db_Iterator doesn't support rewind.");
        }
    }

    function valid() {
        return $this->current !== null;
    }

    private function fetchRow() {
        $this->current = $this->statement->fetch(PDO::FETCH_ASSOC);
        if (!$this->current) {
            $this->current = null;
            $this->db = null;
            $this->statement = null;
        }
    }
}

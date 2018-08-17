<?php

/**
 * Schema.php
 *
 * This file is a part of tccl/database.
 */

namespace TCCL\Database;

use Iterator;
use Countable;
use ArrayAccess;
use Exception;

/**
 * Schema
 *
 * Provides an abstraction layer for defining and installing schemas.
 */
class Schema implements ArrayAccess, Iterator, Countable {
    const FILTER_FUNC = '\TCCL\Database\Schema::filterName';

    /**
     * Generic database type constants
     */
    const SERIAL_TYPE = 'serial';
    const INTEGER_TYPE = 'int';
    const FLOAT_TYPE = 'float';
    const NUMERIC_TYPE = 'numeric';
    const CHAR_TYPE = 'char';
    const VARCHAR_TYPE = 'varchar';
    const TEXT_TYPE = 'text';
    const BLOB_TYPE = 'blob';

    /**
     * Numeric data type size constants
     */
    const SIZE_TINY = 'tiny';
    const SIZE_SMALL = 'small';
    const SIZE_MEDIUM = 'medium';
    const SIZE_NORMAL = 'normal';
    const SIZE_BIG = 'big';

    /**
     * Stores the schema contents.
     *
     * @var array
     */
    private $schema = [];

    /**
     * The table name prefix.
     *
     * @var string
     */
    private $prefix;

    /**
     * Filters a name for use in a query.
     *
     * @param string $name
     *  The name to filter.
     *
     * @return string
     *  
     */
    public static function filterName($name) {
        return "`$name`";
    }

    private static function getTypeMap() {
        static $map = [
            'serial:tiny' => 'TINYINT',
            'serial:small' => 'SMALLINT',
            'serial:medium' => 'MEDIUMINT',
            'serial:normal' => 'INT',
            'serial:big' => 'BIGINT',

            'int:tiny' => 'TINYINT',
            'int:small' => 'SMALLINT',
            'int:medium' => 'MEDIUMINT',
            'int:normal' => 'INT',
            'int:big' => 'BIGINT',

            'float:tiny' => 'FLOAT',
            'float:small' => 'FLOAT',
            'float:medium' => 'FLOAT',
            'float:normal' => 'FLOAT',
            'float:big' => 'DOUBLE',

            'numeric:normal' => 'DECIMAL',

            'char:normal' => 'CHAR',

            'varchar:normal' => 'VARCHAR',

            'text:tiny' => 'TINYTEXT',
            'text:small' => 'TINYTEXT',
            'text:medium' => 'MEDIUMTEXT',
            'text:normal' => 'TEXT',
            'text:big' => 'LONGTEXT',

            'blob:normal' => 'BLOB',
            'blob:big' => 'LONGBLOB',
        ];

        return $map;
    }

    /**
     * Creates a new Schema instance.
     *
     * @param string $prefix
     *  An optional table name prefix.
     */
    public function __construct($prefix = '') {
        $this->prefix = $prefix;
    }

    /**
     * Implements ArrayAccess::offsetExists().
     */
    public function offsetExists($offset) {
        return isset($this->schema[$offset]);
    }

    /**
     * Implements ArrayAccess::offsetGet().
     */
    public function &offsetGet($offset) {
        return $this->schema[$offset];
    }

    /**
     * Implements ArrayAccess::offsetSet().
     */
    public function offsetSet($offset,$value) {
        $this->schema[$offset] = $value;
    }

    /**
     * Implements ArrayAccess::offsetUnset().
     */
    public function offsetUnset($offset) {
        unset($this->schema[$offset]);
    }

    /**
     * Implements Iterator::current().
     */
    public function current() {
        return current($this->schema);
    }

    /**
     * Implements Iterator::key().
     */
    public function key() {
        return key($this->schema);
    }

    /**
     * Implements Iterator::next().
     */
    public function next() {
        next($this->schema);
    }

    /**
     * Implements Iterator::rewind().
     */
    public function rewind() {
        reset($this->schema);
    }

    /**
     * Implements Iterator::valid().
     */
    public function valid() {
        return is_null(key($this->schema));
    }

    /**
     * Implements Countable::count().
     */
    public function count() {
        return count($this->schema);
    }

    /**
     * Generates the table SQL.
     */
    public function execute(DatabaseConnection $conn) {
        foreach ($this->schema as $name => $table) {
            $sql = $this->generateTable($name,$table);

            $conn->rawQuery($sql);
        }
    }

    /**
     * Implements __toString() magic method.
     */
    public function __toString() {
        try {
            $tables = [];
            foreach ($this->schema as $table => $info) {
                $tables[] = $this->generateTable($table,$info);
            }
            return implode(PHP_EOL . PHP_EOL,$tables);
        } catch (Exception $ex) {
            return "ERROR! " . $ex->getMessage();
        }
    }

    private function generateTable($name,$table) {
        $name = self::filterName("{$this->prefix}$name");
        $sql = "CREATE TABLE $name ( ";

        $fields = [];
        $constraints = [];
        if (isset($table['primary keys']) && !empty($table['primary keys'])) {
            $keyfields = [];
            foreach ($table['primary keys'] as $field => $fieldInfo) {
                $fields[] = $this->generateField($field,$fieldInfo);
                $keyfields[] = self::filterName($field);
            }
            $keyfields = implode(',',$keyfields);
            $constraints[] = "PRIMARY KEY ($keyfields)";
        }
        if (isset($table['fields'])) {
            foreach ($table['fields'] as $field => $fieldInfo) {
                $fields[] = $this->generateField($field,$fieldInfo);
            }
        }
        if (isset($table['unique keys'])) {
            foreach ($table['unique keys'] as $keyname => $allfields) {
                $keyname = self::filterName($keyName);
                $allfields = implode(',',array_map(self::FILTER_FUNC,$allfields));
                $constraints[] = "UNIQUE KEY $keyname ($allfields)";
            }
        }
        if (isset($table['foreign keys'])) {
            foreach ($table['foreign keys'] as $field => $foreignTable) {
                $foreignTableName = $this->prefix . $foreignTable['table'];
                $foreignField = $foreignTable['field'];
                $fields[] = $this->generateField($field,$foreignTable['key']);
                list($field,$foreignTableName,$foreignField)
                    = array_map(self::FILTER_FUNC,[$field,$foreignTableName,$foreignField]);
                $constraints[] = "FOREIGN KEY ($field) REFERENCES $foreignTableName ($foreignField)";
            }
        }
        if (isset($table['indexes'])) {
            foreach ($table['indexes'] as $indexname => $allfields) {
                $allfields = implode(',',array_map(self::FILTER_FUNC,$allfields));
                $indexname = self::filterName($indexname);
                $constraints[] = "INDEX $indexname ($allfields)";
            }
        }

        $sql .= implode(',',array_merge($fields,$constraints));
        $sql .= ' );';

        return $sql;
    }

    private function generateField($name,$info) {
        $TYPE_MAP = self::getTypeMap();

        if (!isset($info['type'])) {
            throw new Exception("Field '$name' must specify type");
        }
        $type = $info['type'];

        if (!isset($info['size'])) {
            $size = self::SIZE_NORMAL;
        }
        else {
            $size = $info['size'];
        }

        $sizeType = "$type:$size";
        if (isset($TYPE_MAP[$sizeType])) {
            $mysqlType = $TYPE_MAP[$sizeType];

            if ($type == self::NUMERIC_TYPE) {
                if (!isset($info['precision']) || !isset($info['scale'])) {
                    $mysqlType = false;
                }
                else {
                    $mysqlType = "$mysqlType({$info['precision']},{$info['scale']})";
                }
            }
            else if ($type == self::CHAR_TYPE || $type == self::VARCHAR_TYPE) {
                if (!isset($info['length'])) {
                    $mysqlType = false;
                }
                else {
                    $mysqlType = "$mysqlType({$info['length']})";
                }
            }
        }
        else {
            // Assume type is native mysql type name.
            $mysqlType = $type;
        }

        if ($mysqlType === false) {
            throw new Exception("Field '$name' has bad type");
        }

        $modifiers = [];
        if (isset($info['not null']) && $info['not null']) {
            $modifiers[] = 'NOT NULL';
        }
        if (isset($info['default'])) {
            $def = $info['default'];
            if (!is_int($def)) {
                $def = "'" . str_replace("'","\\'",$def) . "'";
            }
            $modifiers[] = "DEFAULT $def";
        }
        if (isset($info['type']) && $info['type'] == self::SERIAL_TYPE) {
            $modifiers[] = 'AUTO_INCREMENT';
        }
        $modifiers = implode(' ',$modifiers);
        $name = self::filterName($name);
        $sql = "$name $mysqlType $modifiers";

        return trim($sql);
    }
}

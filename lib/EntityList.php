<?php

/**
 * EntityList.php
 *
 * This file is a part of tccl/database.
 */

namespace TCCL\Database;

use PDO;
use Exception;
use ReflectionClass;

/**
 * EntityList
 *
 * Represents a list of entities that are manipulated together.
 */
abstract class EntityList {
    private $__info = [
        'conn' => null,
        'table' => '',
        'key' => '',
        'fields' => [],
        'fieldMaps' => [],
        'filters' => [],

        'list' => false,
        'changes' => [
            'new' => [],
            'update' => [],
            'delete' => [],
        ],
    ];

    /**
     * Creates a new EntityList instance. Optional parameters can be read from
     * doc comments if not provided directly.
     *
     * @param DatabaseConnection $conn
     *  The database connection instance to use.
     * @param string $table
     *  The name of the table that stores the entities.
     * @param string $key
     *  The field name that uniquely identifies each entity.
     * @param array $fields
     *  Array of field metadata. This list should not contain an entry for the
     *  key field. Each entry should contain the following keys:
     *   - field: name of field
     *   - alias: optional alias for field
     *   - map: optional PHP string callable to apply when reading value
     * @param array $filters
     *  Array of SQL fragments used to filter the list.
     */
    public function __construct(DatabaseConnection $conn,
                                $table = '',
                                $key = '',
                                $fields = [],
                                $filters = [])
    {
        $rf = new ReflectionClass(get_class($this));
        $commentTags = self::parseDocTags($rf->getDocComment());

        $this->__info['conn'] = $conn;

        // Apply table.
        if (!empty($table)) {
            $this->__info['table'] = $table;
        }
        else if (isset($commentTags['table'])) {
            if (!is_scalar($commentTags['table'])) {
                throw new Exception('EntityList: @table must be a scalar value');
            }

            $this->__info['table'] = $commentTags['table'];
        }
        else {
            throw new Exception('EntityList: @table is undefined');
        }

        // Apply key.
        if (!empty($key)) {
            $this->__info['key'] = $key;
        }
        else if (isset($commentTags['key'])) {
            if (!is_scalar($commentTags['key'])) {
                throw new Exception('EntityList: @key must be a scalar value');
            }

            $this->__info['key'] = $commentTags['key'];
        }
        else {
            throw new Exception('EntityList: @key is undefined');
        }

        // Apply fields.
        if (!is_array($fields) || (isset($commentTags['field']) && !is_array($commentTags['field']))) {
            throw new Exception('EntityList: @field must be an array');
        }
        foreach ($fields as $info) {
            if (!isset($info['name'])) {
                throw new Exception("EntityList: field entry missing 'name' property");
            }
            $this->__info['fields'][$info['name']]
                = isset($info['alias']) && !empty($info['alias']) ? $info['alias'] : false;
            $this->__info['fieldMaps'][$info['name']] = isset($info['map']) ? $info['map'] : null;
        }
        if (isset($commentTags['field'])) {
            foreach ($commentTags['field'] as $fld) {
                @list($name,$alias,$map) = explode(':',$fld,3);
                $this->__info['fields'][$name] = !empty($alias) ? $alias : false;
                $this->__info['fieldMaps'][$name] = $map;
            }
        }

        // Apply filters.
        if (!is_array($filters) || (isset($commentTags['filter']) && !is_array($commentTags['filter']))) {
            throw new Exception('EntityList: @filter must be an array');
        }
        $this->__info['filters'] = array_merge($this->__info['filters'],$filters);
        if (isset($commentTags['filter'])) {
            $this->__info['filters'] = array_merge($this->__info['filters'],$commentTags['filter']);
        }
    }

    /**
     * Obtains an ordered list of all items in the set.
     *
     * @return array
     *  Returns an indexed array of entity payload arrays. Each inner array will
     *  have entries for each field, plus one for the key field.
     */
    public function getItems($forceReload = false) {
        if ($forceReload) {
            $list = $this->queryList();
        }
        else {
            if ($this->__info['list'] === false) {
                $this->__info['list'] = $this->queryList(true);
            }
            $list = array_values($this->__info['list']);
        }

        return $list;
    }

    /**
     * Obtains a list of all items in the set keyed by key field.
     *
     * @return array
     *  Returns an associative array of entity payload arrays. Each inner array
     *  will have entries for each field, plus one for the key field.
     */
    public function getItemsWithKeys() {
        if ($forceReload) {
            $list = $this->queryList(true);
        }
        else {
            if ($this->__info['list'] === false) {
                $this->__info['list'] = $this->queryList(true);
            }
            $list = $this->__info['list'];
        }

        return $list;
    }

    public function setItem(array $payload) {

    }

    public function setItemWithKey($key,array $payload) {

    }

    public function deleteItem($key) {

    }

    /**
     * Commits entity list changes to the database.
     */
    public function commit() {

    }

    private function queryList($assoc = false) {
        $query = 'SELECT ' . $this->makeFieldList(true,true)
               . " FROM {$this->__info['table']} ";

        if (!empty($this->__info['filters'])) {
            $filters = array_map(function($filter) {
                return "($filter)";

            }, $this->__info['filters']);

            $filterString = implode(' AND ',$filters);
            $query .= "WHERE $filterString";
        }

        $stmt = $this->__info['conn']->query($query);

        $rows = [];
        while (true) {
            $next = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($next === false) {
                break;
            }

            $this->processRow($next);
            if ($assoc) {
                $rows[$next[$this->__info['key']]] = $next;
            }
            else {
                $rows[] = $next;
            }
        }

        return $rows;
    }

    private function makeFieldList($useAliases = false,$includeKey = false) {
        $fields = array_keys($this->__info['fields']);
        if ($includeKey) {
            array_unshift($fields,$this->__info['key']);
        }

        $fields = array_map(function($fld) use($useAliases) {
            $result = "`{$this->__info['table']}`.`$fld`";
            if ($useAliases && !empty($this->__info['fields'][$fld])) {
                $result .= " AS `{$this->__info['fields'][$fld]}`";
            }

            return $result;

        }, $fields);

        return implode(', ',$fields);
    }

    private function processRow(array &$row) {
        if (is_numeric($row[$this->__info['key']])) {
            $row[$this->__info['key']] = (int)$row[$this->__info['key']];
        }

        foreach ($row as $key => &$value) {
            if (isset($this->__info['fieldMaps'][$key])
                && !empty($this->__info['fieldMaps'][$key]))
            {
                $value = $this->__info['fieldMaps'][$key]($value);
            }
        }
    }

    private static function parseDocTags($block) {
        $results = [];

        if ($block && preg_match_all('/@.*$/m',$block,$matches,PREG_PATTERN_ORDER)) {
            foreach ($matches[0] as $match) {
                $parts = explode(' ',$match,2);
                if (!isset($parts[1])) {
                    $parts[1] = null;
                }

                $value = trim($parts[1]);

                if (preg_match('/\[\]$/',$parts[0])) {
                    $key = trim(substr($parts[0],1,strlen($parts[0])-3));
                    $results[$key][] = $value;
                }
                else {
                    $key = trim(substr($parts[0],1));
                    $results[$key] = $value;
                }

            }
        }

        return $results;
    }
}

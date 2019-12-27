<?php

/**
 * EntityList.php
 *
 * tccl/database
 */

namespace TCCL\Database;

use PDO;
use stdClass;
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
        'filterVars' => [],

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
     * @param array $filterVars
     *  Array of variables to bind the filter statement(s).
     */
    public function __construct(DatabaseConnection $conn,
                                $table = '',
                                $key = '',
                                $fields = [],
                                $filters = [],
                                $filterVars = [])
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
            $fieldName = isset($info['alias']) && !empty($info['alias']) ? $info['alias'] : $info['name'];

            $this->__info['fields'][$info['name']]
                = isset($info['alias']) && !empty($info['alias']) ? $info['alias'] : false;

            $this->__info['fieldMaps'][$fieldName] = isset($info['map']) ? $info['map'] : null;
        }
        if (isset($commentTags['field'])) {
            foreach ($commentTags['field'] as $fld) {
                @list($name,$alias,$map) = explode(':',$fld,3);
                $fieldName = !empty($alias) ? $alias : $name;

                $this->__info['fields'][$name] = !empty($alias) ? $alias : false;
                $this->__info['fieldMaps'][$fieldName] = $map;
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

        $this->__info['filterVars'] = $filterVars;
    }

    /**
     * Obtains an ordered list of all items in the set.
     *
     * @param bool $forceReload
     *  Forces the list to be reloaded from the database (default off).
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
     * @param bool $forceReload
     *  Forces the list to be reloaded from the database (default off).
     *
     * @return array
     *  Returns an associative array of entity payload arrays. Each inner array
     *  will have entries for each field, plus one for the key field.
     */
    public function getItemsWithKeys($forceReload = false) {
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

    /**
     * Sets the initial list as if it were queried from the database.
     *
     * @param array $rows
     *  Result rows to set; they should have the same format expected from a
     *  database query.
     */
    public function setInitialList(array $rows) {
        array_walk($rows,[$this,'processRow']);
        $keys = array_column($rows,$this->__info['key']);
        $this->__info['list'] = array_combine($keys,$rows);
    }

    /**
     * Applies a complete list to the EntityList. The EntityList will make the
     * minimal amount of changes required to apply the list. Changes are
     * detected along the key.
     *
     * @param array $items
     *  The list of items to apply.
     */
    public function applyList(array $items) {
        $tracking = [];

        $key = $this->__info['key'];
        $curlist = $this->getItemsWithKeys();

        // Ignore any other changes that were previously applied.
        $this->resetChanges();

        // Calculate array diff to determine set of new and update.
        $map = [];
        foreach ($items as $item) {
            if (!isset($item[$key]) || !isset($curlist[$item[$key]])) {
                $tracking[] = $this->addItem($item);
            }
            else if ($this->comparePayload($item,$curlist[$item[$key]]) != 0) {
                $tracking[] = $this->updateItem($item[$key],$item);
            }

            if (isset($item[$key])) {
                $map[$item[$key]] = $item;
            }
        }

        // Calculate reverse array diff to determine set of delete.
        foreach ($curlist as $kv => $item) {
            if (!isset($map[$kv])) {
                $this->deleteItem($kv);
            }
        }

        return $tracking;
    }

    /**
     * Adds a new item to the list. The item is automatically assigned a key via
     * the database system (if defined); otherwise the key must be provided in
     * the field payload.
     *
     * @param array $payload
     *  The field payload for the item. You must specify all non-default fields.
     *
     * @return stdClass
     *  Returns a tracking object that will be assigned the object's key if it
     *  is auto-assigned.
     */
    public function addItem(array $payload) {
        $tracking = (object)$payload;
        if (!array_key_exists($this->__info['key'],$payload)) {
            $tracking->{$this->__info['key']} = null;
        }

        $this->__info['changes']['new'][] = $tracking;

        return $tracking;
    }

    /**
     * Adds a new item to the list with a key.
     *
     * @param mixed $key
     *  The key to use for the new entry.
     * @param array $payload
     *  Field payload for the new entry; this must specify all non-default
     *  fields.
     * @param bool $override
     *  If true, then the operation determines if the entry should be inserted
     *  or updated. Otherwise the operation just assumes the entry is to be
     *  inserted.
     *
     * @return stdClass
     *  Returns a tracking object.
     */
    public function addItemWithKey($key,array $payload,$override = false) {
        $tracking = (object)$payload;
        $tracking->{$this->__info['key']} = $key;

        if ($override) {
            $list = $this->getItemsWithKeys();

            if (isset($list[$key])) {
                $this->__info['changes']['update'][$key] = $tracking;
            }
            else {
                $this->__info['changes']['new'][] = $tracking;
            }
        }
        else {
            $this->__info['changes']['new'][] = $tracking;
        }

        return $tracking;
    }

    /**
     * Updates an existing item in the list. This assumes the item actually
     * exists.
     *
     * @param mixed $key
     *  The key that identifies the item to update.
     * @param array $payload
     *  List of fields to update; this can be a subset of the total fields.
     *
     * @return stdClass
     *  Returns a tracking object.
     */
    public function updateItem($key,array $payload) {
        $tracking = (object)$payload;
        $tracking->{$this->__info['key']} = $key;

        $this->__info['changes']['update'][$key] = $tracking;

        return $tracking;
    }

    /**
     * Removes an item from the list.
     *
     * @param mixed $key
     *  The key that identifies the item.
     */
    public function deleteItem($key) {
        $this->__info['changes']['delete'][$key] = true;
    }

    /**
     * Commits entity list changes to the database.
     */
    public function commit() {
        extract($this->__info);

        // Perform everything under a common transaction.
        $conn->beginTransaction();

        // Create filter fragment. We'll use this for the UPDATE and DELETE
        // queries so that only filtered entities are touched for those
        // operations.
        $filters = $this->makeFilterString();
        if (!empty($filters)) {
            $filters = "AND $filters";
        }

        // Insert new entities into the database system. Perform each insert
        // using a single-insert prepared statement; this allows us to reliably
        // obtain any auto IDs.
        if (count($changes['new']) > 0) {
            $fieldList = $this->makeFieldList(false,true);
            $preps = '?' . str_repeat(',?',count($fields));
            $query = "INSERT INTO `$table` ($fieldList) VALUES ($preps)";
            $stmt = $conn->prepare($query);

            foreach ($changes['new'] as $entry) {
                // NOTE: The key is always ordered first so we add it here. We
                // leave it NULL if unspecified; the database system may use an
                // auto ID when we provide NULL.
                if (isset($entry->$key)) {
                    $stmt->bindValue(1,$entry->$key);
                }
                else {
                    $stmt->bindValue(1,null);
                }

                $n = 2;
                foreach ($fields as $fld => $alias) {
                    if (isset($entry->$fld)) {
                        $stmt->bindValue($n,$entry->$fld);
                    }
                    else if ($alias !== false && isset($entry->$alias)) {
                        $stmt->bindValue($n,$entry->$alias);
                    }
                    else {
                        // Assume default value or NULL provided.
                        $stmt->bindValue($n,null);
                    }

                    $n += 1;
                }

                if ($stmt->execute() === false) {
                    throw new DatabaseException($stmt);
                }

                // Assume a last insert ID is the key value for the new entry.
                $lastId = $conn->lastInsertId();
                if (!empty($lastId)) {
                    if (is_numeric($lastId)) {
                        $entry->$key = (int)$lastId;
                    }
                    else {
                        $entry->$key = $lastId;
                    }
                }
            }
        }

        // Update entities that already exist in the database system.
        if (count($changes['update']) > 0) {
            $sets = [];
            $vars = [];
            $keys = [];

            $usedFields = [];

            $keyNo = 1;
            foreach ($changes['update'] as $itemKey => $item) {
                $keyParam = "key$keyNo";
                $keyNo += 1;

                $vars[$keyParam] = $itemKey;
                $keys[] = ":$keyParam";

                foreach ($fields as $fld => $alias) {
                    if (property_exists($item,$fld)) {
                        $value = ":$keyParam$fld";
                        $vars["$keyParam$fld"] = $item->$fld;

                        $usedFields[$fld] = true;
                    }
                    else if ($alias !== false && property_exists($item,$alias)) {
                        $value = ":$keyParam$fld";
                        $vars["$keyParam$fld"] = $item->$alias;

                        $usedFields[$fld] = true;
                    }
                    else {
                        $value = "`$fld`";
                    }

                    $sets[$fld][] = "WHEN `$key` = :$keyParam THEN $value";
                }
            }

            // Eliminate fields that are never updated across all items.
            foreach (array_keys($sets) as $fld) {
                if (!isset($usedFields[$fld])) {
                    unset($sets[$fld]);
                }
            }

            $sets = array_map(function($whens,$fld) {
                $whens = implode(' ',$whens);
                return "`$fld` = CASE $whens ELSE `$fld` END";

            }, array_values($sets), array_keys($sets));

            $sets = implode(',',$sets);
            $preps = implode(',',$keys);
            $query = "UPDATE `$table` SET $sets WHERE `$key` IN ($preps) $filters";

            $vars = array_merge($vars,$this->__info['filterVars']);
            $conn->query($query,$vars);
        }

        // Delete entities.
        if (count($changes['delete']) > 0) {
            // Invoke delete hook.
            $this->deleteHook($changes['delete'],$conn,$table);

            // Delete entities from RDBMS.
            if (count($changes['delete']) > 0) {
                $keys = array_keys($changes['delete']);
                $preps = '?' . str_repeat(',?',count($keys)-1);
                $query = "DELETE FROM `$table` WHERE `$key` IN ($preps) $filters";
                $vars = array_merge($keys,$this->__info['filterVars']);
                $conn->query($query,$vars);
            }
        }

        $conn->commit();
        $this->resetChanges();
    }

    /**
     * Adds a filter to the object's internal list of filters.
     *
     * @param string $sql
     *  The SQL that makes up the filter. Note that all SQL filters are joined
     *  with AND clauses.
     */
    protected function addFilter($sql) {
        $this->__info['filters'][] = $sql;
    }

    /**
     * Sets the filter variables that are substituted for placeholders in the
     * filter clause (i.e. WHERE clause).
     *
     * @param array $vars
     *  Associative/indexed array of filter variables.
     */
    protected function setFilterVariables(array $vars) {
        $this->__info['filterVars'] = $vars;
    }

    /**
     * Adds a single filter variable to the set of filter variables.
     *
     * @param string $name
     * @param mixed $value
     *  The variable value needs to be convertable to string.
     */
    protected function addFilterVariable($name,$value) {
        $this->__info['filterVars'][$name] = $value;
    }

    /**
     * Hook called by commit() implementation that allows derived functionality
     * to change the set of items to delete or otherwise process delete items in
     * some way.
     *
     * @param array &$deleteSet
     *  The set of items to delete; this is an associative array having keys
     *  corresponding to each entity key in the set. Note that this list is
     *  always guarenteed to be non-empty.
     * @param DatabaseConnection $conn
     *  The database connection associated with the EntityList.
     * @param string $table
     *  The table that stores the list entities.
     */
    protected function deleteHook(array &$deleteSet,DatabaseConnection $conn,$table) {
        //  Default implementation does nothing.
    }

    private function queryList($assoc = false) {
        $query = 'SELECT ' . $this->makeFieldList(true,true)
               . " FROM {$this->__info['table']} ";

        $filterString = $this->makeFilterString();
        if (!empty($filterString)) {
            $query .= "WHERE $filterString";
        }

        $stmt = $this->__info['conn']->query($query,$this->__info['filterVars']);

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

    private function makeFilterString() {
        if (!empty($this->__info['filters'])) {
            $filters = array_map(function($filter) {
                return "($filter)";

            }, $this->__info['filters']);

            $filterString = implode(' AND ',$filters);
            return $filterString;
        }

        return '';
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

    private function resetChanges() {
        $this->__info['changes'] = [
            'new' => [],
            'update' => [],
            'delete' => [],
        ];
    }

    private function comparePayload(array $left,array $right) {
        // Determine if right differs from left.

        $n = 0;

        foreach ($this->__info['fields'] as $fld => $alias) {
            if (isset($left[$fld])) {
                $leftValue = $left[$fld];
            }
            else if ($alias !== false && isset($left[$alias])) {
                $leftValue = $left[$alias];
            }
            else {
                // Note difference if the right side has a field the left side
                // doesn't have.
                $n += isset($right[$fld]) || ($alias !== false && isset($right[$alias]));
            }

            if (isset($right[$fld])) {
                $rightValue = $right[$fld];
            }
            else if ($alias !== false && isset($right[$alias])) {
                $rightValue = $right[$alias];
            }
            else {
                continue;
            }

            $n += ($leftValue != $rightValue);
        }

        return $n;
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

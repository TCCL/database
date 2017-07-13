<?php

/**
 * Entity.php
 *
 * This file is a part of tccl/database.
 */

namespace TCCL\Database;

use PDO;
use Exception;

/**
 * Entity
 *
 * Represents an abstract entity object which serves as a convenience wrapper
 * against entities in a database. Each instance represents a single entity
 * item.
 *
 * The schema representing the entity must be simple: one table to lookup or
 * update the entity.
 */
abstract class Entity {
    /**
     * The database connection.
     *
     * @var DatabaseConnection
     */
    private $conn;

    /**
     * The table in the database that represents the entity type.
     *
     * @var string
     */
    private $table;

    /**
     * The key or keys involved in querying a specific entity. Each element maps
     * a key name to the expected value.
     *
     * @var array
     */
    private $keys;

    /**
     * The properties exposed by the model. The keys are the property names and
     * the values are the field names to which they correspond respectively.
     *
     * @var array
     */
    private $props;

    /**
     * An associative array mapping field names to their values in the model.
     *
     * @var array
     */
    private $fields;

    /**
     * An associative array whose keys represent the set of fields to update.
     *
     * @var array
     */
    private $updates;

    /**
     * The set of filters to apply (if any) to values fetched from the database.
     *
     * @var array
     */
    private $filters;

    /**
     * Determines whether the fields have been fetched. Typically this is only
     * done once in the object's lifetime unless it is invalidated.
     *
     * @var bool
     */
    private $fetchState = false;

    /**
     * Determines whether the entity is expected to be created or updated. This
     * effectively stores whether the entity exists or not after doing a
     * doFetch() operation.
     *
     * @var bool
     */
    private $create;

    /**
     * Overloads for special handlers.
     */

    public function __get($propertyName) {
        $this->doFetch();
        if (isset($this->props[$propertyName])) {
            return $this->fields[$this->props[$propertyName]];
        }

        trigger_error("Undefined entity property: $propertyName",E_USER_NOTICE);
    }

    public function __set($propertyName,$value) {
        if (isset($this->props[$propertyName])) {
            $fieldName = $this->props[$propertyName];
            $this->fields[$fieldName] = $value;
            $this->updates[$fieldName] = true;
            return;
        }

        trigger_error("Undefined entity property: $propertyName",E_USER_NOTICE); 
    }

    public function __isset($field) {
        // Make sure the property is registered and it's value is set.
        return isset($this->props[$field]) && isset($this->fields[$this->props[$field]]);
    }

    /**
     * Determines if the entity exists.
     *
     * @return bool
     */
    public function exists() {
        $this->doFetch();
        return !$this->create;
    }

    /**
     * Gets the complete set of fields representing the entity.
     *
     * @param bool $userPropertyNames
     *  If true then the keys in the array are the property names instead of the
     *  database table names.
     *
     * @return array
     *  An associative array mapping field names to field values.
     */
    public function getFields($usePropertyNames = true) {
        $this->doFetch();
        if ($usePropertyNames) {
            $result = [];
            foreach ($this->props as $propName => $fieldName) {
                $result[$propName] = $this->fields[$fieldName];
            }
            return $result;
        }

        return $this->fields;
    }

    /**
     * Gets the complete set of field names for the entity.
     *
     * @param bool $userPropertyNames
     *  If true then the keys in the array are the property names instead of the
     *  database table names.
     *
     * @return array
     *  An indexed array containing the field names.
     */
    public function getFieldNames($usePropertyNames = true) {
        if ($usePropertyNames) {
            return array_keys($this->props);
        }
        return array_values($this->props);
    }

    /**
     * Commit any pending changes to the database. This must be done explicitly.
     */
    public function commit() {
        if ($this->create) {
            $values = [];

            // Process any specified updates as field inserts.
            if (isset($this->updates)) {
                foreach ($this->updates as $key => &$value) {
                    $value = $this->fields[$key];
                    $values[] = $this->fields[$key];
                }
                unset($value);
            }

            // Add any non-null keys to the list of inserts. We'll assume null
            // keys are defaulted or auto-incremented in some way by the DB
            // engine.
            foreach ($this->keys as $key => $value) {
                if (!is_null($value)) {
                    $this->updates[$key] = true;
                    $values[] = $value;
                }
            }

            // Abort operation if no inserts are available.
            if (!isset($this->updates)) {
                return;
            }

            // Build the query.
            $fields = implode(',',array_keys($this->updates));
            $prep = '?' . str_repeat(',?',count($this->updates)-1);
            $query = "INSERT INTO $this->table ($fields) VALUES ($prep)";
            $this->fetchState = false;
        }
        else {
            // Abort operation if no updates are available.
            if (!isset($this->updates)) {
                return;
            }

            // Process the set of updates.
            $keyCondition = $this->getKeyString($values);
            foreach ($this->updates as $key => &$value) {
                $value = $this->fields[$key];
                array_unshift($values,$this->fields[$key]);
            }

            // Build the query.
            $fields = implode(',',array_map(function($x){ return "$x = ?"; },
                                            array_keys($this->updates)));
            $query = "UPDATE $this->table SET $fields WHERE $keyCondition LIMIT 1";
        }

        $this->conn->beginTransaction();
        $stmt = $this->conn->query($query,$values);
        if ($stmt->rowCount() < 1) {
            // Attempt to create the entity if we weren't already.
            if (!$this->create) {
                // Attempt to create the entity.
                $this->create = true;
                $this->commit();
                $this->conn->endTransaction();
                return;
            }

            throw new Exception(__METHOD__.': failed to commit entity');
        }

        $this->conn->endTransaction();
        unset($this->updates);
    }

    /**
     * Creates a new Entity instance. This must be called by derive classes in
     * order for the object to function properly.
     *
     * @param DatabaseConnection $conn
     *  The database connection object to use when making queries for the
     *  entity.
     * @param string $table
     *  The database table that represents the entity.
     * @param array $keys
     *  The keys that identify the specific entity. This is an associative array
     *  mapping key field name(s) to the expected value(s).
     * @param bool $create
     *  If true then the object will attempt to force commit the entity as a new
     *  entity. Otherwise the entity is only created if it was determined to not
     *  exist.
     */
    protected function __construct(DatabaseConnection $conn,$table,array $keys,$create = false) {
        $this->conn = $conn;
        $this->table = $table;
        $this->keys = $keys;

        // Force create if specified or if null appears as one of the key
        // values.
        if (in_array(null,array_values($keys))) {
            $this->create = true;
        }
        else {
            $this->create = $create;
        }
    }

    /**
     * Registers a field with the object. This should be called by the derived
     * class for the fields it wants to include as a part of its interface. Each
     * field is provided as a property of the object.
     *
     * @param string $field
     *  The field name corresponding to the database field name.
     * @param string $propertyName
     *  The name of the property. This may be different than the verbatim
     *  database table field name if specified. If empty string or null then the
     *  property name will be the same as the database field name.
     * @param mixed $default
     *  The default value to use for the property.
     * @param callable $filter
     *  If a callable, then the field is filtered through the callback when it
     *  is read. This does not effect the value when it is committed.
     */
    protected function registerField($field,$propertyName = null,$default = null,$filter = null) {
        if (empty($propertyName)) {
            $propertyName = $field;
        }
        if (property_exists($this,$propertyName)) {
            trigger_error("Cannot register field '$propertyName'.",E_USER_ERROR);
            return;
        }

        $this->fields[$field] = $default;
        $this->props[$propertyName] = $field;
        if (is_callable($filter)) {
            $this->filters[$field] = $filter;
        }
    }

    /**
     * Gets the string representing the WHERE key bind condition in the SQL
     * query. This is just a convenience wrapper.
     *
     * @param array &$values
     *  The list of values for the key expression.
     *
     * @return string
     */
    private function getKeyString(&$values) {
        $keys = array_keys($this->keys);
        $query = implode(' AND ',array_map(function($x){ return "$x = ?"; },$keys));
        $values = array_values($this->keys);
        return $query;
    }

    /**
     * Performs the fetch operation. This overwrites all field values currently
     * available.
     */
    private function doFetch() {
        if (!$this->fetchState) {
            $keyCondition = $this->getKeyString($values);
            $fields = implode(',',array_keys($this->fields));

            $query = "SELECT $fields FROM $this->table WHERE $keyCondition LIMIT 1";
            $stmt = $this->conn->query($query,$values);

            $newfields = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->fetchState = true;

            // If we didn't get any results, we can assume the entity doesn't
            // exist. In this case we'll want to enter create mode so that any
            // future commit won't attempt an UPDATE but skip to an INSERT.
            if (!is_array($newfields)) {
                $this->create = true;
            }
            else {
                // Apply filters to the fetched values.
                $this->fields = $newfields;
                foreach ($this->fields as $key => &$value) {
                    if (isset($this->filters[$key])) {
                        $value = $this->filters[$key]($value);
                    }
                }
                $this->create = false;
            }
        }
    }
}

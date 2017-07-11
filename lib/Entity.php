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
     * Determines whether the fields have been fetched. Typically this is only
     * done once in the object's lifetime unless it is invalidated.
     *
     * @var bool
     */
    private $fetchState = false;

    /**
     * Overloads for special handlers.
     */

    public function __destruct() {
        $this->doCommit();
    }

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
        return isset($this->props[$name]) && isset($this->fields[$this->props[$name]]);
    }

    protected function __construct(DatabaseConnection $conn,$table,array $keys) {
        $this->conn = $conn;
        $this->table = $table;
        $this->keys = $keys;
    }

    protected function registerField($field,$propertyName,$default = null) {
        if (property_exists($this,$propertyName)) {
            trigger_error("Cannot register field '$propertyName'.",E_USER_ERROR);
            return;
        }

        $this->fields[$field] = $default;
        $this->props[$propertyName] = $field;
    }

    private function getKeyString(&$values) {
        $keys = array_keys($this->keys);
        $query = implode(' AND ',array_map(function($x){ return "$x = ?"; },$keys));
        $values = array_values($this->keys);
        return $query;
    }

    private function doFetch() {
        if (!$this->fetchState) {
            $keyCondition = $this->getKeyString($values);
            $fields = implode(',',array_keys($this->fields));

            $query = "SELECT $fields FROM $this->table WHERE $keyCondition LIMIT 1";
            $stmt = $this->conn->query($query,$values);

            $this->fields = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->fetchState = true;
        }
    }

    private function doCommit() {
        if (isset($this->updates)) {
            $keyCondition = $this->getKeyString($values);
            foreach ($this->updates as $key => &$value) {
                $value = $this->fields[$key];
                array_unshift($values,$this->fields[$key]);
            }
            $fields = implode(',',array_map(function($x){ return "$x = ?"; },
                                            array_keys($this->updates)));

            $query = "UPDATE $this->table SET $fields WHERE $keyCondition LIMIT 1";
            $this->conn->query($query,$values);

            unset($this->updates);
        }
    }
}

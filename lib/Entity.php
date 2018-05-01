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
     * Determines whether the entity is expected to already exist. By default we
     * don't care and will attempt to UPDATE or INSERT the entity as needed.
     *
     * @var bool
     */
    private $updateOnly = false;

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
            $this->fetchState = true;
            return;
        }

        trigger_error("Undefined entity property: $propertyName",E_USER_NOTICE);
    }

    public function __isset($field) {
        // Make sure the property is registered and it's value is set.
        $this->doFetch();
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
     * Sets the update-only state of the Entity.
     *
     * @param bool $state
     *  The flag to set.
     */
    public function setUpdateOnly($state = true) {
        $this->updateOnly = $state;
    }

    /**
     * Gets the database connection instance.
     *
     * @return DatabaseConnection
     */
    public function getConnection() {
        return $this->conn;
    }

    /**
     * Gets the table name for the Entity.
     *
     * @return string
     */
    public function getTable() {
        return $this->table;
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
     * Sets the fields for the Entity.
     *
     * @param array $fields
     *  The associative array of fields for the entity. This is cross-referenced
     *  for keys meaning any keys that exist in $fields but weren't registered
     *  are ignored. Both table field names and aliases are allowed.
     * @param bool $synchronized
     *  If true, then the field values are assumed to already be synchronized
     *  with the database backend. Otherwise updates are queued for a later
     *  commit.
     */
    final public function setFields(array $fields,$synchronized = true) {
        // Always set the fetchState to true to avoid overwriting the fields.
        $this->fetchState = true;

        // If the caller indicated the entity is synchronized, change the create
        // flag to reflect this.
        if ($synchronized) {
            $this->create = false;
        }

        foreach ($fields as $key => $value) {
            // Set key value if found.
            if (array_key_exists($key,$this->keys)) {
                $this->keys[$key] = $value;
            }

            // See if the key is a property name. If so then map it to its
            // corresponding field name.
            if (isset($this->props[$key])) {
                $key = $this->props[$key];
            }

            // Set field value if found.
            if (array_key_exists($key,$this->fields)) {
                // See if we can first filter the value. Only filter non-null
                // values.
                if (isset($this->filters[$key],$value)) {
                    $value = $this->filters[$key]($value);
                }

                $this->fields[$key] = $value;
                if (!$synchronized) {
                    $this->updates[$key] = true;
                }
            }
        }
    }

    /**
     * Gets the list of inserts for the object. Such a list only exists if the
     * object is in create mode.
     *
     * @param array &$values
     *  Appends the values to insert to the specified array.
     *
     * @return array
     *  Returns the list of insert fields as an associative array whose keys
     *  represent the fields to insert.
     */
    final public function getInserts(array &$values) {
        if (!$this->create) {
            return false;
        }

        // Process any specified updates as field inserts.
        if (isset($this->updates)) {
            foreach ($this->updates as $key => &$value) {
                $value = $this->fields[$key];
                $values[] = $this->fields[$key];
            }
            unset($value);
        }
        else {
            return false;
        }

        // Add any non-null keys to the list of inserts. We'll assume null
        // keys are defaulted or auto-incremented in some way by the DB
        // engine.
        foreach ($this->keys as $key => $value) {
            if (!is_null($value) && !isset($this->updates[$key])) {
                $this->updates[$key] = true;
                $values[] = $value;
            }
        }

        // Abort operation if no inserts are available.
        if (!isset($this->updates)) {
            return false;
        }

        return $this->updates;
    }

    /**
     * Commit any pending changes to the database. This must be done explicitly.
     *
     * @return bool
     *  Returns true if the entity was successfully updated or created, false
     *  otherwise. False may be returned if the entity had nothing to
     *  insert/update.
     */
    public function commit() {
        // Begin a transaction for the commit process and perform any precommit
        // operation.
        $this->conn->beginTransaction();
        if ($this->preCommit($this->create) === false) {
            $this->conn->rollback();
            return false;
        }

        if ($this->create) {
            // If we can only update this entity then we cannot insert into it
            // and must bail out. The postCommit() hook is not called because a
            // commit is semantically incorrect.
            if ($this->updateOnly) {
                $this->conn->endTransaction();
                return false;
            }

            // Get inserts information.
            $values = [];
            $inserts = $this->getInserts($values);
            if ($inserts === false) {
                $this->conn->endTransaction();
                return false;
            }
            $fieldNames = array_keys($inserts);

            // Build the query.
            $fields = implode(',',$fieldNames);
            $prep = '?' . str_repeat(',?',count($inserts)-1);
            $query = "INSERT INTO $this->table ($fields) VALUES ($prep)";
        }
        else {
            // Abort operation if no updates are available. We still invoke the
            // postCommit() hook since the commit is semantically correct but
            // just empty.
            if (!isset($this->updates)) {
                if ($this->postCommit(false) === false) {
                    $this->conn->rollback();
                    return false;
                }

                // Succeed with no changes.
                $this->conn->endTransaction();
                return true;
            }

            // Process the set of updates and prepared values for the query.
            $fieldNames = array_keys($this->updates);
            foreach ($fieldNames as $name) {
                $values[] = $this->fields[$name];
            }
            $keyCondition = $this->getKeyString($keyvals);
            $values = array_merge($values,$keyvals);

            // Build the query.
            $fields = implode(',',array_map(function($x){ return "$x = ?"; },
                                            $fieldNames));
            $query = "UPDATE $this->table SET $fields WHERE $keyCondition LIMIT 1";
        }

        // Allow derived classes to modify field values before commit through
        // Entity::processCommitFields().
        for ($i = 0;$i < count($fieldNames);++$i) {
            $processing[$fieldNames[$i]] =& $values[$i];
        }
        $this->processCommitFields($processing);

        // Perform the query.
        $stmt = $this->conn->query($query,$values);
        if ($stmt->rowCount() < 1) {
            // Attempt to create the entity if we hadn't already tried and the
            // update only flag is not active.
            if (!$this->create && !$this->updateOnly) {
                // Attempt to create the entity.
                $this->create = true;
                $status = $this->commit();
                $this->conn->endTransaction();
                return $status;
            }

            // Assume the entity was updated but had no changes.
            $this->conn->endTransaction();
            return true;
        }

        // Handle insert ID updates. We only do this conventionally for keys
        // with the name 'id'.
        if (array_key_exists('id',$this->keys) && is_null($this->keys['id'])) {
            $this->keys['id'] = $this->conn->lastInsertId();
            if (array_key_exists('id',$this->fields)) {
                $this->fields['id'] = $this->keys['id'];
                // No need to set update flag.
            }
        }

        // Invoke post commit method.
        if ($this->postCommit($this->create) === false) {
            $this->conn->rollback();
            return false;
        }
        $this->conn->endTransaction();

        unset($this->updates);
        return true;
    }

    /**
     * Invalidates the Entity object to where all fields will be re-fetched at
     * next access.
     */
    public function invalidate() {
        $this->fetchState = false;
    }

    /**
     * Creates a new Entity instance. This must be called by derived classes in
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
     *  exist and an attempt will be made to fetch fields.
     */
    protected function __construct(DatabaseConnection $conn,$table,array $keys,$create = false) {
        $this->conn = $conn;
        $this->table = $table;
        $this->keys = $keys;

        // Force create if specified or if null appears as one of the key
        // values.
        if (in_array(null,array_values($keys))) {
            $this->create = true;
            $this->fetchState = true;
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
     *  The field name corresponding to the database field name. This *must* be
     *  a field in the entity type's corresponding table.
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
    final protected function registerField($field,$propertyName = null,$default = null,$filter = null) {
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

    final protected function getKeys() {
        return $this->keys;
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
    final protected function getKeyString(&$values) {
        $keys = array_keys($this->keys);
        $query = implode(' AND ',array_map(function($x){ return "$x = ?"; },$keys));
        $values = array_values($this->keys);
        return $query;
    }

    /**
     * Gets the query used to fetch the entity fields. This may be overridden by
     * derived classes to handle more complicated entity types.
     *
     * @param array &$values
     *  The array of variables for the query statement.
     *
     * @return string
     *  The query string
     */
    protected function getFetchQuery(array &$values) {
        $keyCondition = $this->getKeyString($values);
        $fields = implode(',',array_keys($this->fields));

        $query = "SELECT $fields FROM $this->table WHERE $keyCondition LIMIT 1";
        return $query;
    }

    /**
     * Allows derived classes to process the fetch results. The default
     * implementation does nothing.
     *
     * @param array &$fetches
     *  The array of fetch results from the fetch query. The keys correspond to
     *  the database table field names, not the aliases.
     */
    protected function processFetchResults(array &$fetches) {

    }

    /**
     * Allows derived classes to process the field data before it is
     * committed. The default implementation does nothing.
     *
     * @param array $fields
     *  The associative array of field names to field values. The field names
     *  are the database table field names, not the aliases. The function should
     *  modify the field value variables to process a field (these variables are
     *  references).
     */
    protected function processCommitFields(array $fields) {

    }

    /**
     * Invoked immediately before the entity has been committed. This method is
     * to be overridden by a derived class. The default implementation does
     * nothing.
     *
     * The operation is included in the commit transaction. Note that pre commit
     * may actually be called more than once so a derived class should track
     * this.
     *
     * @param bool $insert
     *  If true, the commit performed an INSERT query. Otherwise an UPDATE query
     *  will be performed.
     *
     * @return mixed
     *  If the function returns False, then the commit is aborted. Any other
     *  value is ignored and the commit proceeds.
     */
    protected function preCommit($insert) {

    }

    /**
     * Invoked when the entity has been committed. This method is to be
     * overridden by a derived class. The default implementation does nothing.
     *
     * The operation is included in the commit transaction.
     *
     * @param bool $insert
     *  If true, the commit performed an INSERT query. Otherwise an UPDATE query
     *  was performed.
     *
     * @return mixed
     *  If the function returns False, then the commit is aborted. Any other
     *  value is ignored and the commit proceeds.
     */
    protected function postCommit($insert) {

    }

    /**
     * Performs the fetch operation. This overwrites all field values currently
     * available.
     */
    private function doFetch() {
        if (!$this->fetchState) {
            $values = [];
            $query = $this->getFetchQuery($values);
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
                // Allow derived functionality the chance to process the fields.
                $this->processFetchResults($newfields);

                // Update fields with new fetch results.
                $this->setFields($newfields);
            }
        }
    }
}

<?php

/**
 * Entity.php
 *
 * @package tccl\database
 */

namespace TCCL\Database;

use PDO;

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
     * Helper function to collect values from an array having a common prefix.
     *
     * @param string $prefix
     * @param array $row
     * @param array $extra
     *
     * @return array
     */
    public static function collect($prefix,array $row,array $extra = []) {
        $collection = [];

        foreach ($row as $key => $value) {
            if (substr($key,0,strlen($prefix)) == $prefix) {
                $collection[substr($key,strlen($prefix))] = $value;
            }
        }

        return $collection + $extra;
    }

    /**
     * Store all instance state in this bucket to free up property names in
     * derived classes.
     *
     * @var array
     */
    private $__info = [
        /**
         * The database connection.
         *
         * @var \TCCL\Database\DatabaseConnection
         */
        'conn' => null,

        /**
         * The table in the database that represents the entity type.
         *
         * @var string
         */
        'table' => null,

        /**
         * The key or keys involved in querying a specific entity. Each element
         * maps a key name to the expected value.
         *
         * @var array
         */
        'keys' => null,

        /**
         * The properties exposed by the model. The keys are the property names
         * and the values are the field names to which they correspond
         * respectively.
         *
         * @var array
         */
        'props' => null,

        /**
         * An associative array mapping field names to their values in the
         * model.
         *
         * @var array
         */
        'fields' => null,

        /**
         * An associative array whose keys represent the set of fields to
         * update.
         *
         * @var array
         */
        'updates' => null,

        /**
         * The set of callbacks to apply to map query result values to their
         * field value during sync. This is an associative array mapping field
         * names to callables.
         *
         * @var array
         */
        'syncFn' => null,

        /**
         * The set of callbacks to apply to map field values to their query
         * values during commit. This is an associative array mapping field
         * names to callables.
         *
         * @var array
         */
        'commitFn' => null,

        /**
         * Determines whether the fields have been fetched. Typically this is
         * only done once in the object's lifetime unless it is invalidated.
         *
         * @var bool
         */
        'fetchState' => false,

        /**
         * Determines whether the entity is expected to be created or updated.
         *
         * @var bool
         */
        'create' => null,

        /**
         * Caches whether the entity exists. This is essentially whether at
         * least one field is set.
         *
         * @var bool
         */
        'existsState' => false,

        /**
         * Determines whether the entity is expected to already exist. By
         * default we don't care and will attempt to UPDATE or INSERT the entity
         * as needed.
         *
         * @var bool
         */
        'updateOnly' => false,

    ]; // $__info

    /**
     * Overloads for special handlers.
     */

    public function __get($propertyName) {
        $this->sync();
        if (isset($this->__info['props'][$propertyName])) {
            return $this->__info['fields'][$this->__info['props'][$propertyName]];
        }

        trigger_error("Undefined entity property: $propertyName",E_USER_NOTICE);
    }

    public function __set($propertyName,$value) {
        if (isset($this->__info['props'][$propertyName])) {
            $fieldName = $this->__info['props'][$propertyName];
            $this->__info['fields'][$fieldName] = $value;
            $this->__info['updates'][$fieldName] = true;
            $this->__info['fetchState'] = true;
            return;
        }

        trigger_error("Undefined entity property: $propertyName",E_USER_NOTICE);
    }

    public function __isset($field) {
        // Make sure the property is registered and it's value is set.
        $this->sync();
        return isset($this->__info['props'][$field]) && isset($this->__info['fields'][$this->__info['props'][$field]]);
    }

    /**
     * Determines if the entity exists.
     *
     * @return bool
     */
    final public function exists() {
        // NOTE: The existsState may be independent of the fetchState.
        if (!$this->__info['existsState']) {
            $this->sync();
        }

        return $this->__info['existsState'];
    }

    /**
     * Sets the update-only state of the Entity.
     *
     * @param bool $state
     *  The flag to set.
     */
    final public function setUpdateOnly($state = true) {
        $this->__info['updateOnly'] = $state;
    }

    /**
     * Gets the database connection instance.
     *
     * @return \TCCL\Database\DatabaseConnection
     */
    final public function getConnection() {
        return $this->__info['conn'];
    }

    /**
     * Gets the table name for the Entity.
     *
     * @return string
     */
    final public function getTable() {
        return $this->__info['table'];
    }

    /**
     * Gets the complete set of fields representing the entity. The values
     * represent the most current state of the entity object.
     *
     * @param bool $userPropertyNames
     *  If true then the keys in the array are the property names instead of the
     *  database table names.
     *
     * @return array
     *  An associative array mapping field names to field values.
     */
    final public function getFields($usePropertyNames = true) {
        $this->sync();

        $fields = $this->lookupFields();

        if ($usePropertyNames) {
            $result = [];

            foreach ($this->__info['props'] as $propName => $fieldName) {
                $result[$propName] = $fields[$fieldName];
            }
        }
        else {
            $result = $fields;
        }

        return $result;
    }

    /**
     * Gets the complete set of field names for the entity.
     *
     * @param bool $usePropertyNames
     *  If true then the keys in the array are the property names instead of the
     *  database table names.
     *
     * @return array
     *  An indexed array containing the field names.
     */
    final public function getFieldNames($usePropertyNames = true) {
        if ($usePropertyNames) {
            return array_keys($this->__info['props']);
        }

        return array_values($this->__info['props']);
    }

    /**
     * Sets the fields for the Entity.
     *
     * @param array $fields
     *  The associative array of fields for the entity. This is cross-referenced
     *  for keys meaning any keys that exist in $fields but weren't registered
     *  are ignored. Both field names and property names are allowed. When both
     *  are provided, the property name is preferred.
     * @param bool $synchronized
     *  If true, then the field values are assumed to already be synchronized
     *  with the database backend. Otherwise updates are queued for a later
     *  commit.
     */
    final public function setFields(array $fields,$synchronized = true) {
        // Always set the fetchState to true to avoid overwriting the fields.
        $this->__info['fetchState'] = true;

        // Prefer props over field names in case there are duplicates.
        foreach (array_keys($fields) as $name) {
            if (isset($this->__info['props'][$name])
                && $this->__info['props'][$name] != $name)
            {
                unset($fields[$this->__info['props'][$name]]);
            }
        }

        // Gather values to apply to the entity.

        $apply = [];
        $fn = $this->makeApplyFn('syncFn');

        foreach ($fields as $name => $value) {
            // See if the name is actually a distinct property name. If so then
            // map it to its corresponding field name.
            if (isset($this->__info['props'][$name])) {
                $name = $this->__info['props'][$name];
            }

            // Map value from database variant to field variant.
            $fn($value,$name);

            // Set the value under 'keys' bucket if the field is registered as a
            // key.
            if (array_key_exists($name,$this->__info['keys'])) {
                $this->__info['keys'][$name] = $value;
            }

            // Set field value if found.
            if (array_key_exists($name,$this->__info['fields'])) {
                $apply[$name] = $value;
            }
        }

        $this->applyFields($apply,$synchronized);

        // If the caller indicated the entity is synchronized, change the create
        // flag to reflect this.
        if ($synchronized) {
            // The entity exists if there is at least one non-empty key.
            $this->__info['existsState'] = !empty(
                array_filter($this->__info['keys'])
            );

            $this->__info['create'] = false;
        }
    }

    /**
     * Marks a field as having been edited without actually making edits. This
     * is useful for complex field types that cannot report when they are
     * modified via the __set() object handler.
     *
     * NOTE: this method is only useful for Entities that derive directly from
     * TCCL\Database\Entity.
     *
     * @param string $fieldName
     *  The field name. This may either be the table field name or property name
     *  (i.e. alias).
     */
    final public function touchField($fieldName) {
        // See if the field name is a property name first so we can resolve
        // property names.
        if (isset($this->__info['props'][$fieldName])) {
            $fieldName = $this->__info['props'][$fieldName];
        }

        if (array_key_exists($fieldName,$this->__info['fields'])) {
            $this->__info['updates'][$fieldName] = true;
        }
    }

    /**
     * Multifield version of touchField().
     */
    final public function touchFields(/* $field, ... */) {
        foreach (func_get_args() as $fieldName) {
            $this->touchField($fieldName);
        }
    }

    /**
     * Commit any pending changes to the database. This must be done explicitly.
     *
     * @param bool $invalidate
     *  Determines if the entity is invalidated after commit. The default
     *  behavior is to invalidate the Entity so that its fields are refetched at
     *  a later time.
     * @param bool $recursive
     *  Used by the implementation.
     *
     * @return bool
     *  Returns true if the entity was successfully updated or created, false
     *  otherwise. If false is returned, then the transaction was rolled back
     *  and the Entity may be in an inconsistent state.
     */
    public function commit($invalidate = true,$recursive = false) {
        // Begin a transaction for the commit process and perform any precommit
        // operation.
        if (!$recursive) {
            $this->__info['conn']->beginTransaction();
        }
        if ($this->preCommit($this->__info['create']) === false) {
            $this->rollback();
            return false;
        }

        $fieldNames = $this->getDirtyFields($values);

        if ($this->__info['create']) {
            // NOTE: If update-only is flagged or if there are no inserts to
            // process, postCommit() hook is not called because a commit is
            // semantically incorrect. In this instance we just do a rollback.

            if ($this->__info['updateOnly']) {
                $this->rollback();
                return false;
            }

            // Check if we have fields to update.
            if ($fieldNames === false) {
                $this->rollback();
                return false;
            }

            // Build the query.
            $fields = implode(',',array_map(function($x){ return "`$x`"; },$fieldNames));
            $prep = '?' . str_repeat(',?',count($fieldNames)-1);
            $query = "INSERT INTO `{$this->__info['table']}` ($fields) VALUES ($prep)";
        }
        else {
            // Abort operation if no updates are available. We still invoke the
            // postCommit() hook since the commit is semantically correct but
            // just empty.
            if ($fieldNames === false) {
                // Call success function to change state *before* post-commit.
                $this->commitSuccess($invalidate);

                if ($this->postCommit(false) === false) {
                    $this->rollback();
                    return false;
                }

                // Succeed with no direct changes.
                $this->__info['conn']->commit();
                return true;
            }

            // Build the query.
            $keyCondition = $this->getKeyStringEx($keyvals,$keynames);
            $fields = implode(',',array_map(function($x){ return "`$x` = ?"; },
                                            $fieldNames));
            $query = "UPDATE `{$this->__info['table']}` SET $fields WHERE $keyCondition LIMIT 1";

            // Augment values and field name lists with key values.
            $values = array_merge($values,$keyvals);
            $fieldNames = array_merge($fieldNames,$keynames);
        }

        // Process query values before commit. Allow derived classes to modify
        // query values before commit through Entity::processCommitFields().
        $processing = $this->applyFnEx('commitFn',$values,$fieldNames);
        $this->processCommitFields($processing);

        // Perform the query.
        try {
            $stmt = $this->__info['conn']->query($query,$values);
        } catch (\Exception $ex) {
            $this->rollback();
            throw $ex;
        }

        if ($stmt->rowCount() < 1) {
            if (!$this->__info['create']) {
                // If the row count is less than one on UPDATE, then several
                // things may have happened: 1) The entity exists but none of
                // the fields were actually updated or 2) the entity does not
                // exist. We must call exists() to resolve the ambiguity.

                if (!$this->__info['existsState']) {
                    // If we do not know if the entity exists, we must ensure we
                    // can do a fetch to make the determination.
                    $this->__info['fetchState'] = false;
                }

                if (!$this->exists()) {
                    if ($this->__info['updateOnly']) {
                        // The commit fails if the entity does not exist when in
                        // update-only mode.
                        $this->rollback();
                        return false;
                    }

                    // Attempt to create the entity recursively. This will
                    // handle any commit(), commitSuccess() and/or
                    // rollback() calls.
                    $this->__info['create'] = true;
                    return $this->commit($invalidate,true);
                }

                // Otherwise we must assume the entity exists but the update had
                // no changes.

                // Call success function to change state *before* post-commit.
                $create = $this->__info['create'];
                $this->commitSuccess($invalidate);

                // Invoke post commit method.
                if ($this->postCommit($create) === false) {
                    $this->rollback();
                    return false;
                }

                $this->__info['conn']->commit();

                return true;
            }

            // If the row count is less than one on INSERT, then the commit
            // fails.

            $this->rollback();
            return false;
        }

        // Handle ID key field updates (on INSERT only). We do this
        // conventionally for keys and fields with the name 'id'.
        if ($this->__info['create']) {
            $lastInsertId = $this->__info['conn']->lastInsertId();
            if (is_numeric($lastInsertId)) {
                $lastInsertId = (int)$lastInsertId;
            }

            if (array_key_exists('id',$this->__info['keys']) && is_null($this->__info['keys']['id'])) {
                $this->__info['keys']['id'] = $lastInsertId;
            }
            if (array_key_exists('id',$this->__info['fields'])) {
                $this->applyFields(['id' => $lastInsertId],true);
            }
        }

        // Call success function to change state *before* post-commit.
        $create = $this->__info['create'];
        $this->commitSuccess($invalidate);

        // Invoke post commit method.
        if ($this->postCommit($create) === false) {
            $this->rollback();
            return false;
        }

        $this->__info['conn']->commit();
        unset($this->__info['updates']);

        return true;
    }

    /**
     * Deletes the entity record.
     */
    public function delete() {
        $this->__info['conn']->beginTransaction();

        // Build the query.
        $keyCondition = $this->getKeyStringEx($keyValues,$keyNames);
        $query = "DELETE FROM `{$this->__info['table']}` WHERE $keyCondition";

        // Convert the key values to storage representation via commitFn.
        $this->applyFnEx('commitFn',$keyValues,$keyNames);

        // Perform the query.
        try {
            $this->__info['conn']->query($query,$keyValues);
        } catch (\Exception $ex) {
            $this->rollback();
            throw $ex;
        }

        $this->__info['conn']->commit();
        $this->invalidate(true);
    }

    /**
     * Performs the fetch operation. This overwrites all field values currently
     * available.
     */
    final public function sync() {
        if (!$this->__info['fetchState']) {
            $values = [];
            $fieldNames = [];
            $query = $this->getFetchQueryEx($values,$fieldNames);
            $this->applyFnEx('commitFn',$values,$fieldNames);
            $stmt = $this->__info['conn']->query($query,$values);

            $newfields = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->__info['fetchState'] = true;

            // If we didn't get any results, we can assume the entity doesn't
            // exist. In this case we'll want to enter create mode so that any
            // future commit won't attempt an UPDATE but skip to an INSERT.
            if (!is_array($newfields)) {
                $this->__info['create'] = true;
                $this->__info['existsState'] = false;
            }
            else {
                // Allow derived functionality the chance to process the fields.
                $this->processFetchResults($newfields);

                // Update fields with new fetch results. (This sets state to
                // exists.)
                $this->setFields($newfields);

                // Allow derived functionality to execute after sync.
                $this->postSync();
            }
        }
    }

    /**
     * Invalidates the Entity object to where all fields will be re-fetched at
     * next access.
     *
     * @param bool $deleted
     *  If true, the object is invalidated in such a way that it can be used
     *  again for a new entity. This is useful to call for a deleted object.
     */
    public function invalidate($deleted = false) {
        $this->__info['existsState'] = false;

        if ($deleted) {
            $this->__info['fetchState'] = true;
            $this->__info['create'] = true;

            array_walk($this->__info['keys'], function(&$value) {
                $value = null;
            });
        }
        else {
            $this->__info['fetchState'] = false;
        }
    }

    /**
     * Gets dirty fields but only for insert.
     *
     * @param array &$values
     *  Appends the values to insert to the specified array.
     *
     * @return array
     *  Returns the list of insert fields as an associative array.
     */
    final public function getInserts(array &$values) {
        if (!$this->__info['create']) {
            return false;
        }

        $result = $this->getDirtyFields($values);
        if ($result === false) {
            return false;
        }

        return array_fill_keys($result,true);
    }

    /**
     * Creates a new Entity instance. This must be called by derived classes in
     * order for the object to function properly.
     *
     * @param \TCCL\Database\DatabaseConnection $conn
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
        $this->__info['conn'] = $conn;
        $this->__info['table'] = $table;
        $this->__info['keys'] = $keys;

        // Force create if specified or if null appears as one of the key
        // values.
        if (in_array(null,array_values($keys))) {
            $this->__info['create'] = true;
            $this->__info['fetchState'] = true;
        }
        else {
            $this->__info['create'] = $create;
            $this->__info['fetchState'] = $create;
        }
    }

    /**
     * Registers a field with the object. This should be called by the derived
     * class for the fields it wants to include as a part of its interface. Each
     * field is provided as a property of the object.
     *
     * NOTE: this method is only useful for Entities that derive directly from
     * TCCL\Database\Entity.
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
     * @param ?callable $syncFn
     *  Optional callable for mapping database values to field values during
     *  sync. (This used to be called 'filter' and serves the same purpose.)
     * @param ?callable $commitFn
     *  Optional callable for mapping field values to database values during
     *  commit.
     */
    final protected function registerField(
        $field,
        $propertyName = null,
        $default = null,
        $syncFn = null,
        $commitFn = null)
    {
        if (empty($propertyName)) {
            $propertyName = $field;
        }
        if (property_exists($this,$propertyName)) {
            trigger_error("Cannot register field '$propertyName'.",E_USER_ERROR);
            return;
        }

        $this->__info['fields'][$field] = $default;
        $this->__info['props'][$propertyName] = $field;
        if (is_callable($syncFn)) {
            $this->__info['syncFn'][$field] = $syncFn;
        }
        if (is_callable($commitFn)) {
            $this->__info['commitFn'][$field] = $commitFn;
        }
    }

    /**
     * Registers all fields in one call.
     *
     * @param array $fields
     *  The array of fields to set. Each element has the following structure:
     *   - field_name
     *   - prop_name
     *   - default_value
     *   - filter (deprecated; same as 'syncFn')
     *   - syncFn
     *   - commitFn
     */
    final protected function registerAllFields(array $fields) {
        foreach ($fields as $fieldInfo) {
            $fieldInfo += [
                'prop_name' => null,
                'default_value' => null,
                'syncFn' => null,
                'commitFn' => null,
            ];

            if (!isset($fieldInfo['syncFn']) && isset($fieldInfo['filter'])) {
                $fieldInfo['syncFn'] = $fieldInfo['filter'];
            }

            $this->registerField(
                $fieldInfo['field_name'],
                $fieldInfo['prop_name'],
                $fieldInfo['default_value'],
                $fieldInfo['syncFn'],
                $fieldInfo['commitFn']
            );
        }
    }

    final protected function setFieldInfo(
        array $fields,
        array $props,
        array $syncFns,
        array $commitFns = [])
    {
        $this->__info['fields'] = $fields;
        $this->__info['props'] = $props;
        $this->__info['syncFn'] = $syncFns;
        $this->__info['commitFn'] = $commitFns;
    }

    final protected function getKeys() {
        return $this->__info['keys'];
    }

    final protected function &queryUpdates() {
        return $this->__info['updates'];
    }

    /**
     * Gets the string representing the WHERE key bind condition in the SQL
     * query. This is just a convenience wrapper.
     *
     * @param array &$values
     *  Returns the query values required for the fragment.
     * @param string $tableAlias
     *  Optional alias for table.
     *
     * @return string
     */
    final protected function getKeyString(&$values,$tableAlias = null) {
        if (!isset($tableAlias)) {
            $tableAlias = $this->__info['table'];
        }

        $keys = array_keys($this->__info['keys']);

        $query = implode(
            ' AND ',
            array_map(
                function($x) use($tableAlias) {
                    return "`{$tableAlias}`.`$x` = ?";
                },
                $keys
            )
        );

        $values = array_values($this->__info['keys']);

        return $query;
    }

    /**
     * Gets the string representing the WHERE key bind condition in the SQL
     * query. This is just a convenience wrapper.
     *
     * @param array &$values
     *  Returns the query values required for the fragment.
     * @param array &$fields
     *  Returns the ordered set of field names corresponding with the values in
     *  the $values array.
     * @param string $tableAlias
     *  Optional alias for table.
     *
     * @return string
     */
    final protected function getKeyStringEx(&$values,&$fields = null,$tableAlias = null) {
        $fields = array_keys($this->__info['keys']);
        return $this->getKeyString($values,$tableAlias);
    }

    /**
     * Gets the string representing the query fields for a SELECT query.
     *
     * @return string
     */
    final protected function getFieldString($tableAlias = null) {
        if (!isset($tableAlias)) {
            $tableAlias = $this->__info['table'];
        }

        $fields = array_keys($this->__info['fields']);
        $fields = array_map(function($x) use($tableAlias) {
            return "`{$tableAlias}`.`{$x}`";
        }, $fields);
        $fields = implode(',',$fields);

        return $fields;
    }

    /**
     * Gets the set of fields that are dirty (i.e. modified in-between syncs).
     *
     * @param array &$values
     *  The method returns the field values in this variable.
     *
     * @return array
     *  The method returns the field keys, or false if no fields were dirty.
     */
    protected function getDirtyFields(&$values) {
        // Use the 'updates' state to obtain list of dirty fields.

        if (!isset($this->__info['updates']) || count($this->__info['updates']) == 0) {
            return false;
        }

        // Process any specified updates as field inserts.
        foreach (array_keys($this->__info['updates']) as $key) {
            $values[] = $this->__info['fields'][$key];
        }

        // Add any non-null keys to the list of inserts. We'll assume null
        // keys are defaulted or auto-incremented in some way by the DB
        // engine.
        foreach ($this->__info['keys'] as $key => $value) {
            if (!is_null($value) && !isset($this->__info['updates'][$key])) {
                $this->__info['updates'][$key] = true;
                $values[] = $value;
            }
        }

        return array_keys($this->__info['updates']);
    }

    /**
     * Looks up the field values.
     *
     * @return array
     *  An associative array mapping field names to values.
     */
    protected function lookupFields() {
        return $this->__info['fields'];
    }

    /**
     * Applies a subset of the entity fields to the entity's field store.
     *
     * @param array $fields
     *  The fields to apply. The keys are the field names and the values are the
     *  field values.
     * @param bool $synchronized
     *  Determines if field values should be considered synchronized with the
     *  database backend.
     */
    protected function applyFields($fields,$synchronized = true) {
        foreach ($fields as $key => $value) {
            $this->__info['fields'][$key] = $value;
            if (!$synchronized) {
                $this->__info['updates'][$key] = true;
            }
        }
    }

    /**
     * Gets the query used to fetch the entity fields. This may be overridden by
     * derived classes to handle more complicated entity types. A fetch query
     * always uses positional prepared parameters.
     *
     * @param array &$values
     *  Returns the values required for the query.
     *
     * @return string
     *  The query string
     */
    protected function getFetchQuery(array &$values) {
        $keyCondition = $this->getKeyStringEx($values,$fields);
        $fieldNames = $this->getFieldString();

        $query = "SELECT $fieldNames FROM `{$this->__info['table']}` WHERE $keyCondition LIMIT 1";

        return $query;
    }

    /**
     * Gets the query used to fetch the entity fields. This may be overridden by
     * derived classes to handle more complicated entity types. A fetch query
     * always uses positional prepared parameters.
     *
     * Note: This variant is a transition for version 2.
     *
     * @param array &$values
     *  Returns the values required for the query.
     * @param array &$fields
     *  Returns the ordered set of field names corresponding with the values in
     *  the $values array.
     *
     * @return string
     *  The query string
     */
    protected function getFetchQueryEx(array &$values,array &$fields) {
        $query = $this->getFetchQuery($values);
        $fields = array_keys($this->__info['keys']);
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
     * Invoked when the entity has been successfully synced. The default
     * implementation has nothing to do.
     */
    protected function postSync() {

    }

    /**
     * Called when a commit() fails.
     */
    private function rollback() {
        $this->invalidate();
        $this->__info['conn']->rollback();
    }

    /**
     * Called when a commit() succeeds and had changes.
     *
     * @param bool $invalidate
     *  Determines if the entity is invalidated after commit. The default
     *  behavior is to invalidate the Entity so that its fields are refetched at
     *  a later time.
     */
    private function commitSuccess($invalidate = true) {
        $this->__info['fetchState'] = !$invalidate;
        $this->__info['existsState'] = true;
        $this->__info['create'] = false;
    }

    private function makeApplyfn($bucket) {
        return function(&$value,$name) use($bucket) {
            if (is_null($value)) {
                return null;
            }

            if (isset($this->__info[$bucket][$name])) {
                $fn = $this->__info[$bucket][$name];
                assert(is_callable($fn));

                $value = $fn($value);
            }
        };
    }

    private function applyFn($bucket,array &$values) {
        array_walk($values,$this->makeApplyfn($bucket));
    }

    private function applyFnEx($bucket,array &$values,array $fieldNames) {
        $map = [];
        for ($i = 0;$i < count($fieldNames);++$i) {
            $map[$fieldNames[$i]] =& $values[$i];
        }

        array_walk($map,$this->makeApplyFn($bucket));

        return $map;
    }
}

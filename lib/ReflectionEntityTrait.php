<?php

/**
 * ReflectionEntityTrait.php
 *
 * @package tccl\database
 */

namespace TCCL\Database;

trait ReflectionEntityTrait {
    /**
     * Maps field names to property names.
     *
     * @var array
     */
    private $__reverseProps;

    /**
     * Implements ReflectionEntity::initialize in a standard way.
     *
     * @param array $schema
     *  The ReflectionEntity schema for this instance.
     *
     * @return array
     *  Returns an associative array denoting the entity keys to use in the
     *  Entity class constructor call.
     */
    protected function initialize(array $schema) {
        $this->__reverseProps = array_flip($schema['props']);

        // Lookup key values on object properties.
        $keys = array_fill_keys($schema['keys'],null);
        foreach ($schema['keys'] as $key) {
            $prop = $this->__reverseProps[$key];
            $keys[$key] = $this->$prop;
        }

        return $keys;
    }

    /**
     * Overrides Entity::getDirtyFields().
     */
    protected function getDirtyFields(&$values) {
        $values = [];
        $fields = [];
        $updates = $this->queryUpdates();

        foreach ($this->__reverseProps as $field => $prop) {
            if (isset($updates[$field])) {
                $fields[] = $field;
                $values[] = $this->$prop;
            }
        }

        if (count($fields) == 0) {
            $values = null;
            return false;
        }

        return $fields;
    }

    /**
     * Overrides Entity::lookupFields().
     */
    protected function lookupFields() {
        $results = [];

        foreach ($this->__reverseProps as $field => $prop) {
            $results[$field] = $this->$prop;
        }

        return $results;
    }

    /**
     * Overrides Entity::applyFields().
     */
    protected function applyFields($fields,$synchronized = true) {
        $updates =& $this->queryUpdates();
        foreach ($fields as $field => $value) {
            $prop = $this->__reverseProps[$field];
            $this->$prop = $value;

            if (!$synchronized) {
                $updates[$field] = true;
            }
        }
    }
}

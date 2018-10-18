<?php

namespace TCCL\Database;

trait ReflectionEntityTrait {
    /**
     * Maps field names to property names.
     *
     * @var array
     */
    private $__reverseProps;

    /**
     * Stores the clean field values.
     *
     * @var array
     */
    private $__clean;

    /**
     * Initializes the trait's state.
     */
    protected function initialize(array $entry) {
        $this->__reverseProps = array_flip($entry['props']);

        // Lookup key values on object properties.
        $keys = array_fill_keys($entry['keys'],null);
        foreach ($entry['keys'] as $key) {
            $prop = $entry['props'][$key];
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

        foreach ($this->__reverseProps as $field => $prop) {
            $dirty = (string)$this->$prop;
            if ((string)$this->__clean[$field] != $dirty) {
                $fields[] = $field;
                $values[] = $dirty;
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
        // Update clean state if values are synchronized. We always write to the
        // dirty state since each application should be reflected in the
        // object's properties.

        if ($synchronized) {
            foreach ($fields as $field => $value) {
                $this->__clean[$field] = $value;
            }
        }

        foreach ($fields as $field => $value) {
            $prop = $this->__reverseProps[$field];
            $this->$prop = $value;
        }
    }

    /**
     * Overrides Entity::syncDirtyFields().
     */
    protected function syncDirtyFields() {
        // Apply dirty properties to the clean set.
        foreach ($this->__reverseProps as $field => $prop) {
            $this->__clean[$field] = $this->$prop;
        }
    }
}

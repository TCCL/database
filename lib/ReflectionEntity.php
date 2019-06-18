<?php

/**
 * ReflectionEntity
 *
 * tccl/database
 */

namespace TCCL\Database;

use ReflectionClass;

/**
 * An Entity that deduces its structure by using class reflection.
 */
abstract class ReflectionEntity extends Entity {
    private static $__schemaCache;

    /**
     * Creates a new ReflectionEntity instance.
     *
     * @param DatabaseConnection $conn
     *  Database connection to employ.
     * @param string $table
     *  An override table name that replaces the default provided in the doc
     *  comment.
     */
    public function __construct(DatabaseConnection $conn,$table = null) {
        $class = get_class($this);

        if (!isset(self::$__schemaCache[$class])) {
            self::$__schemaCache[$class] = self::loadSchema($class);
        }

        $entry = self::$__schemaCache[$class];
        if (isset($table)) {
            $entry['table'] = $table;
        }
        $keys = $this->initialize($entry);

        parent::__construct($conn,$entry['table'],$keys,in_array(null,$keys));
        $this->setFieldInfo($entry['fields'],$entry['props'],$entry['filters']);
    }

    /**
     * A ReflectionEntity instance does not employ the __get() and __set() magic
     * methods since all properties exist on the object.
     */

    public function __set($name,$value) {
        trigger_error("Undefined entity property: $name",E_USER_NOTICE);
    }

    public function __get($name) {
        trigger_error("Undefined entity property: $name",E_USER_NOTICE);
    }

    public function __isset($name) {
        return false;
    }

    private static function loadSchema($className) {
        $rf = new ReflectionClass($className);
        $topLevelTags = self::parseDocTags($rf->getDocComment());

        if (!isset($topLevelTags['table'])) {
            trigger_error('Class doc comment is missing table definition',E_USER_ERROR);
        }

        $props = [];
        $fields = [];
        $filters = [];
        $keys = [];

        foreach ($rf->getProperties() as $prop) {
            $tags = self::parseDocTags($prop->getDocComment());

            if (isset($tags['field'])) {
                $field = $tags['field'];
                $props[$prop->getName()] = $field;

                if (isset($tags['filter']) && is_callable($tags['filter'])) {
                    $filter = $tags['filter'];

                    $fields[$field] = null;
                    if (isset($fields[$field])) {
                        $fields[$field] = $filter($fields[$field]);
                    }

                    $filters[$field] = $filter;
                }
                else {
                    $fields[$field] = null;
                }

                if (array_key_exists('key',$tags)) {
                    $keys[] = $field;
                }
            }
        }

        return [
            'table' => $topLevelTags['table'],
            'fields' => $fields,
            'props' => $props,
            'filters' => $filters,
            'keys' => $keys,
        ];
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

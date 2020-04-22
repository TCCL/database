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
     * Gets metadata for the ReflectionEntity class.
     *
     * @return array
     *  An associative array having the following fields:
     *   - table
     *   - fields
     *   - props
     *   - filters
     *   - keys
     */
    public static function getMetadata() {
        // Load schema from class metadata.
        $schema = self::loadSchema(static::class);

        if ($schema === false) {
            trigger_error(
                "ReflectionEntity: Schema metadata could not be loaded for class '$class' or any parent class",
                E_USER_ERROR
            );
        }

        return $schema;
    }

    /**
     * Normalizes a field name.
     *
     * @param string $propOrField
     *  Property or field name.
     *
     * @return string
     *  Returns false if the value did not normalize to a valid field.
     */
    public static function normalizeField($propOrField) {
        $schema = self::getMetadata();

        if (isset($schema['props'][$propOrField])) {
            return $schema['props'][$propOrField];
        }

        if (array_key_exists($propOrField,$schema['fields'])) {
            return $propOrField;
        }

        return false;
    }

    /**
     * Creates an SQL fragment for filtering a SELECT query via a WHERE clause. The fragment
     *
     * @param array &$vars
     *  Associative array that writes out variables required in the query
     *  execution.
     * @param array $filters
     *  Associative array mapping field to filter value. If the value is NULL,
     *  then the field is filtered using the expression IS NULL. If the value is
     *  TRUE, then the field is filtered using the expression IS NOT NULL.
     * @param string $tableAlias
     *  The name alias to use for the table. If none is provided, then actual
     *  table name is used.
     * @param bool $strict
     *  If strict, then non-existent fields throw an exception. Otherwise they
     *  are included with no qualifying table name.
     *
     * @return string
     *  Returns the generated SQL fragment.
     */
    public static function createFilterFragment(array &$vars,array $filters,$tableAlias = '',$strict = true) {
        if (empty($filters)) {
            return '';
        }

        $schema = self::getMetadata();

        if (empty($tableAlias)) {
            $tableAlias = $schema['table'];
        }

        $prefix = '';
        for ($i = 0;$i < 4;++$i) {
            $prefix .= chr(97 + rand(0,25));
        }
        $parts = [];
        $n = 1;

        foreach ($filters as $field => $value) {
            if (array_key_exists($field,$schema['props'])) {
                $ref = "`$tableAlias`.`{$schema['props'][$field]}`";
            }
            else if (array_key_exists($field,$schema['fields'])) {
                $ref = "`$tableAlias`.`$field`";
            }
            else if (!$strict) {
                $ref = $field;
            }
            else {
                throw new Exception("Field '$field' does not exist in Entity schema");
            }

            if ($value === true) {
                $parts[] = "$ref IS NOT NULL";
            }
            else if (is_null($value)) {
                $parts[] = "$ref IS NULL";
            }
            else if (is_array($value)) {
                if (empty($value)) {
                    continue;
                }

                $preps = [];
                foreach ($value as $val) {
                    $vv = "f{$prefix}_" . $n++;
                    $preps[] = ":$vv";
                    $vars[$vv] = $val;
                }
                $preps = implode(',',$preps);
                $parts[] = "$ref IN ($preps)";
            }
            else {
                $vv = "f{$prefix}_" . $n++;
                $parts[] = "$ref = :$vv";
                $vars[$vv] = $value;
            }
        }

        $fragment = implode(' AND ',$parts);

        return $fragment;
    }

    /**
     * Creates an ORDER BY SQL fragment.
     *
     * @param array $sortby
     *  List of fields to sort within. An item in this list may be one of the
     *  following:
     *   - An associative entry mapping field/prop name to boolean indicating
     *     whether the field is sorted in ascending order.
     *   - An indexed entry where the value is the field/prop name. The field
     *     will always be sorted in ascending order.
     *   - An indexed entry where the value is 2-array; the first element is the
     *     field/prop name and the second is a boolean indicating whether the
     *     field is sorted in ascending order.
     * @param string $tableAlias
     *  The name alias to use for the table. If none is provided, then actual
     *  table name is used.
     * @param bool $strict
     *  If strict, then non-existent fields throw an exception. Otherwise they
     *  are included with no qualifying table name.
     *
     * @return string
     *  Returns the generated SQL fragment.
     */
    public static function createSortByFragment(array $sortby,$tableAlias = '',$strict = true) {
        if (empty($sortby)) {
            return '';
        }

        $schema = self::getMetadata();

        if (empty($tableAlias)) {
            $tableAlias = $schema['table'];
        }

        $parts = [];

        foreach ($sortby as $key => $value) {
            if (is_array($value)) {
                list($field,$asc) = $value;
            }
            else if (is_string($key)) {
                $field = $key;
                $asc = $value;
            }
            else {
                $field = $value;
                $asc = true;
            }

            if (array_key_exists($field,$schema['props'])) {
                $ref = "`$tableAlias`.`{$schema['props'][$field]}`";
            }
            else if (array_key_exists($field,$schema['fields'])) {
                $ref = "`$tableAlias`.`$field`";
            }
            else if (!$strict) {
                $ref = $field;
            }
            else {
                throw new Exception("Field '$field' does not exist in Entity schema");
            }

            $suffix = $asc ? 'ASC' : 'DESC';
            $parts[] = "{$ref}{$suffix}";
        }

        $fragment = implode(', ',$parts);

        return $fragment;
    }

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
        $schema = self::getMetadata();

        if (isset($table)) {
            $schema['table'] = $table;
        }
        $keys = $this->initialize($schema);

        parent::__construct($conn,$schema['table'],$keys,in_array(null,$keys));
        $this->setFieldInfo($schema['fields'],$schema['props'],$schema['filters']);
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
        // Handle base cases (this function is called recursively).
        if (!$className || $className == 'TCCL\Database\ReflectionEntity') {
            return false;
        }

        // Hit cache.
        if (isset(self::$__schemaCache[$className])) {
            return self::$__schemaCache[$className];
        }

        // Load schema from metadata using doc-comment reflection.

        $rf = new ReflectionClass($className);
        $topLevelTags = self::parseDocTags($rf->getDocComment());
        $fields = self::loadSchemaFields($rf);

        if (!isset($topLevelTags['table'])) {
            return self::mergeSchema(
                $fields,
                self::loadSchema(get_parent_class($className))
            );
        }

        $schema = self::mergeSchema(
            ['table' => $topLevelTags['table']] + $fields,
            self::loadSchema(get_parent_class($className))
        );
        self::$__schemaCache[$className] = $schema;

        return $schema;
    }

    private static function loadSchemaFields(ReflectionClass $rf) {
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
            'fields' => $fields,
            'props' => $props,
            'filters' => $filters,
            'keys' => $keys,
        ];
    }

    private static function mergeSchema(array $schema,$with) {
        if (!is_array($with)) {
            if (!isset($schema['table'])) {
                return false;
            }

            return $schema;
        }

        // Merge table.
        if (!isset($schema['table'])) {
            $schema['table'] = $with['table'];
        }

        // Merge field metadata.
        $schema['fields'] += $with['fields'];
        $schema['props'] += $with['props'];
        $schema['filters'] += $with['filters'];
        $schema['keys'] += $with['keys'];

        return $schema;
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

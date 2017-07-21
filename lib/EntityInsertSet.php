<?php

/**
 * EntityInsertSet.php
 *
 * This file is a part of tccl/database.
 */

namespace TCCL\Database;

use PDO;
use Exception;
use ArrayAccess;
use Iterator;
use Countable;

/**
 * EntityInsertSet
 *
 * Provides a library for performing bulk INSERTs on Entity objects.
 */
class EntityInsertSet implements ArrayAccess, Iterator, Countable {
    /**
     * The type name of the Entity subclasses used in the object. This is
     * obtained from the first inserted Entity.
     *
     * @var string
     */
    private $type;

    /**
     * The list of Entities. They will go to war and march on Isengard.
     *
     * @var array (of Entity implementations)
     */
    private $ents;

    /**
     * The database connection instance to use.
     *
     * @var DatabaseConnection
     */
    private $conn;

    /**
     * Creates a new EntityInsertSet instance.
     *
     * @param DatabaseConnection $conn
     *  The database connection instance to user.
     * @param array $ents
     *  An optional list of Entity objects to serve as the initial set.
     */
    public function __construct(DatabaseConnection $conn,$ents = null) {
        if (isset($ents,$ents[0])) {
            // Make sure they all have the same type name.
            $this->type = get_class($ents[0]);
            for ($i = 1;$i < count($ents);++$ents) {
                if (get_class($ents[$i]) != $this->type) {
                    throw new Exception('EntityInsertSet::__construct(): all Entity elements must be of the same type');
                }
            }
            $this->ents = $ents;
        }

        $this->conn = $conn;
    }

    /**
     * Adds a new entity to the set.
     *
     * @param Entity $ent
     *  An instance of some subclass of type \TCCL\Database\Entity.
     */
    public function addEntity(Entity $ent) {
        if (!isset($this->type)) {
            $this->type = get_class($ent);
        }
        else if (get_class($ent) != $this->type) {
            throw new Exception("EntityInsertSet::addEntity(): any element must be of type '{$this->type}'");
        }

        $this->ents[] = $ent;
    }

    /**
     * Implements ArrayAccess::offsetExists().
     */
    public function offsetExists($offset) {
        return isset($this->ents[$offset]);
    }

    /**
     * Implements ArrayAccess::offsetGet().
     */
    public function offsetGet($offset) {
        return $this->ents[$offset];
    }

    /**
     * Implements ArrayAccess::offsetSet().
     */
    public function offsetSet($offset,$value) {
        if (!is_subclass_of($value,'\TCCL\Database\Entity')) {
            throw new Exception('EntityInsertSet::offsetSet(): the element must be a subclass of \TCCL\Database\Entity');
        }

        if (!isset($this->type)) {
            $this->type = get_class($value);
        }
        else if (get_class($value) != $this->type) {
            throw new Exception("EntityInsertSet::offsetSet(): any element must be of type '{$this->type}'");
        }

        $this->ents[] = $value;
    }

    /**
     * Implements ArrayAccess::offsetUnset().
     */
    public function offsetUnset($offset) {
        unset($this->ents[$offset]);
    }

    /**
     * Implements Iterator::current().
     */
    public function current() {
        return current($this->ents);
    }

    /**
     * Implements Iterator::key().
     */
    public function key() {
        if (!isset($this->ents)) {
            return;
        }
        return key($this->ents);
    }

    /**
     * Implements Iterator::next().
     */
    public function next() {
        next($this->ents);
    }

    /**
     * Implements Iterator::rewind().
     */
    public function rewind() {
        if (isset($this->ents)) {
            reset($this->ents);
        }
    }

    /**
     * Implements Iterator::valid().
     */
    public function valid() {
        return $this->offsetExists($this->key());
    }

    /**
     * Implements Countable::count().
     */
    public function count() {
        return count($this->ents);
    }

    /**
     * Performs a single INSERT query to insert the set of objects. This
     * operation assumes that each entity has the same fields to update; the
     * query will fail otherwise.
     *
     * @return bool
     *  Returns true if the insert was successful, false otherwise. Note that
     *  false may be returned if there were no entities to commit.
     */
    public function commit() {
        // Make sure we have entities to insert.
        if (empty($this->ents)) {
            return false;
        }

        // Get set of inserts and values.
        $values = [];
        $inserts = [];
        foreach ($this->ents as $entity) {
            $ins = $entity->getInserts($values);
            if ($ins === false) {
                continue;
            }

            $inserts += $ins;
        }

        // Make sure there are some fields to insert.
        if (count($inserts) == 0) {
            return false;
        }

        // Get prep string for query.
        $prepInner = '?' . str_repeat(',?',count($inserts)-1);
        $prepOuter = "($prepInner)" . str_repeat(",($prepInner)",count($this->ents)-1);

        // Perform the query.
        $table = $this->ents[0]->getTable();
        $fields = implode(',',array_keys($inserts));
        $this->conn->query("INSERT INTO $table ($fields) VALUES $prepOuter",$values);

        return true;
    }
}

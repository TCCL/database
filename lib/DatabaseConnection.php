<?php

/**
 * DatabaseConnection.php
 *
 * tccl/database
 */

namespace TCCL\Database;

use PDO;
use Exception;

/**
 * DatabaseConnection
 *
 * Represents a database connection. This provides a simple wrapper above PDO.
 */
class DatabaseConnection {
    /**
     * The map of active database connections in the application. We'll use this
     * to prevent redundant connection.
     *
     * @var array
     */
    static private $pdomap = array();

    /**
     * The map of transaction counters in the application, one per connection.
     *
     * @var array
     */
    static private $transactionCounters = array();

    /**
     * The key into $pdomap that gets the PDO instance.
     *
     * @var string
     */
    private $key;

    /**
     * Initializes the backing PDO object. All parameters are forwarded to the
     * PDO constructor.
     *
     * @param variable... $keys
     *  The parameters indicate keys within the GLOBALS array that store
     *  database credentials. The keyed element must be an indexed array
     *  containing the arguments to the PDO constructor (in the correct order).
     */
    public function __construct(/* $keys ... */) {
        $keys = func_get_args();
        if (count($keys) < 1) {
            throw new Exception(
                __METHOD__.': list of keys must contain at least 1 key');
        }
        $this->key = implode(':',$keys);

        if (!isset(self::$pdomap[$this->key])) {
            // Find the bucket based on the subkeys.
            $bucket = $GLOBALS;
            foreach ($keys as $k) {
                if (!isset($bucket[$k])) {
                    throw new Exception(
                        __METHOD__.": could not locate key '$k' under globals path");
                }
                $bucket = $bucket[$k];
            }

            self::$pdomap[$this->key] = $bucket;
            self::$transactionCounters[$this->key] = 0;
        }
    }

    /**
     * Creates a prepared statement, then executes it using the specified
     * arguments. If no arguments are specified, then the statement is not
     * executed.
     *
     * @param string $query
     *  The query, using prepared statement annotations as needed
     * @param mixed... $args
     *  If this argument is an array, then the array contents are treated as the
     *  parameters to the prepared statement, and any subsequent arguments are
     *  ignored. If this argument is a scalar, then it and any subsequent
     *  arguments are treated as parameters to the prepared statement. If this
     *  argument is null, then it is ignored and this method behaves just like
     *  rawQuery().
     *
     * @return PDOStatement
     *  The PDOStatement representing the prepared statement; NOTE: the
     *  statement will have already been executed.
     */
    public function query($query,$args = null) {
        if (is_array($args)) {
            // If the 'args' argument is an array, then use its contents as
            // parameters to the prepared statement.
            $stmt = $this->pdo()->prepare($query);
            if ($stmt !== false) {
                if ($stmt->execute($args) === false) {
                    throw new DatabaseException($stmt);
                }
            }
            else {
                throw new DatabaseException($this->pdo());
            }
        }
        else if (!is_null($args)) {
            // Treat arguments after the query string as parameters to the
            // prepared statement.
            $args = array_slice(func_get_args(),1);
            $stmt = $this->pdo()->prepare($query);
            if ($stmt !== false) {
                if ($stmt->execute($args) === false) {
                    throw new DatabaseException($stmt);
                }
            }
            else {
                throw new DatabaseException($this->pdo());
            }
        }
        else {
            $stmt = $this->pdo()->query($query);
            if ($stmt === false) {
                throw new DatabaseException($this->pdo());
            }
        }

        return $stmt;
    }

    /**
     * Executes a raw query without any preparations.
     *
     * @param string $query
     *  The SQL query
     *
     * @return PDOStatement
     */
    public function rawQuery($query) {
        $stmt = $this->pdo()->query($query);
        if ($stmt === false) {
            throw new DatabaseException($this->pdo());
        }

        return $stmt;
    }

    /**
     * Wraps PDO::prepare().
     *
     * @param string $query
     *  The SQL string
     *
     * @return PDOStatement
     */
    public function prepare($query) {
        $stmt = $this->pdo()->prepare($query);
        if ($stmt === false) {
            throw new DatabaseException($this->pdo());
        }

        return $stmt;
    }

    /**
     * Gets the underlying PDO backing object.
     *
     * @return PDO
     */
    public function getPDO() {
        return $this->pdo();
    }

    /**
     * Wraps PDO::beginTransaction() in a such a way that transactions are
     * counted and may be nested.
     */
    public function beginTransaction() {
        if (++self::$transactionCounters[$this->key] == 1) {
            $this->pdo()->beginTransaction();
        }
    }

    /**
     * Wraps PDO::rollback(). This will pop out of all transaction frames.
     */
    public function rollback() {
        if (self::$transactionCounters[$this->key] >= 1) {
            $this->pdo()->rollback();
            self::$transactionCounters[$this->key] = 0;
        }
    }

    /**
     * Wraps PDO::commit() in such a way that transactions are counted and may
     * be nested.
     */
    public function commit() {
        if (--self::$transactionCounters[$this->key] == 0) {
            $this->pdo()->commit();
        }
        if (self::$transactionCounters[$this->key] < 0) {
            self::$transactionCounters[$this->key] = 0;
        }
    }

    /**
     * Alias for commit() method.
     */
    public function endTransaction() {
        $this->commit();
    }

    /**
     * Wraps PDO::lastInsertId().
     */
    public function lastInsertId() {
        return $this->pdo()->lastInsertId();
    }

    /**
     * Gets the PDO object for this instance.
     *
     * @return PDO
     */
    private function pdo() {
        if (is_object(self::$pdomap[$this->key])) {
            return self::$pdomap[$this->key];
        }

        $bucket = self::$pdomap[$this->key];

        // Extract arguments and create PDO.
        @list($dsn,$user,$passwd,$options) = $bucket;
        $pdo = new PDO($dsn,$user,$passwd,$options);

        // Store connection in map and assign.
        self::$pdomap[$this->key] = $pdo;
        return $pdo;

    }
}

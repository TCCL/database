<?php

/**
 * DatabaseConnection.php
 *
 * This file is a part of tccl/database.
 */

namespace TCCL\Database;

use PDO;
use Exception;

class DatabaseConnection {
    /**
     * The map of active database connections in the application. We'll use this
     * to prevent redundant connection.
     */
    static private $pdomap = array();

    /**
     * The PDO object managing the database connection.
     */
    private $pdo;

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
        if (count($keys < 1)) {
            throw new Exception(
                __METHOD__.': list of keys must contain at least 1 key');
        }
        $key = $keys[count($keys)-1];

        if (!isset(self::$pdomap[$key])) {
            // Find the bucket based on the subkeys.
            $bucket = $GLOBALS;
            foreach ($keys as $k) {
                if (!isset($bucket[$k])) {
                    throw new Exception(
                        __METHOD__.": could not locate key '$k' under globals path");
                }
                $bucket = $bucket[$k];
            }

            // Extract arguments and create PDO.
            @list($dsn,$user,$passwd,$options) = $bucket;
            $pdo = new PDO($dsn,$user,$passwd,$options);

            // Store connection in map and assign.
            self::$pdomap[$key] = $pdo;
            $this->pdo = $pdo;
        }
        else {
            // Connection already exists: extract only.
            $this->pdo = self::$pdomap[$key];
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
            $result = $this->pdo->prepare($query);
            if ($result !== false) {
                if ($result->execute($args) === false) {
                    $error = $result->errorInfo();
                }
            }
            else {
                $error = $this->pdo->errorInfo();
            }
        }
        else if (!is_null($args)) {
            // Treat arguments after the query string as parameters to the
            // prepared statement.
            $args = array_slice(func_get_args(),1);
            $result = $this->pdo->prepare($query);
            if ($result !== false) {
                if ($result->execute($args) === false) {
                    $error = $result->errorInfo();
                }
            }
            else {
                $error = $this->pdo->errorInfo();
            }
        }
        else {
            $result = $this->pdo->query($query);
            if ($result === false) {
                $error = $this->pdo->errorInfo();
            }
        }

        if (isset($error)) {
            $message = is_null($error[2]) ? '' : ": {$error[2]}";
            throw new Exception(__METHOD__.": failed database query$message");
        }

        return $result;
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
        $stmt = $this->pdo->query($query);
        if ($stmt === false) {
            $error = $this->pdo->errorInfo();
            $message = is_null($error[2]) ? '' : ": {$error[2]}";
            throw new Exception(__METHOD__.": failed database query$message");            
        }

        return $stmt;
    }

    /**
     * Gets the underlying PDO backing object.
     *
     * @return PDO
     */
    public function getPDO() {
        return $this->pdo;
    }
}

<?php

/**
 * DatabaseException.php
 *
 * This file is a part of tccl/database.
 */

namespace TCCL\Database;

use PDO;
use PDOStatement;
use Exception;

/**
 * DatabaseException
 *
 * Exception type used to indicate PDO errors.
 */
class DatabaseException extends Exception {
    const MYSQL_ERROR_DUP_ENTRY = 1062;
    const MYSQL_ERROR_FAILED_FOREIGN_CONSTRAINT = 1452;

    /**
     * Creates a new DatabaseException instance.
     *
     * @param mixed $stmtOrPDO
     *  A PDO or PDOStatement instance from which the exception error
     *  information is derived.
     */
    public function __construct($stmtOrPDO) {
        if (!($stmtOrPDO instanceof PDO || $stmtOrPDO instanceof PDOStatement)) {
            throw new Exception('Parameter must be PDO or PDOStatement object');
        }

        $error = $stmtOrPDO->errorInfo();

        if (is_null($error[2])) {
            $message = 'An error occurred and the database query failed';
        }
        else {
            $message = "Failed database query: {$error[2]}";
        }

        parent::__construct($message,$error[1]);
    }

    /**
     * Determines if the exception resulted from a duplicate key error.
     *
     * @return bool
     */
    public function isDuplicateEntry() {
        return $this->getCode() == self::MYSQL_ERROR_DUP_ENTRY;
    }

    /**
     * Determines if the exception occurred as a result of a foreign key
     * constraint failure.
     *
     * @return bool
     */
    public function isFailedForeignKeyConstraint() {
        return $this->getCode() == self::MYSQL_ERROR_FAILED_FOREIGN_CONSTRAINT;
    }
}

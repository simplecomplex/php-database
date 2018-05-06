<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

use SimpleComplex\Database\Database;

/**
 * Database query interface.
 *
 * @package SimpleComplex\Database
 */
interface DbResultInterface
{
    /**
     * @param DbQueryInterface $query
     * @param mixed|null $connection
     * @param mixed|null $statement
     */
    public function __construct(DbQueryInterface $query, $connection, $statement);

    /**
     * Number of rows affected by a CRUD statement.
     *
     * @return int
     *      Throws throwable on failure.
     */
    public function affectedRows() : int;

    /**
     * Auto ID set by last insert statement.
     *
     * @param mixed|null $getAsType
     *
     * @return mixed|null
     *      Null: The query didn't trigger setting an ID.
     *      Throws throwable on failure.
     */
    public function insertId($getAsType = null);

    /**
     * Number of rows in a result set.
     *
     * @return int
     *      Throws throwable on failure.
     */
    public function numRows() : int;

    /**
     * Number of columns in a result row.
     *
     * @return int
     *      Throws throwable on failure.
     */
    public function numColumns() : int;

    /**
     * Associative (column-keyed) or numerically indexed array.
     *
     * @param int $as
     *      Default: column-keyed.
     *
     * @return array|null
     *      No more rows.
     *      Throws throwable on failure.
     */
    public function fetchArray(int $as = Database::FETCH_ASSOC);

    /**
     * Column-keyed object.
     *
     * @param string $class
     *      Optional class name.
     * @param array $args
     *      Optional constructor args.
     *
     * @return object|null
     *      No more rows.
     *      Throws throwable on failure.
     */
    public function fetchObject(string $class = '', array $args = []);

    /**
     * Fetch all rows into a list.
     *
     * Option 'list_by_column' is not supported when fetching as numerically
     * indexed arrays.
     *
     * @param int $as
     *      Default: column-keyed.
     * @param array $options {
     *      @var string $list_by_column  Key list by that column's values.
     *      @var string $class  Object class name.
     *      @var array $args  Object constructor args.
     * }
     *
     * @return array
     *      Throws throwable on failure.
     */
    public function fetchAll(int $as = Database::FETCH_ASSOC, array $options = []) : array;

    /**
     * Move cursor to next result set.
     *
     * @param bool $noException
     *      True: return false on failure, don't throw exception.
     *
     * @return bool|null
     *      Null: No next result set.
     *
     * @throws \Throwable
     */
    public function nextSet(bool $noException = false);

    /**
     * Go to next row in the result set.
     *
     * @param bool $noException
     *      True: return false on failure, don't throw exception.
     *
     * @return bool|null
     *      Null: No next row.
     *
     * @throws \Throwable
     */
    public function nextRow(bool $noException = false);

    /**
     * @return void
     */
    public function free() /*:void*/;
}

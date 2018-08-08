<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

use SimpleComplex\Database\DbResult;

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
     * @param string|null $getAsType
     *      Values: i|int|integer|d|float|s|string, default string.
     *
     * @return string|int|float|null
     *      Null: The query didn't trigger setting an ID.
     *      Throws throwable on failure.
     */
    public function insertId(string $getAsType = null);

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
     * Get value of a single column in a row.
     *
     * @param int $index
     * @param string $column
     *      Non-empty: fetch column by that name, ignore arg $index.
     *
     * @return mixed|null
     *      Null: No more rows.
     */
    public function fetchField(int $index = 0, string $column = '');

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
    public function fetchArray(int $as = DbResult::FETCH_ASSOC);

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
    public function fetchAll(int $as = DbResult::FETCH_ASSOC, array $options = []) : array;

    /**
     * Move cursor to next result set.
     *
     * @return bool
     *      False: No next result set.
     *      Throws throwable on failure.
     */
    public function nextSet() : bool;

    /**
     * Go to next row in the result set.
     *
     * @return bool
     *      False: No next row.
     *      Throws throwable on failure.
     *
     * @throws \Throwable
     */
    public function nextRow() : bool;

    /**
     * @return void
     */
    public function free() /*: void*/;
}

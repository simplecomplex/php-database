<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018-2019 Jacob Friis Mathiasen
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
     * @see MariaDbResult::__construct()
     * @see MsSqlResult::__construct()
     *
     * @param DbQueryInterface $query
     * @param mixed|null $connection
     * @param mixed|null $statement
     */
    public function __construct(DbQueryInterface $query, $connection, $statement);

    /**
     * Number of rows affected by a CRUD statement.
     *
     * @see MariaDbResult::affectedRows()
     * @see MsSqlResult::affectedRows()
     *
     * @return int
     *      Throws throwable on failure.
     */
    public function affectedRows() : int;

    /**
     * Auto ID set by last insert statement.
     *
     * @see MariaDbResult::insertId()
     * @see MsSqlResult::insertId()
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
     * @see MariaDbResult::numRows()
     * @see MsSqlResult::numRows()
     *
     * @return int
     *      Throws throwable on failure.
     */
    public function numRows() : int;

    /**
     * Number of columns in a result row.
     *
     * @see MariaDbResult::numColumns()
     * @see MsSqlResult::numColumns()
     *
     * @return int
     *      Throws throwable on failure.
     */
    public function numColumns() : int;

    /**
     * Fetch value of a single column in a single row.
     *
     * @see MariaDbResult::fetchField()
     * @see MsSqlResult::fetchField()
     *
     * @param int $index
     * @param string $name
     *      Non-empty: fetch column by that name, ignore arg $index.
     *
     * @return mixed|null
     *      Null: No more rows.
     */
    public function fetchField(int $index = 0, string $name = null);

    /**
     * Associative (column-keyed) or numerically indexed array.
     *
     * @see MariaDbResult::fetchArray()
     * @see MsSqlResult::fetchArray()
     *
     * @param int $as
     *      Default: column-keyed.
     *
     * @return array|null
     *      No more rows.
     *      Throws throwable on failure.
     */
    public function fetchArray(int $as = DbResult::FETCH_ASSOC) /*: ?array*/;

    /**
     * Column-keyed object.
     *
     * @see MariaDbResult::fetchObject()
     * @see MsSqlResult::fetchObject()
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
    public function fetchObject(string $class = null, array $args = null) /*: ?object*/;

    /**
     * Fetch value of a single column of all rows.
     *
     * @see MariaDbResult::fetchField()
     * @see MsSqlResult::fetchField()
     *
     * @param int $index
     * @param string $name
     *      Non-empty: fetch column by that name, ignore arg $index.
     * @param string $list_by_column
     *      Key list by that column's values; ignored if falsy arg $name.
     *
     * @return array
     *      Empty on no rows.
     *      Throws throwable on failure.
     */
    public function fetchFieldAll(int $index = 0, string $name = null, string $list_by_column = null) : array;

    /**
     * Fetch all rows into a list of associative (column-keyed) or numerically
     * indexed arrays.
     *
     * @see MariaDbResult::fetchArrayAll()
     * @see MsSqlResult::fetchArrayAll()
     *
     * @param int $as
     *      Default: ~associative.
     *      DbResult::FETCH_ASSOC|DbResult::FETCH_NUMERIC
     * @param string $list_by_column
     *      Key list by that column's values; illegal when $as:FETCH_NUMERIC.
     *      Empty: numerically indexed list.
     *
     * @return array
     *      Empty on no rows.
     *      Throws throwable on failure.
     */
    public function fetchArrayAll(int $as = DbResult::FETCH_ASSOC, string $list_by_column = null) : array;

    /**
     * Fetch all rows into a list of column-keyed objects.
     *
     * @see MariaDbResult::fetchObjectAll()
     * @see MsSqlResult::fetchObjectAll()
     *
     * @param string $class
     *      Optional class name; effective default stdClass.
     * @param string $list_by_column
     *      Key list by that column's values; illegal when $as:FETCH_NUMERIC.
     *      Empty: numerically indexed list.
     * @param array $args
     *      Optional constructor args.
     *
     * @return object[]
     *      Empty on no rows.
     *      Throws throwable on failure.
     */
    public function fetchObjectAll(string $class = null, string $list_by_column = null, array $args = null) : array;

    /**
     * Move cursor to next result set.
     *
     * @see MariaDbResult::nextSet()
     * @see MsSqlResult::nextSet()
     *
     * @return bool
     *      False: No next result set.
     *      Throws throwable on failure.
     */
    public function nextSet() : bool;

    /**
     * Traverse all remaining result sets.
     *
     * @see MariaDbResult::depleteSets()
     * @see MsSqlResult::depleteSets()
     *
     * In some case a DBMS may fail to reveal error(s)
     * until all sets have being accessed.
     *
     * @return void
     */
    public function depleteSets() /*: void*/;

    /**
     * Go to next row in the result set.
     *
     * @see MariaDbResult::nextRow()
     * @see MsSqlResult::nextRow()
     *
     * @return bool
     *      False: No next row.
     *      Throws throwable on failure.
     *
     * @throws \Throwable
     */
    public function nextRow() : bool;

    /**
     * Traverse all remaining rows in current result set.
     *
     * In some case a DBMS may fail to release resources
     * until all rows have been traversed.
     *
     * @see MariaDbResult::depleteRows()
     * @see MsSqlResult::depleteRows()
     *
     * @return void
     */
    public function depleteRows() /*: void*/;

    /**
     * Traverse all remaining result sets and rows.
     *
     * @see MariaDbResult::depleteAll()
     * @see MsSqlResult::depleteAll()
     *
     * @return void
     */
    public function depleteAll() /*: void*/;

    /**
     * @see MariaDbResult::free()
     * @see MsSqlResult::free()
     *
     * @return void
     */
    public function free() /*: void*/;
}

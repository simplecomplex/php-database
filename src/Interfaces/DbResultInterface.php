<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
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
     * @param mixed $statement
     */
    public function __construct(DbQueryInterface $query, $statement);

    /**
     * Number of rows affected by a CRUD statement.
     *
     * @return int
     */
    public function affectedRows() : int;

    /**
     * Auto ID set by last insert statement.
     *
     * @param mixed|null $getAsType
     *
     * @return mixed|null
     *      Null: no result at all.
     */
    public function insertId($getAsType = null);

    /**
     * Number of rows in a result set.
     *
     * @return int
     */
    public function numRows() : int;

    /**
     * Number of columns in a result row.
     *
     * @return int
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
     */
    public function fetchAll(int $as = Database::FETCH_ASSOC, array $options = []) : array;
}

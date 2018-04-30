<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

/**
 * Database query interface.
 *
 * @package SimpleComplex\Database
 */
interface DbQueryInterface
{
    /**
     * @see DbClientInterface::query()
     *
     * @param \SimpleComplex\Database\Interfaces\DbClientInterface $client
     *      Reference to parent client.
     * @param string $baseQuery
     * @param array $options {
     *      @var bool $is_multi_query
     *          True: arg $baseQuery contains multiple queries.
     * }
     *
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     */
    public function __construct(DbClientInterface $client, string $baseQuery, array $options = []);

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $types
     *      Empty: uses string for all.
     * @param array &$arguments
     *      By reference.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    public function prepare(string $types, array &$arguments) : DbQueryInterface;

    /**
     * Non-prepared statement: set query arguments, for native automated
     * parameter marker substitution or direct substition in the query.
     *
     * The base query remains reusable allowing more ->parameters()->execute(),
     * much like a prepared statement (except arguments aren't referred).
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $types
     *      Empty: uses string for all.
     * @param array $arguments
     *      Values to substitute query ?-parameters with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface;

    /**
     * Any query must be executed, even non-prepared statement.
     *
     * @return DbResultInterface
     */
    public function execute() : DbResultInterface;

    /**
     * Must unset prepared statement arguments reference.
     *
     * @return void
     */
    public function close();
}

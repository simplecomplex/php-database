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
     * @param \SimpleComplex\Database\Interfaces\DbClientInterface $client
     *      Reference to parent client.
     * @param string $baseQuery
     * @param bool $isMulti
     *      True: arg $baseQuery contains multiple queries.
     *
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     */
    public function __construct(DbClientInterface $client, string $baseQuery, bool $isMulti = false);

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
     * @param array $options
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    public function prepareStatement(string $types, array &$arguments, array $options = []) : DbQueryInterface;

    /**
     * Substitute base query ?-parameters by arguments.
     *
     * Non-prepared statement only.
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
     * Execute simple query, multi-query or prepared statement.
     *
     * @return DbResultInterface
     */
    public function execute() : DbResultInterface;

    /**
     * @return void
     */
    public function closePreparedStatement();
}

<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

/**
 * Database query interface.
 *
 *
 * Multi-query versus batch query
 * ------------------------------
 * A batch query contains more non-selecting queries.
 * A multi-query contains more selecting queries; producing result set.
 * Batch query is supported by all common RMDSs.
 * Multi-query is supported by MariaDB/MySQL and Postgresql.
 *
 *
 * @property-read string $id
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
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
     * @param string $sql
     * @param array $options
     *
     * @throws \InvalidArgumentException
     *      Arg $sql empty.
     */
    public function __construct(DbClientInterface $client, string $sql, array $options = []);

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * Chainable.
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
     * parameter marker substitution or direct substition in the sql.
     *
     * The base sql remains reusable allowing more ->parameters()->execute(),
     * much like a prepared statement (except arguments aren't referred).
     *
     * Chainable.
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
     *      Values to substitute sql parameter markers with.
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

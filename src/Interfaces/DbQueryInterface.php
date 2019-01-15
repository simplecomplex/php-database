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
     * @see MariaDbQuery::__construct()
     * @see MsSqlQuery::__construct()
     *
     * @param \SimpleComplex\Database\Interfaces\DbClientInterface $client
     *      Reference to parent client.
     * @param string $sql
     * @param array $options {
     *      @var bool|int $reusable
     * }
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
     * Types, at least (more types may be supported):
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @see MariaDbQuery::prepare()
     * @see MsSqlQuery::prepare()
     *
     * @param string $types
     *      Empty: uses $arguments' actual types.
     * @param array &$arguments
     *      By reference.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \LogicException
     *      Method called more than once for this query.
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    public function prepare(string $types, array &$arguments) : DbQueryInterface;

    /**
     * Non-prepared statement: set query arguments, for native automated
     * parameter marker substitution or direct substition in the sql.
     *
     * The base sql remains reusable - if option reusable - allowing more
     * ->parameters()->execute(), much like a prepared statement
     * (except arguments aren't referred).
     *
     * Chainable.
     *
     * Types, at least (more types may be supported):
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @see MariaDbQuery::parameters()
     * @see MsSqlQuery::parameters()
     *
     * @param string $types
     *      Empty: uses $arguments' actual types.
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
     * @see MariaDbQuery::execute()
     * @see MsSqlQuery::execute()
     *
     * @return DbResultInterface
     *
     * @throws \LogicException
     *      Repeated execution of simple query without truthy option reusable
     *      and intermediate call to parameters().
     */
    public function execute() : DbResultInterface;

    /**
     * Must unset prepared statement arguments reference.
     *
     * @see MariaDbQuery::close()
     * @see MsSqlQuery::close()
     *
     * @return void
     */
    public function close();
}

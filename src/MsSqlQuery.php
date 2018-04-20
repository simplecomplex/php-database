<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbResultInterface;

use SimpleComplex\Database\Exception\DbLogicalException;
use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbInterruptionException;
use SimpleComplex\Database\Exception\DbQueryException;

/**
 * MS SQL query.
 *
 * @property-read string $query
 * @property-read bool $isMultiQuery
 * @property-read bool $hasLikeClause
 * @property-read bool $isPreparedStatement
 * @property-read int $nParameters
 *
 * @package SimpleComplex\Database
 */
class MsSqlQuery extends AbstractDbQuery
{
    /**
     * Class name of \SimpleComplex\Database\MsSqlResult or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_RESULT = \Package\Library\CustomMsSqlResult::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MsSqlResult
     *
     * @var string
     */
    const CLASS_RESULT = MsSqlResult::class;

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var MsSqlClient
     */
    public $client;

    /**
     * @var resource
     */
    protected $simpleStatement;

    /**
     * @var resource
     */
    protected $preparedStatement;

    /**
     * @var string
     */
    protected $preparedStatementTypes;

    /**
     * @param MsSqlClient|DbClientInterface $client
     *      Reference to parent client.
     * @param string $query
     *
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     */
    public function __construct(DbClientInterface $client, string $query)
    {
        $this->client = $client;
        if (!$query) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' arg $query cannot be empty'
            );
        }
        // Remove trailing semicolon.
        $this->query = rtrim($query, ';');
    }

    public function __destruct()
    {
        if ($this->preparedStatement) {
            @sqlsrv_free_stmt($this->preparedStatement);
        }
    }

    /**
     * Not supported by this type of database client.
     *
     * @param string $types
     * @param array $arguments
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      MS SQL (at least Sqlsrv extension) doesn't support multi-query.
     */
    public function multiQueryParameters(string $types, array $arguments) : DbQueryInterface
    {
        throw new DbLogicalException(
            $this->client->errorMessagePreamble() . ' doesn\'t support multi-query.'
        );
    }

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * @param string $types
     *      Ignored; Sqlsrv parameter binding too weird.
     * @param array &$arguments
     *      By reference.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws DbLogicalException
     *      Method called more than once for this query.
     * @throws DbRuntimeException
     *      Failure to bind $arguments to native layer.
     */
    public function prepareStatement(string $types, array &$arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' query cannot prepare statement more than once.'
            );
        }

        // Allow re-connection.
        $connection = $this->client->getConnection(true);

        $this->preparedStatementArgs =& $arguments;

        /** @var resource $statement */
        $statement = @sqlsrv_prepare($connection, $this->query, $this->preparedStatementArgs);
        if (!$statement) {
            unset($this->preparedStatementArgs);
            throw new DbRuntimeException(
                $this->client->errorMessagePreamble()
                . ' query failed to prepare statement and bind parameters, with error: '
                . $this->client->getNativeError() . '.'
            );
        }
        $this->preparedStatement = $statement;
        $this->isPreparedStatement = true;

        return $this;
    }

    /**
     * Only
     *
     * @return DbResultInterface|MsSqlResult
     *
     * @throws DbLogicalException
     *      Method called without previous call to prepareStatement().
     * @throws DbQueryException
     */
    public function execute(): DbResultInterface
    {
        if ($this->isPreparedStatement) {
            // Require unbroken connection.
            if (!$this->client->isConnected()) {
                throw new DbInterruptionException(
                    $this->client->errorMessagePreamble()
                    . ' query can\'t execute prepared statement when connection lost.'
                );
            }
            if (!@sqlsrv_execute($this->preparedStatement)) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' failed executing prepared statement, query',
                    $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' failed executing prepared statement, with error: ' . $this->client->getNativeError() . '.'
                );
            }
        }
        else {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $connection = $this->client->getConnection(true);
            $simple_statement = @sqlsrv_query($connection, $this->queryWithArguments ?? $this->query);
            if (!$simple_statement) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' failed executing simple query, query',
                    $this->queryWithArguments ?? $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' failed executing simple query, with error: ' . $this->client->getNativeError() . '.'
                );
            }
            $this->simpleStatement = $simple_statement;
        }

        return new MsSqlResult();
    }

    /**
     * @return void
     */
    public function closePreparedStatement()
    {
        if (!$this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' query isn\'t a prepared statement.'
            );
        }
        if ($this->client->isConnected() && $this->preparedStatement) {
            @sqlsrv_free_stmt($this->preparedStatement);
            unset($this->preparedStatement, $this->preparedStatementArgs);
        }
    }
}

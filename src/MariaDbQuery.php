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
 * Maria DB query.
 *
 * @property-read string $query
 * @property-read bool $isMultiQuery
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read int $nParameters
 *
 * @package SimpleComplex\Database
 */
class MariaDbQuery extends AbstractDbQuery
{
    /**
     * Class name of \SimpleComplex\Database\MariaDbResult or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_RESULT = \Package\Library\CustomMariaDbResult::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MariaDbResult
     *
     * @var string
     */
    const CLASS_RESULT = MariaDbResult::class;

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var MariaDbClient
     */
    public $client;

    /**
     * @var \mysqli_stmt
     */
    protected $preparedStatement;

    /**
     * @param MariaDbClient|DbClientInterface $client
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
        // Remove trailing semicolon; for multi-query.
        $this->query = rtrim($query, ';');
    }

    public function __destruct()
    {
        if ($this->preparedStatement) {
            @$this->preparedStatement->close();
        }
    }

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * @param string $types
     *      i: integer.
     *      d: float (double).
     *      s: string.
     *      b: blob.
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
                $this->client->errorMessagePreamble()
                . ' query cannot prepare statement more than once.'
            );
        }

        // Allow re-connection.
        $mysqli = $this->client->getConnection(true);

        /** @var \mysqli_stmt $mysqli_stmt */
        $mysqli_stmt = @$mysqli->prepare($this->query);
        if (!$mysqli_stmt) {
            throw new DbRuntimeException(
                $this->client->errorMessagePreamble()
                . ' query failed to prepare statement, with error: ' . $this->client->getNativeError() . '.'
            );
        }

        $this->preparedStatementArgs =& $arguments;

        if (!@$mysqli_stmt->bind_param($types, ...$this->preparedStatementArgs)) {
            unset($this->preparedStatementArgs);
            throw new DbRuntimeException(
                $this->client->errorMessagePreamble()
                . ' query failed to bind parameters prepare statement, with error: '
                . $this->client->getNativeError() . '.'
            );
        }
        $this->preparedStatement = $mysqli_stmt;
        $this->isPreparedStatement = true;

        return $this;
    }

    /**
     * @return DbResultInterface|MariaDbResult
     *
     * @throws DbInterruptionException
     *      Is prepared statement and connection lost.
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
            if (!@$this->preparedStatement->execute()) {
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
        elseif ($this->isMultiQuery) {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection(true);
            if (!@$mysqli->multi_query($this->queryWithArguments ?? $this->query)) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' failed executing multi-query, query',
                    $this->queryWithArguments ?? $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' failed executing multi-query, with error: ' . $this->client->getNativeError() . '.'
                );
            }
        }
        else {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection(true);
            if (!@$mysqli->real_query($this->queryWithArguments ?? $this->query)) {
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
        }

        $class_result = static::CLASS_RESULT;
        /** @var DbResultInterface|MariaDbResult */
        return new $class_result();
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
            @$this->preparedStatement->close();
            unset($this->preparedStatement, $this->preparedStatementArgs);
        }
    }

    /**
     * Parameter value escaper.
     *
     * Escapes %_ unless instance var hasLikeClause.
     *
     * Replaces semicolon with comma if multi-query.
     *
     * @param string $str
     *
     * @return string
     */
    public function escapeString(string $str) : string
    {
        $s = $str;
        if ($this->isMultiQuery) {
            $s = str_replace(';', ',', $s);
        }

        // Allow re-connection.
        $s = $this->client->getConnection(true)
            ->real_escape_string($s);

        return $this->hasLikeClause ? $s : addcslashes($s, '%_');
    }
}

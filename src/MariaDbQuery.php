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
use SimpleComplex\Database\Exception\DbLogicalException;
use SimpleComplex\Database\Exception\DbRuntimeException;

/**
 * Maria DB query.
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
            throw new \InvalidArgumentException('Arg $query cannot be empty');
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
            throw new DbLogicalException('Database query cannot prepare statement more than once.');
        }

        // Allow re-connection.
        $mysqli = $this->client->getConnection(true);

        /** @var \mysqli_stmt $mysqli_stmt */
        $mysqli_stmt = @$mysqli->prepare($this->query);
        if (!$mysqli_stmt) {
            throw new DbRuntimeException(
                'Database query failed to prepare statement, with error: ' . $this->client->getNativeError() . '.'
            );
        }

        $this->preparedStatementArgs =& $arguments;

        if (!$mysqli_stmt->bind_param($types, ...$this->preparedStatementArgs)) {
            unset($this->preparedStatementArgs);
            throw new DbRuntimeException(
                'Database query failed to bind parameters prepare statement, with error: '
                . $this->client->getNativeError() . '.'
            );
        }
        $this->preparedStatement = $mysqli_stmt;
        $this->isPreparedStatement = true;

        return $this;
    }

    /**
     * @return void
     */
    public function closePreparedStatement()
    {
        if (!$this->isPreparedStatement) {
            throw new DbLogicalException('Database query isn\'t a prepared statement.');
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

<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbResultInterface;

use SimpleComplex\Database\Exception\DbLogicalException;
use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbInterruptionException;
use SimpleComplex\Database\Exception\DbQueryException;

/**
 * Maria DB query.
 *
 * Multi-query is supported by Maria DB.
 * @see DatabaseQuery
 *
 *
 * NB: Prepared statement requires the mysqlnd driver.
 * Because a result set will eventually be handled as \mysqli_result
 * via mysqli_stmt::get_result(); only available with mysqlnd.
 * @see http://php.net/manual/en/mysqli-stmt.get-result.php
 *
 * @property-read bool $isPreparedStatement
 * @property-read bool $isMultiQuery
 * @property-read bool $isRepeatStatement
 * @property-read bool $queryAppended
 * @property-read bool $hasLikeClause
 * @property-read string $query
 * @property-read string $queryTampered
 * @property-read array $arguments
 *
 * @package SimpleComplex\Database
 */
class MariaDbQuery extends DatabaseQuery
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
     * Prepared or simple statement.
     *
     * A simple statement might not be linked at all (MySQLi).
     *
     * @var \mysqli_stmt|null
     */
    protected $statement;

    public function __destruct()
    {
        if ($this->statement) {
            @$this->statement->close();
        }
    }

    /**
     * Turn query into server-side prepared statement and bind parameters.
     *
     * NB: Requires the mysqlnd driver.
     * Because a result set will eventually be handled as \mysqli_result
     * via mysqli_stmt::get_result(); only available with mysqlnd.
     * @see http://php.net/manual/en/mysqli-stmt.get-result.php
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
     * @throws DbLogicalException
     *      Method called more than once for this query.
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     * @throws DbRuntimeException
     *      Failure to bind $arguments to native layer.
     */
    public function prepare(string $types, array &$arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' - query cannot prepare statement more than once.'
            );
        }
        $this->isPreparedStatement = true;

        // Checks for parameters/arguments count mismatch.
        $query_fragments = $this->queryFragments($this->query, $arguments);
        $n_params = count($query_fragments) - 1;
        unset($query_fragments);

        $tps = $types;
        if ($n_params) {
            if ($tps === '') {
                // Be friendly, all strings.
                $tps = str_repeat('s', $n_params);
            }
            elseif (strlen($types) != $n_params) {
                throw new \InvalidArgumentException(
                    $this->client->errorMessagePreamble() . ' - arg $types length[' . strlen($types)
                    . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
                );
            }
            elseif (($type_illegals = $this->parameterTypesCheck($types))) {
                throw new \InvalidArgumentException(
                    $this->client->errorMessagePreamble()
                    . ' - arg $types contains illegal char(s) ' . $type_illegals . '.'
                );
            }
        }

        // Allow re-connection.
        $mysqli = $this->client->getConnection(true);

        /** @var \mysqli_stmt|bool $mysqli_stmt */
        $mysqli_stmt = @$mysqli->prepare($this->query);
        if (!$mysqli_stmt) {
            throw new DbRuntimeException(
                $this->client->errorMessagePreamble()
                . ' - query failed to prepare statement, with error: ' . $this->client->nativeError() . '.'
            );
        }

        if ($n_params) {
            $this->arguments['prepared'] =& $arguments;

            if (!@$mysqli_stmt->bind_param($tps, ...$this->arguments['prepared'])) {
                $this->unsetReferences();
                throw new DbRuntimeException(
                    $this->client->errorMessagePreamble()
                    . ' - query failed to bind parameters prepare statement, with error: '
                    . $this->client->nativeError() . '.'
                );
            }
        }
        $this->statement = $mysqli_stmt;

        return $this;
    }

    /**
     * Any query must be executed, even non-prepared statement.
     *
     * @return DbResultInterface|MariaDbResult
     *
     * @throws DbLogicalException
     *      Is prepared statement and the statement is previously closed.
     * @throws DbInterruptionException
     *      Is prepared statement and connection lost.
     * @throws DbQueryException
     */
    public function execute(): DbResultInterface
    {
        if ($this->isPreparedStatement) {
            // (MySQLi) Only a prepared statement is a 'statement'.
            if ($this->statementClosed) {
                throw new DbLogicalException(
                    $this->client->errorMessagePreamble()
                    . ' - query can\'t execute previously closed prepared statement.'
                );
            }
            // Require unbroken connection.
            if (!$this->client->isConnected()) {
                $this->unsetReferences();
                throw new DbInterruptionException(
                    $this->client->errorMessagePreamble()
                    . ' - query can\'t execute prepared statement when connection lost.'
                );
            }
            // bool.
            if (!@$this->statement->execute()) {
                $this->unsetReferences();
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' - failed executing prepared statement, query',
                    $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' - failed executing prepared statement, with error: ' . $this->client->nativeError() . '.'
                );
            }
        }
        elseif ($this->isMultiQuery) {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection(true);
            // bool.
            if (!@$mysqli->multi_query($this->queryTampered ?? $this->query)) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' - failed executing multi-query',
                    $this->queryTampered ?? $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' - failed executing multi-query, with error: ' . $this->client->nativeError() . '.'
                );
            }
        }
        else {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection(true);
            // bool.
            if (!@$mysqli->real_query($this->queryTampered ?? $this->query)) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' - failed executing simple query',
                    $this->queryTampered ?? $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' - failed executing simple query, with error: ' . $this->client->nativeError() . '.'
                );
            }
        }

        $class_result = static::CLASS_RESULT;
        /** @var DbResultInterface|MariaDbResult */
        return new $class_result($this);
    }

    /**
     * @see DatabaseQuery::$statementClosed
     *
     * @return void
     */
    public function closeStatement()
    {
        $this->statementClosed = true;
        $this->unsetReferences();
        if ($this->statement) {
            @$this->statement->close();
            $this->statement = null;
        }
    }

    /**
     * @return void
     */
    public function freeResult()
    {
         // @todo: free_result()
    }


    // Helpers.-----------------------------------------------------------------

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

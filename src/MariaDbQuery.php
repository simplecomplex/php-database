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
 * @property-read string $queryWithArguments
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
     * @var \mysqli_stmt
     */
    protected $preparedStatement;

    public function __destruct()
    {
        if ($this->preparedStatement) {
            @$this->preparedStatement->close();
        }
    }

    /**
     * Turn query into prepared statement and bind parameters.
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
     *      Arg $types contains illegal char(s).
     * @throws DbRuntimeException
     *      Failure to bind $arguments to native layer.
     */
    public function prepareStatement(string $types, array &$arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' - query cannot prepare statement more than once.'
            );
        }

        $fragments = explode('?', $this->query);
        $n_params = count($fragments) - 1;
        unset($fragments);
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' - arg $arguments length[' . $n_args
                . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
            );
        }

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
            $this->preparedStatementArgs =& $arguments;

            if (!@$mysqli_stmt->bind_param($tps, ...$this->preparedStatementArgs)) {
                unset($this->preparedStatementArgs);
                throw new DbRuntimeException(
                    $this->client->errorMessagePreamble()
                    . ' - query failed to bind parameters prepare statement, with error: '
                    . $this->client->nativeError() . '.'
                );
            }
        }
        $this->preparedStatement = $mysqli_stmt;
        $this->isPreparedStatement = true;

        return $this;
    }

    /**
     * Any query must be executed, even non-prepared statement.
     *
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
                unset($this->preparedStatementArgs);
                throw new DbInterruptionException(
                    $this->client->errorMessagePreamble()
                    . ' - query can\'t execute prepared statement when connection lost.'
                );
            }
            // bool.
            if (!@$this->preparedStatement->execute()) {
                unset($this->preparedStatementArgs);
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
            if (!@$mysqli->multi_query($this->queryWithArguments ?? $this->query)) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' - failed executing multi-query, query',
                    $this->queryWithArguments ?? $this->query
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
            if (!@$mysqli->real_query($this->queryWithArguments ?? $this->query)) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' - failed executing simple query, query',
                    $this->queryWithArguments ?? $this->query
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
     * @return void
     */
    public function closeStatement()
    {
        /**
         * @todo: see MsSqlQuery::closeStatement()
         * @see MsSqlQuery::closeStatement()
         */
        unset($this->preparedStatementArgs);
        if (!$this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' - query isn\'t a prepared statement.'
            );
        }
        if ($this->client->isConnected() && $this->preparedStatement) {
            @$this->preparedStatement->close();
            unset($this->preparedStatement);
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

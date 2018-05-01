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
 * Own read-onlys:
 * @property-read string $cursorMode
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
     * MySQL (MySQLi) supports multi-query.
     *
     * @var bool
     */
    const MULTI_QUERY_SUPPORT = true;

    /**
     * Result set cursor modes.
     *
     * @see http://php.net/manual/en/mysqli.use-result.php
     *
     * Store vs. use at Stackoverflow:
     * @see https://stackoverflow.com/questions/9876730/mysqli-store-result-vs-mysqli-use-result
     *
     * @var int[]
     */
    const CURSOR_MODES = [
        'use',
        'store',
    ];

    /**
     * Default result set cursor mode.
     *
     * 'use':
     * - heavy serverside, light clientside
     * - doesn't allow getting number of rows until all rows have been retrieved
     *
     * This class' default is 'store':
     * - light serverside, heavy clientside
     * - we like getting number of rows
     *
     * @var string
     */
    const CURSOR_MODE_DEFAULT = 'store';

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var MariaDbClient
     */
    public $client;

    /**
     * Prepared statement only.
     *
     * @var \mysqli_stmt|null
     *      Overriding to annotate type.
     */
    protected $statement;

    /**
     * Option (str) cursor_mode.
     *
     * Will always be 'store' when making a stored procedure.
     * @see \mysqli_stmt::get_result()
     *
     * @see MariaDbQuery::CURSOR_MODES
     * @see MariaDbQuery::CURSOR_MODE_DEFAULT
     *
     * @var string
     */
    protected $cursorMode;

    /**
     * @param DbClientInterface|DatabaseClient|MariaDbClient $client
     *      Reference to parent client.
     * @param string $baseQuery
     * @param array $options {
     *      @var string $cursor_mode
     *          Ignored if making prepared statement; will always be 'store'.
     * }
     *
     * @throws \InvalidArgumentException
     *      Propagated.
     *      Unsupported 'cursor_mode'.
     */
    public function __construct(DbClientInterface $client, string $baseQuery, array $options = [])
    {
        parent::__construct($client, $baseQuery, $options);

        if (!empty($options['cursor_mode'])) {
            if (!in_array($options['cursor_mode'], static::CURSOR_MODES, true)) {
                throw new DbLogicalException(
                    $this->client->errorMessagePrefix()
                    . ' query option \'cursor_mode\' value[' . $options['cursor_mode'] . '] is invalid.'
                );
            }
            $this->cursorMode = $options['cursor_mode'];
        } else {
            $this->cursorMode = static::CURSOR_MODE_DEFAULT;
        }
        $this->explorableIndex[] = 'cursorMode';
    }

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
     * Supports that arg $arguments is associative array.
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
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            throw new DbLogicalException(
                $this->client->errorMessagePrefix() . ' - query cannot prepare statement more than once.'
            );
        }
        $this->isPreparedStatement = true;
        // Prepared statement's result will be store'd
        // - via \mysqli_stmt::get_result() - because \mysqli_stmt misses
        // result methods like fetch_array().
        $this->cursorMode = 'store';

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
                    $this->client->errorMessagePrefix() . ' - arg $types length[' . strlen($types)
                    . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
                );
            }
            elseif (($type_illegals = $this->parameterTypesCheck($types))) {
                throw new \InvalidArgumentException(
                    $this->client->errorMessagePrefix()
                    . ' - arg $types contains illegal char(s) ' . $type_illegals . '.'
                );
            }
        }

        // Allow re-connection.
        $mysqli = $this->client->getConnection(true);

        /** @var \mysqli_stmt|bool $mysqli_stmt */
        $mysqli_stmt = @$mysqli->prepare($this->query);
        if (!$mysqli_stmt) {
            $this->log(__FUNCTION__);
            throw new DbRuntimeException(
                $this->errorMessagePrefix()
                . ' - query failed to prepare statement, with error: ' . $this->client->nativeError() . '.'
            );
        }

        if ($n_params) {
            // Support assoc array; \mysqli_stmt::bind_param() doesn't.
            if ($arguments && !ctype_digit('' . join(array_keys($arguments)))) {
                $args = [];
                foreach ($arguments as &$arg) {
                    $args[] =& $arg;
                }
                unset($arg);
                $this->arguments['prepared'] =& $args;
            } else {
                $this->arguments['prepared'] =& $arguments;
            }

            if (!@$mysqli_stmt->bind_param($tps, ...$this->arguments['prepared'])) {
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $this->log(__FUNCTION__);
                throw new DbRuntimeException(
                    $this->errorMessagePrefix()
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
                    $this->client->errorMessagePrefix()
                    . ' - query can\'t execute previously closed prepared statement.'
                );
            }
            // Require unbroken connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection();
            if (!$mysqli) {
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                throw new DbInterruptionException(
                    $this->client->errorMessagePrefix()
                    . ' - query can\'t execute prepared statement when connection lost.'
                );
            }
            // bool.
            if (!@$this->statement->execute()) {
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $this->log(__FUNCTION__);
                throw new DbQueryException(
                    $this->errorMessagePrefix()
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
                $this->log(__FUNCTION__);
                throw new DbQueryException(
                    $this->errorMessagePrefix()
                    . ' - failed executing multi-query, with error: ' . $this->client->nativeError() . '.'
                );
            }
        }
        // @todo: a query containing more non-SELECT statements (like INSERT...; SELECT...) must probably also be executed as multi-query.
        else {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection(true);
            // bool.
            if (!@$mysqli->real_query($this->queryTampered ?? $this->query)) {
                $this->log(__FUNCTION__);
                throw new DbQueryException(
                    $this->errorMessagePrefix()
                    . ' - failed executing simple query, with error: ' . $this->client->nativeError() . '.'
                );
            }
        }

        $class_result = static::CLASS_RESULT;
        /** @var DbResultInterface|MariaDbResult */
        return new $class_result($this, $mysqli, $this->statement);
    }

    /**
     * @see DatabaseQuery::$statementClosed
     *
     * @see DatabaseQuery::unsetReferences()
     *
     * @return void
     */
    public function close()
    {
        $this->statementClosed = true;
        $this->unsetReferences();
        if ($this->statement) {
            @$this->statement->close();
            $this->statement = null;
        }
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

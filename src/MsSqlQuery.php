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
 * Multi-query - multiple non-CRUD statements - is NOT supported by MS SQL.
 * @todo: is this correct?
 * @see DatabaseQuery
 *
 * Inherited read-onlys:
 * @property-read bool $isPreparedStatement
 * @property-read bool $isMultiQuery
 * @property-read bool $isRepeatStatement
 * @property-read bool $queryAppended
 * @property-read bool $hasLikeClause
 * @property-read string $query
 * @property-read string $queryTampered
 *
 * Own read-onlys:
 * @property-read int $queryTimeout
 * @property-read string $cursorMode
 * @property-read bool $sendDataChunked
 *
 * @package SimpleComplex\Database
 */
class MsSqlQuery extends DatabaseQuery
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
     * MS SQL (Sqlsrv) does not support multi-query.
     *
     * @var bool
     */
    const MULTI_QUERY_SUPPORT = false;

    /**
     * Default query timeout.
     *
     * Zero means no timeout; waits forever.
     *
     * @var int
     */
    const QUERY_TIMEOUT = 0;

    /**
     * Result set cursor modes.
     *
     * Scrollable:
     * @see http://php.net/manual/en/function.sqlsrv-query.php
     *
     * Sqlsrv Cursor Types:
     * @see https://docs.microsoft.com/en-us/sql/connect/php/cursor-types-sqlsrv-driver
     *
     * @var int[]
     */
    const CURSOR_MODES = [
        SQLSRV_CURSOR_FORWARD,
        SQLSRV_CURSOR_STATIC,
        SQLSRV_CURSOR_DYNAMIC,
        SQLSRV_CURSOR_KEYSET,
    ];

    /**
     * Default result set cursor mode.
     *
     * Sqlsrv default is 'forward':
     * - fast
     * - reflects changes serverside
     * - doesn't allow getting number of rows
     *
     * This class' default is 'static':
     * - slower
     * - we don't want serverside changes to reflect the result set
     * - we like getting number of rows
     *
     * @var string
     */
    const CURSOR_MODE_DEFAULT = SQLSRV_CURSOR_STATIC;

    /**
     * @var int
     */
    const SEND_CHUNKS_LIMIT = 1000;

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
    protected $preparedStatement;

    /**
     * @var resource
     */
    protected $simpleStatement;

    /**
     * @var string
     */
    protected $preparedStatementTypes;

    /**
     * @see MsSqlQuery::QUERY_TIMEOUT
     *
     * @var int
     */
    protected $queryTimeout;

    /**
     * @see MsSqlQuery::CURSOR_MODES
     * @see MsSqlQuery::CURSOR_MODE_DEFAULT
     *
     * @var string
     */
    protected $cursorMode;

    /**
     * Send query statement data in chunks instead sending all immediately.
     *
     * Relevant if giant query.
     *
     * Native setting 'SendStreamParamsAtExec'; opposite boolean value.
     * @see http://php.net/manual/en/function.sqlsrv-send-stream-data.php
     *
     * @var bool
     */
    protected $sendDataChunked = false;

    /**
     * @var int
     */
    protected $sendChunksLimit;

    /**
     * @param DbClientInterface|DatabaseClient|MsSqlClient $client
     *      Reference to parent client.
     * @param string $baseQuery
     * @param array $options {
     *      @var int $query_timeout
     *      @var string $cursor_mode
     *      @var bool $send_data_chunked
     *      @var int $send_chunks_limit
     * }
     *
     * @throws \InvalidArgumentException
     *      Propagated.
     *      Unsupported 'cursor_mode'.
     */
    public function __construct(DbClientInterface $client, string $baseQuery, array $options = [])
    {
        parent::__construct($client, $baseQuery, $options);

        if (isset($options['query_timeout'])) {
            $this->queryTimeout = $options['query_timeout'];
            if ($this->queryTimeout < 0) {
                $this->queryTimeout = 0;
            }
        } else {
            $this->queryTimeout = static::QUERY_TIMEOUT;
        }
        $this->explorableIndex[] = 'queryTimeout';

        if (!empty($options['cursor_mode'])) {
            if (!in_array($options['cursor_mode'], static::CURSOR_MODES, true)) {
                throw new DbLogicalException(
                    $this->client->errorMessagePreamble()
                    . ' query option \'cursor_mode\' value[' . $options['cursor_mode'] . '] is invalid.'
                );
            }
            $this->cursorMode = $options['cursor_mode'];
        } else {
            $this->cursorMode = static::CURSOR_MODE_DEFAULT;
        }
        $this->explorableIndex[] = 'cursorMode';

        if (!empty($options['send_data_chunked'])) {
            $this->sendDataChunked = true;
            $this->sendChunksLimit = $options['send_chunks_limit'] ?? static::SEND_CHUNKS_LIMIT;
        }
        $this->explorableIndex[] = 'sendDataChunked';
        $this->explorableIndex[] = 'sendChunksLimit';
    }

    public function __destruct()
    {
        $this->closeStatement();
    }

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * Preferable all $arguments are type qualifying arrays.
     * Secures safer behaviour and far quicker execution.
     *
     * Otherwise - literal argument value - the only types are
     * integer, float, string (and binary, if non-empty arg $types).
     *
     * Type qualifying argument
     * ------------------------
     * Must have numerical and consecutive keys, starting with zero.
     * Qualifies:
     * - 'in' and/or 'out' nature of the argument
     * - SQLSRV_SQLTYPE_* if 'in' parameter
     * Array structure:
     * - 0: (mixed) value
     * - 1: (int|null) SQLSRV_PARAM_IN|SQLSRV_PARAM_INOUT|null; null ~ SQLSRV_PARAM_IN
     * - 2: (int|null) SQLSRV_PHPTYPE_*; out type
     * - 3: (int|null) SQLSRV_SQLTYPE_*; in type
     *
     * @see http://php.net/manual/en/function.sqlsrv-prepare.php
     *
     * @param string $types
     *      Empty: uses string for all.
     *      Ignored if all $arguments are type qualifying arrays.
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
     * @throws DbRuntimeException
     *      Failure to bind $arguments to native layer.
     */
    public function prepareStatement(string $types, array &$arguments) : DbQueryInterface
    {
        if ($this->instanceInert) {
            throw new DbLogicalException($this->client->errorMessagePreamble() . ' - query instance inert.');
        }

        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' - query cannot prepare statement more than once.'
            );
        }

        $this->isPreparedStatement = true;

        // Checks for parameters/arguments count mismatch.
        $query_fragments = $this->queryFragments($this->query, $arguments);
        if ($query_fragments) {
            unset($query_fragments);
            $this->adaptArguments($types, $arguments);
        }

        // Allow re-connection.
        $connection = $this->client->getConnection(true);

        $options = [
            'Scrollable' => $this->cursorMode,
            'SendStreamParamsAtExec' => !$this->sendDataChunked,
        ];
        if ($this->queryTimeout) {
            $options['QueryTimeout'] = $this->queryTimeout;
        }

        /** @var resource $statement */
        $statement = @sqlsrv_prepare($connection, $this->query, $this->preparedStmtArgs ?? [], $options);
        if (!$statement) {
            $this->unsetReferences();
            throw new DbRuntimeException(
                $this->client->errorMessagePreamble()
                . ' - query failed to prepare statement and bind parameters, with error: '
                . $this->client->nativeError() . '.'
            );
        }
        $this->preparedStatement = $statement;

        return $this;
    }

    /**
     * Set query arguments for native automated parameter marker substitution.
     *
     * The base query remains reusable allowing more ->parameters()->execute(),
     * much like a prepared statement (except arguments aren't referred).
     *
     * Non-prepared statement only.
     *
     * Query parameter marker is question mark.
     *
     * Arg $types type:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $types
     *      Ignored if all arguments are type qualified arrays.
     *      Otherwise empty: uses string for all.
     * @param array $arguments
     *      Values to substitute query ?-parameters with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Base query has been repeated.
     *      Another query has been appended to base query.
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     *      Arg $types length (unless empty) doesn't match number of parameters.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->instanceInert) {
            throw new DbLogicalException($this->client->errorMessagePreamble() . ' - query instance inert.');
        }

        if ($this->queryAppended) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble()
                . ' - passing parameters to base query is illegal after another query has been appended.'
            );
        }
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble()
                . ' - passing parameters to prepared statement is illegal except via call to prepareStatement().'
            );
        }

        // Checks for parameters/arguments count mismatch.
        $query_fragments = $this->queryFragments($this->query, $arguments);
        if ($query_fragments) {
            unset($query_fragments);
            $this->adaptArguments($types, $arguments);
        }

        return $this;
    }

    /**
     * Any query must be executed, even non-prepared statement.
     *
     * @return DbResultInterface|MsSqlResult
     *
     * @throws DbLogicalException
     *      Method called without previous call to prepareStatement().
     * @throws DbQueryException
     * @throws DbRuntimeException
     *      Failing to complete sending data as chunks.
     */
    public function execute(): DbResultInterface
    {
        if ($this->instanceInert) {
            throw new DbLogicalException($this->client->errorMessagePreamble() . ' - query instance inert.');
        }

        if ($this->isPreparedStatement) {
            // Require unbroken connection.
            if (!$this->client->isConnected()) {
                $this->unsetReferences();
                throw new DbInterruptionException(
                    $this->client->errorMessagePreamble()
                    . ' - query can\'t execute prepared statement when connection lost.'
                );
            }
            // bool.
            if (!@sqlsrv_execute($this->preparedStatement)) {
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
        else {
            $options = [
                'Scrollable' => $this->cursorMode,
                'SendStreamParamsAtExec' => !$this->sendDataChunked,
            ];
            if ($this->queryTimeout) {
                $options['QueryTimeout'] = $this->queryTimeout;
            }

            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $connection = $this->client->getConnection(true);
            /** @var resource|bool $simple_statement */
            $simple_statement = @sqlsrv_query(
                $connection, $this->queryTampered ?? $this->query, $this->simpleStmtArgs ?? [], $options
            );
            if (!$simple_statement) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' - failed executing simple query, query',
                    $this->queryTampered ?? $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' - failed executing simple query, with error: ' . $this->client->nativeError() . '.'
                );
            }
            $this->simpleStatement = $simple_statement;
        }

        if ($this->sendDataChunked) {
            $chunks = 0;
            while (
                $chunks < $this->sendChunksLimit
                && @sqlsrv_send_stream_data(
                    $this->isPreparedStatement ? $this->preparedStatement : $this->simpleStatement
                )
            ) {
                ++$chunks;
            }
            $error = $this->client->nativeError(true);
            if ($error) {
                throw new DbRuntimeException(
                    $this->client->errorMessagePreamble()
                    . ' - failed to complete sending data chunked, after chunk[' . $chunks . '], with error: '
                    . $error . '.'
                );
            }
        }

        $class_result = static::CLASS_RESULT;
        /** @var DbResultInterface|MsSqlResult */
        return new $class_result($this, $this->isPreparedStatement ? $this->preparedStatement : $this->simpleStatement);
    }

    /**
     * @return void
     */
    public function closeStatement()
    {
        if ($this->preparedStatement) {
            $this->unsetReferences();
            @sqlsrv_free_stmt($this->preparedStatement);
        } elseif ($this->simpleStatement) {
            @sqlsrv_free_stmt($this->simpleStatement);
        }
    }

    /**
     * Does nothing, because Sqlsrv statement and result are linked
     * by the same resource.
     * Thus a statement could inadvertedly be closed.
     *
     * @return void
     */
    public function freeResult()
    {
    }


    //  Helpers.----------------------------------------------------------------

    /**
     * Get native SQLSRV_SQLTYPE_* constant equivalent of arg $value type.
     *
     * Checks that non-empty $typeChar matches $value type.
     *
     * Cannot detect binary unless non-empty $typeChar (b).
     *
     * SQLTYPE Constants:
     * @see https://docs.microsoft.com/en-us/sql/connect/php/constants-microsoft-drivers-for-php-for-sql-server
     *
     * Integers:
     * @see https://docs.microsoft.com/en-us/sql/t-sql/data-types/int-bigint-smallint-and-tinyint-transact-sql
     *
     * @param integer|float|string $value
     * @param string $typeChar
     *      Values: i, d, s, b.
     *
     * @return int
     *      SQLSRV_SQLTYPE_* constant.
     *
     * @throws \InvalidArgumentException
     *      Non-empty arg $typeChar isn't one of i, d, s, b.
     * @throws \RuntimeException
     *      Non-empty arg $typeChar doesn't arg $value type.
     */
    public function nativeType($value, string $typeChar = '')
    {
        if ($typeChar) {
            switch ($typeChar) {
                case 'i':
                    if (!is_int($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    // Integer; continue to end of method body.
                    break;
                case 'd':
                    if (!is_float($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    return SQLSRV_SQLTYPE_FLOAT;
                case 's':
                    if (!is_string($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    return SQLSRV_SQLTYPE_VARCHAR(strlen($value));
                case 'b':
                    if (!is_string($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    return SQLSRV_SQLTYPE_VARBINARY(strlen($value));
                default:
                    throw new \InvalidArgumentException(
                        'Arg $typeChar value[' . $typeChar . '] is not one of i, d, s, b.'
                    );
            }
        }
        else {
            if ($value === '') {
                return SQLSRV_SQLTYPE_VARCHAR(0);
            }

            $type = gettype($value);
            switch ($type) {
                case 'string':
                    // Cannot discern binary from string.
                    return SQLSRV_SQLTYPE_VARCHAR(strlen($value));
                case 'integer':
                    // Integer; continue to end of method body.
                    break;
                case 'double':
                case 'float':
                    return SQLSRV_SQLTYPE_FLOAT;
                default:
                    throw new \InvalidArgumentException(
                        'Arg $value type[' . $type . '] is not integer|float|string.'
                    );
            }
        }

        if ($value >= 0 && $value <= 255) {
            return SQLSRV_SQLTYPE_TINYINT;
        }
        if ($value >= -32768 && $value <= 32767) {
            return SQLSRV_SQLTYPE_SMALLINT;
        }
        if ($value >= -2147483648 && $value <= 2147483647) {
            return SQLSRV_SQLTYPE_INT;
        }
        return SQLSRV_SQLTYPE_BIGINT;
    }

    /**
     * @param string $types
     * @param &$arguments
     *      By reference, for prepared statement's sake.
     *
     * @return void
     *      Number of parameters/arguments.
     *
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     */
    protected function adaptArguments(string $types, array &$arguments) /*:void*/
    {
        // Checks for parameters/arguments count mismatch.
        $query_fragments = $this->queryFragments($this->query, $arguments);
        $n_params = count($query_fragments) - 1;

        if (!$n_params) {

            return;
        }

        // Use arg $arguments directly if all args have type flags.
        // Otherwise build new arguments list, securing type flags.

        $args_typed = true;
        /**
         * Type qualifying array argument:
         * - 0: (mixed) value
         * - 1: (int|null) SQLSRV_PARAM_IN|SQLSRV_PARAM_INOUT|null; null ~ SQLSRV_PARAM_IN
         * - 2: (int|null) SQLSRV_PHPTYPE_*; out type
         * - 3: (int|null) SQLSRV_SQLTYPE_*; in type
         * @see http://php.net/manual/en/function.sqlsrv-prepare.php
         */
        $i = -1;
        foreach ($arguments as $arg) {
            ++$i;
            if (!is_array($arg)) {
                // Argumment is arg value only.
                $args_typed = false;
                break;
            }
            $count = count($arg);
            if (!$count) {
                throw new \InvalidArgumentException(
                    $this->client->errorMessagePreamble() . ' - arg $arguments bucket ' . $i . ' is empty array.'
                );
            }
            // An 'in' parameter must have 4th bucket,
            // containing SQLSRV_SQLTYPE_* constant.
            if (
                $count == 1
                || ($arg[1] != SQLSRV_PARAM_OUT && ($count < 4 || !$arg[3]))
            ) {
                $args_typed = false;
                break;
            }
        }

        if ($args_typed) {
            if ($this->isPreparedStatement) {
                $this->preparedStmtArgs =& $arguments;
            } else {
                // Don't refer; cannot unset the reference on later execute()
                // because setting to an unset instance var is PHP illegal.
                $this->simpleStmtArgs = $arguments;
            }

            return;
        }

        // Use arg $types to establish types.
        $tps = $types;
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

        $is_prep_stat = $this->isPreparedStatement;
        $type_qualifieds = [];
        $i = -1;
        foreach ($arguments as &$arg) {
            ++$i;
            if (!is_array($arg)) {
                // Argumment is arg value only.
                $type_qualifieds[] = [
                    null,
                    SQLSRV_PARAM_IN,
                    null,
                    $this->nativeType($arg, $tps[$i])
                ];
                if ($is_prep_stat) {
                    $type_qualifieds[$i][0] = &$arg;
                } else {
                    $type_qualifieds[$i][0] = $arg;
                }
            }
            else {
                // Expect numerical and consecutive keys,
                // starting with zero.
                // And don't check, too costly performance-wise.
                $count = count($arg);
                if ($count > 3) {
                    $type_qualifieds[] = [
                        null,
                        $arg[1],
                        $arg[2],
                        $arg[3] ?? ($arg[1] == SQLSRV_PARAM_OUT ? null : $this->nativeType($arg, $tps[$i]))
                    ];
                    if ($is_prep_stat) {
                        $type_qualifieds[$i][0] = &$arg;
                    } else {
                        $type_qualifieds[$i][0] = $arg;
                    }
                }
                else {
                    $type_qualifieds[] = [
                        null,
                        $count > 1 ? $arg[1] : null,
                        $count > 2 ? $arg[2] : null,
                        $count > 1 && $arg[1] == SQLSRV_PARAM_OUT ? null : $this->nativeType($arg, $tps[$i])
                    ];
                    if ($is_prep_stat) {
                        $type_qualifieds[$i][0] = &$arg;
                    } else {
                        $type_qualifieds[$i][0] = $arg;
                    }
                }
            }
        }
        // Iteration ref.
        unset($arg);
        
        if ($this->isPreparedStatement) {
            $this->preparedStmtArgs =& $type_qualifieds;
        } else {
            // Don't refer; cannot unset the reference on later execute()
            // because setting to an unset instance var is PHP illegal.
            $this->simpleStmtArgs = $type_qualifieds;
        }
    }
}

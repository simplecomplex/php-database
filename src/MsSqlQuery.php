<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbResultInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbInterruptionException;
use SimpleComplex\Database\Exception\DbQueryException;

/**
 * MS SQL query.
 *
 * Multi-query is NOT supported by MS SQL.
 * For multi-query explanation, see:
 * @see DbClientMultiInterface
 *
 * Inherited properties:
 * @property-read string $id
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 *
 * Own read-onlys:
 * @property-read string $cursorMode
 * @property-read int $queryTimeout
 * @property-read bool $sendDataChunked
 * @property-read int $sendChunksLimit
 * @property-read bool $getInsertId
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
        SQLSRV_CURSOR_CLIENT_BUFFERED,
    ];

    /**
     * Default result set cursor mode.
     *
     * Sqlsrv default is 'forward':
     * - fast
     * - reflects changes serverside
     * - doesn't allow getting number of rows
     * - allows getting affected rows
     *
     * This class' default is 'static':
     * - slower
     * - we don't want serverside changes to reflect the result set
     * - we like getting number of rows
     * - doesn't allow getting affected rows
     *
     * @var string
     */
    const CURSOR_MODE_DEFAULT = SQLSRV_CURSOR_STATIC;

    /**
     * @var int
     */
    const SEND_CHUNKS_LIMIT = 1000;

    /**
     * Additional query needed for getting last insert ID.
     *
     * @see MsSqlResult::insertId()
     *
     * @var string
     */
    const SQL_INSERT_ID = 'SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME';

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var MsSqlClient
     */
    public $client;

    /**
     * Prepared or simple statement.
     *
     * @var resource|null
     *      Overriding to annotate type.
     */
    protected $statement;

    /**
     * Option (int) query_timeout.
     *
     * @see MsSqlQuery::QUERY_TIMEOUT
     *
     * @var int
     */
    protected $queryTimeout;

    /**
     * Option (str) cursor_mode.
     *
     * @see MsSqlQuery::CURSOR_MODES
     * @see MsSqlQuery::CURSOR_MODE_DEFAULT
     *
     * @var string
     */
    protected $cursorMode;

    /**
     * Option (bool) send_data_chunked.
     *
     * Send query statement data in chunks instead sending all immediately.
     *
     * Relevant if giant sql string.
     *
     * Native setting 'SendStreamParamsAtExec'; opposite boolean value.
     * @see http://php.net/manual/en/function.sqlsrv-send-stream-data.php
     *
     * @var bool
     */
    protected $sendDataChunked = false;

    /**
     * Option (int) send_chunks_limit.
     *
     * @see MsSqlQuery::SEND_CHUNKS_LIMIT
     *
     * @var int
     */
    protected $sendChunksLimit;

    /**
     * Option (bool) get_insert_id.
     *
     * Required when intending to retrieve last insert ID.
     * @see MsSqlResult::insertId()
     *
     * @var bool
     */
    protected $getInsertId;

    /**
     * @param DbClientInterface|DatabaseClient|MsSqlClient $client
     *      Reference to parent client.
     * @param string $sql
     * @param array $options {
     *      @var string $cursor_mode
     *      @var int $query_timeout
     *      @var bool $send_data_chunked
     *      @var int $send_chunks_limit
     *      @var bool $get_insert_id
     * }
     *
     * @throws \InvalidArgumentException
     *      Propagated; arg $sql empty.
     *      Unsupported 'cursor_mode'.
     */
    public function __construct(DbClientInterface $client, string $sql, array $options = [])
    {
        parent::__construct($client, $sql, $options);

        if (!empty($options['cursor_mode'])) {
            if (!in_array($options['cursor_mode'], static::CURSOR_MODES, true)) {
                throw new \InvalidArgumentException(
                    $this->client->errorMessagePrefix()
                    . ' query option \'cursor_mode\' value[' . $options['cursor_mode'] . '] is invalid.'
                );
            }
            $this->cursorMode = $options['cursor_mode'];
        } else {
            $this->cursorMode = static::CURSOR_MODE_DEFAULT;
        }
        $this->explorableIndex[] = 'cursorMode';

        if (isset($options['query_timeout'])) {
            $this->queryTimeout = $options['query_timeout'];
            if ($this->queryTimeout < 0) {
                $this->queryTimeout = 0;
            }
        } else {
            $this->queryTimeout = static::QUERY_TIMEOUT;
        }
        $this->explorableIndex[] = 'queryTimeout';

        if (!empty($options['send_data_chunked'])) {
            $this->sendDataChunked = true;
            $this->sendChunksLimit = $options['send_chunks_limit'] ?? static::SEND_CHUNKS_LIMIT;
        }
        $this->explorableIndex[] = 'sendDataChunked';
        $this->explorableIndex[] = 'sendChunksLimit';

        $this->getInsertId = !empty($options['get_insert_id']);
        if ($this->getInsertId && strpos($sql, static::SQL_INSERT_ID) === false) {
            $this->sqlTampered = $this->sql . '; ' . static::SQL_INSERT_ID;
        }
        $this->explorableIndex[] = 'getInsertId';
    }

    public function __destruct()
    {
        if ($this->statement) {
            @sqlsrv_free_stmt($this->statement);
        }
    }

    /**
     * Turn query into server-side prepared statement and bind parameters.
     *
     * Preferable all $arguments are type qualifying arrays.
     * Secures safer behaviour and far quicker execution.
     *
     * Otherwise - literal argument value - the only types are
     * integer, float, string (and binary, if non-empty arg $types).
     *
     * Chainable.
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
     * Supports that arg $arguments is associative array.
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
     * @throws \LogicException
     *      Method called more than once for this query.
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     * @throws DbRuntimeException
     *      Failure to bind $arguments to native layer.
     */
    public function prepare(string $types, array &$arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            throw new \LogicException(
                $this->client->errorMessagePrefix() . ' - query cannot prepare statement more than once.'
            );
        }
        $this->isPreparedStatement = true;

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sqlTampered ?? $this->sql, $arguments);

        if ($sql_fragments) {
            unset($sql_fragments);

            // Set instance var $arguments['prepared'] or $arguments['simple'].
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
        $statement = @sqlsrv_prepare(
            $connection, $this->sqlTampered ?? $this->sql, $this->arguments['prepared'] ?? [], $options
        );
        if (!$statement) {
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            $this->log(__FUNCTION__);
            throw new DbRuntimeException(
                $this->errorMessagePrefix()
                . ' - query failed to prepare statement and bind parameters, with error: '
                . $this->client->nativeError() . '.'
            );
        }
        $this->statement = $statement;

        return $this;
    }

    /**
     * Non-prepared statement: set query arguments for native automated
     * parameter marker substitution.
     *
     * The base sql remains reusable allowing more ->parameters()->execute(),
     * much like a prepared statement (except arguments aren't referred).
     *
     * Sql parameter marker is question mark.
     *
     * Chainable.
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
     *      Values to substitute sql ?-parameters with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \LogicException
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     *      Arg $types length (unless empty) doesn't match number of parameters.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            throw new \LogicException(
                $this->client->errorMessagePrefix()
                . ' - passing parameters to prepared statement is illegal except via call to prepare().'
            );
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sqlTampered ?? $this->sql, $arguments);
        if ($sql_fragments) {
            unset($sql_fragments);

            // Set instance var $arguments['prepared'] or $arguments['simple'].
            $this->adaptArguments($types, $arguments);
        }

        return $this;
    }

    /**
     * Any query must be executed, even non-prepared statement.
     *
     * @return DbResultInterface|MsSqlResult
     *
     * @throws \LogicException
     *      Query statement previously closed.
     * @throws DbInterruptionException
     *      Is prepared statement and connection lost.
     * @throws DbQueryException
     * @throws DbRuntimeException
     *      Failing to complete sending data as chunks.
     */
    public function execute(): DbResultInterface
    {
        // (Sqlsrv) Even a simple statement is a 'statement'.
        if ($this->statementClosed) {
            throw new \LogicException(
                $this->client->errorMessagePrefix()
                . ' - query can\'t execute previously closed statement.'
            );
        }

        if ($this->isPreparedStatement) {
            // Require unbroken connection.
            if (!$this->client->isConnected()) {
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $this->log(__FUNCTION__);
                throw new DbInterruptionException(
                    $this->errorMessagePrefix()
                    . ' - query can\'t execute prepared statement when connection lost.'
                );
            }
            // bool.
            if (!@sqlsrv_execute($this->statement)) {
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $this->log(__FUNCTION__);
                throw new DbQueryException(
                    $this->errorMessagePrefix()
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
            /** @var resource|bool $statement */
            $statement = @sqlsrv_query(
                $connection, $this->sqlTampered ?? $this->sql, $this->arguments['simple'] ?? [], $options
            );
            if (!$statement) {
                $this->log(__FUNCTION__);
                throw new DbQueryException(
                    $this->errorMessagePrefix()
                    . ' - failed executing simple query, with error: ' . $this->client->nativeError() . '.'
                );
            }
            $this->statement = $statement;
        }

        if ($this->sendDataChunked) {
            $chunks = 0;
            while (
                $chunks < $this->sendChunksLimit
                && @sqlsrv_send_stream_data($this->statement)
            ) {
                ++$chunks;
            }
            $error = $this->client->nativeError(true);
            if ($error) {
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $this->log(__FUNCTION__);
                throw new DbRuntimeException(
                    $this->errorMessagePrefix()
                    . ' - failed to complete sending data chunked, after chunk[' . $chunks . '], with error: '
                    . $error . '.'
                );
            }
        }

        $class_result = static::CLASS_RESULT;
        /** @var DbResultInterface|MsSqlResult */
        return new $class_result($this, null, $this->statement);
    }

    /**
     * Flag that the sql contains LIKE clause(s).
     *
     * @see DatabaseQuery::hasLikeClause()
     */
    // public function hasLikeClause() : DbQueryInterface

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
            @sqlsrv_free_stmt($this->statement);
        }
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
                    // @todo: Why doesn't SQLSRV_SQLTYPE_VARCHAR(int) work?
                    //return SQLSRV_SQLTYPE_VARCHAR(strlen($value));
                    return SQLSRV_SQLTYPE_VARCHAR('max');
                case 'b':
                    if (!is_string($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    // @todo: Why doesn't SQLSRV_SQLTYPE_VARBINARY(int) work?
                    //return SQLSRV_SQLTYPE_VARBINARY(strlen($value));
                    return SQLSRV_SQLTYPE_VARBINARY('max');
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
     * Sets instance var $arguments['prepared'] or $arguments['simple'].
     *
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
        $sql_fragments = $this->sqlFragments($this->sql, $arguments);
        $n_params = count($sql_fragments) - 1;

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
                    $this->client->errorMessagePrefix() . ' - arg $arguments bucket ' . $i . ' is empty array.'
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
            // Support assoc array; sqlsrv_prepare() doesn't.
            if ($arguments && !ctype_digit('' . join(array_keys($arguments)))) {
                $args = [];
                $i = -1;
                foreach ($arguments as &$arg) {
                    $args[] = $arg;
                    $args[++$i][0] =& $arg[0];
                }
                unset($arg);
                if ($this->isPreparedStatement) {
                    $this->arguments['prepared'] =& $args;
                } else {
                    // Don't refer; cannot unset the reference on later execute()
                    // because setting to an unset instance var is PHP illegal.
                    $this->arguments['simple'] = $args;
                }
            }
            else {
                if ($this->isPreparedStatement) {
                    $this->arguments['prepared'] =& $arguments;
                } else {
                    // Don't refer; cannot unset the reference on later execute()
                    // because setting to an unset instance var is PHP illegal.
                    $this->arguments['simple'] = $arguments;
                }
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
                $this->client->errorMessagePrefix() . ' - arg $types length[' . strlen($types)
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
            );
        }
        elseif (($type_illegals = $this->parameterTypesCheck($types))) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix()
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
                        $arg[3] ?? ($arg[1] == SQLSRV_PARAM_OUT ? null : $this->nativeType($arg[0], $tps[$i]))
                    ];
                    if ($is_prep_stat) {
                        $type_qualifieds[$i][0] = &$arg[0];
                    } else {
                        $type_qualifieds[$i][0] = $arg[0];
                    }
                }
                else {
                    $type_qualifieds[] = [
                        null,
                        $count > 1 ? $arg[1] : null,
                        $count > 2 ? $arg[2] : null,
                        $count > 1 && $arg[1] == SQLSRV_PARAM_OUT ? null : $this->nativeType($arg[0], $tps[$i])
                    ];
                    if ($is_prep_stat) {
                        $type_qualifieds[$i][0] = &$arg[0];
                    } else {
                        $type_qualifieds[$i][0] = $arg[0];
                    }
                }
            }
        }
        // Iteration ref.
        unset($arg);
        
        if ($this->isPreparedStatement) {
            $this->arguments['prepared'] =& $type_qualifieds;
        } else {
            // Don't refer; the prospect of a slight performance gain isn't
            // worth the risk.
            // A simple query's arguments shan't refer to the outside;
            // new arguments list before every execute().
            $this->arguments['simple'] = $type_qualifieds;
        }
    }
}

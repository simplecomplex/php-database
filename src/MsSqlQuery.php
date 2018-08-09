<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Utils\Utils;
use SimpleComplex\Validate\Validate;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbResultInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbQueryArgumentException;
use SimpleComplex\Database\Exception\DbConnectionException;
use SimpleComplex\Database\Exception\DbQueryException;

/**
 * MS SQL query.
 *
 *
 * Multi-query only when stored procedure
 * --------------------------------------
 * Multi-query producing more results sets is not supported by MS SQL,
 * except when calling stored procedure. For multi vs. batch query, see:
 * @see DbQuery
 *
 *
 * Argument object stringification
 * -------------------------------
 * Sqlsrv handles DateTimes vs. string transparently, interchangeably.
 * However SQLSRV_SQLTYPE_* date/datetimes only accept (\DateTime and)
 * string YYYY-MM-DD.
 * Sqlsrv makes no attempt to stringify object as query argument, regardless
 * of any __toString() method.
 *
 *
 * Properties inherited from DbQuery:
 * @property-read string $name
 * @property-read string $id
 * @property-read int $nExecution
 * @property-read int $validateParams
 * @property-read int $reusable
 * @property-read string $resultMode
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 * @property-read bool $transactionStarted  Value of client ditto.
 *
 * Own properties:
 * @property-read int $queryTimeout
 * @property-read bool $sendDataChunked
 * @property-read int $sendChunksLimit
 * @property-read bool $getInsertId
 *
 * @package SimpleComplex\Database
 */
class MsSqlQuery extends DbQuery
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
     * Sqlsrv makes no attempt to stringify object,
     * regardless of __toString() method.
     *
     * @see DbQuery::AUTO_STRINGIFIES_OBJECT
     *
     * @var int
     */
    const AUTO_STRINGIFIES_OBJECT = 0;

    /**
     * List of class names of objects that automatically gets stringified,
     * and accepted as strings, by the DBMS driver
     *
     * Sqlsrv stringifies \DateTime when column datatype is (n)varchar.
     *
     * @var string[]
     */
    const AUTO_STRINGABLE_CLASSES = [
        \DateTime::class,
    ];

    /**
     * Validate on failure.
     *
     * Option (int) validate_params overrules.
     *
     * Doing check for non-stringable object is not relevant,
     * because Sqlsrv generally makes no attempt to stringify object
     * and it recovers well from stumbling upon object (unlike MySQLi).
     *
     * @see DbQuery::AUTO_STRINGIFIES_OBJECT
     * @see DbQuery::VALIDATE_STRINGABLE_EXEC
     */
    const VALIDATE_PARAMS = DbQuery::VALIDATE_FAILURE;

    /**
     * Default query timeout.
     *
     * Zero means no timeout; waits forever.
     *
     * @var int
     */
    const QUERY_TIMEOUT = 0;

    /**
     * Result modes/cursor types.
     *
     * Summary:
     * - all scrollable except 'forward'
     * - all unbuffered except 'buffered'
     * - affected rows is 'forward' only (an sqlsrv bug?)
     * - number of rows is 'static/keyset/buffered' only
     *
     * 'forward', Sqlsrv default:
     * - reflects changes serverside
     * - number of rows forbidden
     *
     * 'static':
     * - access rows in any order
     * - doesn't reflect changes serverside
     * - affected rows forbidden
     *
     * 'dynamic':
     * - affected rows forbidden
     * - number of rows forbidden
     *
     * 'keyset':
     * - affected rows forbidden
     *
     * 'buffered':
     * - buffered client side; light server side, heavy client side
     * - affected rows forbidden
     *
     * Scrollable:
     * @see http://php.net/manual/en/function.sqlsrv-query.php
     *
     * Sqlsrv Cursor Types:
     * @see https://docs.microsoft.com/en-us/sql/connect/php/cursor-types-sqlsrv-driver
     *
     * Affected rows must be consumed when
     * https://docs.microsoft.com/en-us/sql/relational-databases/native-client-odbc-results/processing-results-odbc
     *
     * @var string[]
     */
    const RESULT_MODES = [
        SQLSRV_CURSOR_FORWARD,
        SQLSRV_CURSOR_STATIC,
        SQLSRV_CURSOR_DYNAMIC,
        SQLSRV_CURSOR_KEYSET,
        SQLSRV_CURSOR_CLIENT_BUFFERED,
    ];

    /**
     * Default result mode.
     *
     * @var string
     */
    const RESULT_MODE_DEFAULT = SQLSRV_CURSOR_FORWARD;

    /**
     * RMDBS specific query options supported, adding to generic options.
     *
     * @see DbQuery::OPTIONS_GENERIC
     *
     * @var string[]
     */
    const OPTIONS_SPECIFIC = [
        'send_data_chunked',
        'sendChunksLimit',
    ];

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
     * @var string[]
     */
    const SQL_SNIPPET = [
        'select_uuid' => 'SELECT NEWID()',
    ];

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
     * Option (str) result_mode.
     *
     * @see MsSqlQuery::RESULT_MODES
     * @see MsSqlQuery::RESULT_MODE_DEFAULT
     *
     * @var string
     */
    protected $resultMode;

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
     * Option (bool) insert_id.
     *
     * Required when intending to retrieve last insert ID.
     * @see MsSqlResult::insertId()
     *
     * @var bool
     */
    protected $getInsertId;

    /**
     * Options affected_rows and num_rows may override default
     * result mode (not option result_mode) and adjust to support result
     * affectedRows/numRows().
     * @see MsSqlQuery::RESULT_MODES
     *
     * @param DbClientInterface|DbClient|MsSqlClient $client
     *      Reference to parent client.
     * @param string $sql
     * @param array $options {
     *      @var string $result_mode
     *      @var bool $affected_rows  May adjust result mode to 'forward'.
     *      @var bool $insert_id
     *      @var bool $num_rows  May adjust result mode to 'static'|'keyset'.
     *      @var int $query_timeout
     *      @var bool $send_data_chunked
     *      @var int $send_chunks_limit
     * }
     *
     * @throws \InvalidArgumentException
     *      Propagated; arg $sql empty.
     *      Unsupported 'result_mode'.
     * @throws \LogicException
     *      Propagated; arg $options contains illegal option.
     */
    public function __construct(DbClientInterface $client, string $sql, array $options = [])
    {
        parent::__construct($client, $sql, $options);

        if (!empty($options['result_mode'])) {
            if (!in_array($options['result_mode'], static::RESULT_MODES, true)) {
                throw new \InvalidArgumentException(
                    $this->messagePrefix()
                    . ' query option \'result_mode\' value[' . $options['result_mode'] . '] is invalid.'
                );
            }
            $this->resultMode = $options['result_mode'];
        }
        elseif (!empty($options['affected_rows'])) {
            /**
             * @see MsSqlResult::affectedRows()
             */
            $this->resultMode = SQLSRV_CURSOR_FORWARD;
        }
        elseif (!empty($options['num_rows'])) {
            /**
             * @see MsSqlResult::numRows()
             */
            switch (static::RESULT_MODE_DEFAULT) {
                case SQLSRV_CURSOR_STATIC:
                case SQLSRV_CURSOR_KEYSET:
                    $this->resultMode = static::RESULT_MODE_DEFAULT;
                    break;
                default:
                    $this->resultMode = SQLSRV_CURSOR_STATIC;
            }
        }
        else {
            $this->resultMode = static::RESULT_MODE_DEFAULT;
        }

        // Re-connect requires client buffered result mode.
        if ($this->resultMode != SQLSRV_CURSOR_CLIENT_BUFFERED) {
            $this->client->reConnectDisable();
        }

        $this->getInsertId = !empty($options['insert_id']);
        // The sql never gets tampered by anything (like parameter substitution)
        // so it's safe to append the insert id ding already here.
        if ($this->getInsertId && stripos($sql, static::SQL_INSERT_ID) === false) {
            $this->sqlTampered = $this->sql . '; ' . static::SQL_INSERT_ID;
        }
        $this->explorableIndex[] = 'getInsertId';

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
     * - 1: (int|null) SQLSRV_PARAM_IN|SQLSRV_PARAM_INOUT|SQLSRV_PARAM_OUT|null;
     *      null ~ SQLSRV_PARAM_IN
     * - 2: (int|null) SQLSRV_PHPTYPE_*; out type
     * - 3: (int|null) SQLSRV_SQLTYPE_*; in type
     *
     * @see http://php.net/manual/en/function.sqlsrv-prepare.php
     *
     * Supports that arg $arguments is associative array.
     *
     * @param string $types
     *      Empty: uses $arguments' actual types.
     *      Ignored for $arguments that are type qualifying arrays.
     * @param array &$arguments
     *      By reference.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \LogicException
     *      Method called more than once for this query.
     * @throws DbQueryArgumentException
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
                $this->messagePrefix() . ' - can\'t prepare statement more than once.'
            );
        }
        $this->isPreparedStatement = true;

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sql, $arguments);

        if ($sql_fragments) {
            unset($sql_fragments);
            // Set instance var $arguments['prepared'] or $arguments['simple'].
            $this->adaptArguments($types, $arguments);
        }

        $options = [
            'Scrollable' => $this->resultMode,
            'SendStreamParamsAtExec' => !$this->sendDataChunked,
        ];
        if ($this->queryTimeout) {
            $options['QueryTimeout'] = $this->queryTimeout;
        }

        // Allow re-connection.
        $connection = $this->client->getConnection(true);
        $statement = null;
        if ($connection) {
            /** @var resource $statement */
            $statement = @sqlsrv_prepare(
                $connection, $this->sqlTampered ?? $this->sql, $this->arguments['prepared'] ?? [], $options
            );
        }
        if (!$statement) {
            $errors = $this->client->getErrors();
            $this->log(__FUNCTION__);
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            $cls_xcptn = $this->client->errorsToException($errors);
            throw new $cls_xcptn(
                $this->messagePrefix() . ' - failed to prepare statement, error: '
                . $this->client->errorsToString($errors) . '.'
            );
        }
        $this->statementClosed = false;
        $this->statement = $statement;

        return $this;
    }

    /**
     * Non-prepared statement: set query arguments for native automated
     * parameter marker substitution.
     *
     * The base sql remains reusable - if option reusable - allowing more
     * ->parameters()->execute(), much like a prepared statement
     * (except arguments aren't referred).
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
     * @throws DbQueryArgumentException
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
                $this->messagePrefix()
                . ' - passing parameters to prepared statement is illegal except via call to prepare().'
            );
        }

        // Allow another execution of this query.
        if ($this->reusable && $this->nExecution) {
            ++$this->reusable;
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sql, $arguments);
        if ($sql_fragments) {
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
     *      Repeated execution of simple query without truthy option reusable
     *      and intermediate call to parameters().
     * @throws DbConnectionException
     *      Is prepared statement and connection lost.
     * @throws DbQueryException
     * @throws DbRuntimeException
     *      Failing to complete sending data as chunks.
     */
    public function execute(): DbResultInterface
    {
        ++$this->nExecution;

        // (Sqlsrv) Even a simple statement is a 'statement'.
        if ($this->statementClosed) {
            throw new \LogicException(
                $this->messagePrefix()
                . ' - can\'t execute previously closed statement.'
            );
        }

        if ($this->isPreparedStatement) {
            // Validate arguments before execution?
            if (($this->validateParams & DbQuery::VALIDATE_EXECUTE) && !empty($this->arguments['prepared'])) {
                // Throws exception on validation failure
                $this->validateArgumentsNativeType($this->arguments['prepared'], [], 'execute');
            }

            // Require unbroken connection.
            if (!$this->client->isConnected()) {
                $errors = $this->client->getErrors();
                $this->log(__FUNCTION__);
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->messagePrefix() . ' - can\'t do execution[' . $this->nExecution
                    . '] of prepared statement when connection lost, error: '
                    . $this->client->errorsToString($errors) . '.'
                );
            }

            // bool.
            if (!@sqlsrv_execute($this->statement)) {
                $errors = $this->client->getErrors();
                $this->log(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                // Validate parameters on query failure.
                if (
                    ($this->validateParams & DbQuery::VALIDATE_FAILURE)
                    && !empty($this->arguments['prepared']) && $cls_xcptn != DbConnectionException::class
                ) {
                    $msg = $this->validateArgumentsNativeType($this->arguments['prepared']);
                    if (!$msg) {
                        $msg = 'no parameter error observed, DBMS error: ';
                    } else {
                        $msg = 'parameter error: ' . $msg . '. DBMS error: ';
                    }
                } else {
                    $msg = 'error: ';
                }
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                throw new $cls_xcptn(
                    $this->messagePrefix() . ' - failed execution[' . $this->nExecution . '] of prepared statement, '
                        . $msg . $this->client->errorsToString($errors) . '.'
                );
            }
        }
        else {
            // Safeguard against unintended simple query repeated execute().
            if ($this->nExecution > 1 && $this->reusable != $this->nExecution) {
                throw new \LogicException(
                    $this->messagePrefix() . ' - simple query is not reusable without'
                        . (!$this->reusable ? ' truthy option reusable.' : ' intermediate call to parameters().')
                );
            }

            // Validate arguments before execution?
            if (($this->validateParams & DbQuery::VALIDATE_EXECUTE) && !empty($this->arguments['simple'])) {
                // Throws exception on validation failure
                $this->validateArgumentsNativeType($this->arguments['simple'], [], 'execute');
            }

            $options = [
                'Scrollable' => $this->resultMode,
                'SendStreamParamsAtExec' => !$this->sendDataChunked,
            ];
            if ($this->queryTimeout) {
                $options['QueryTimeout'] = $this->queryTimeout;
            }

            // Allow re-connection.
            /** @var resource|bool $connection */
            $connection = $this->client->getConnection(true);
            $statement = null;
            if ($connection) {
                /** @var resource|bool $statement */
                $statement = @sqlsrv_query(
                    $connection, $this->sqlTampered ?? $this->sql, $this->arguments['simple'] ?? [], $options
                );
            }
            if (!$statement) {
                $errors = $this->client->getErrors();
                $this->log(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                // Validate parameters on query failure.
                if (
                    ($this->validateParams & DbQuery::VALIDATE_FAILURE)
                    && !empty($this->arguments['simple']) && $cls_xcptn != DbConnectionException::class
                ) {
                    $msg = $this->validateArgumentsNativeType($this->arguments['simple']);
                    if (!$msg) {
                        $msg = 'no parameter error observed, DBMS error: ';
                    } else {
                        $msg = 'parameter error: ' . $msg . '. DBMS error: ';
                    }
                } else {
                    $msg = 'error: ';
                }
                throw new $cls_xcptn(
                    $this->messagePrefix() . ' - failed executing simple query, '
                        . $msg . $this->client->errorsToString($errors) . '.'
                );
            }
            $this->statementClosed = false;
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
            $error = $this->client->getErrors(DbError::AS_STRING_EMPTY_ON_NONE);
            if ($error) {
                $errors = $this->client->getErrors();
                $this->log(__FUNCTION__);
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->messagePrefix() . ' - failed to complete sending data chunked, after chunk['
                    . $chunks . '], error: ' . $this->client->errorsToString($errors) . '.'
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
     * @see DbQuery::hasLikeClause()
     */
    // public function hasLikeClause() : DbQueryInterface

    /**
     * @see DbQuery::$statementClosed
     *
     * @see DbQuery::unsetReferences()
     *
     * @return void
     */
    public function close()
    {
        $this->unsetReferences();
        if ($this->statementClosed === false) {
            $this->statementClosed = true;
            if ($this->statement) {
                @sqlsrv_free_stmt($this->statement);
            }
            $this->statement = null;
        }
    }


    //  Helpers.----------------------------------------------------------------

    /**
     * Translate a type string char to native 'in' SQLSRV_SQLTYPE_*
     * or 'out' SQLSRV_PHPTYPE_* type.
     *
     * @param string $direction
     *      Values: in|out.
     * @param mixed $value
     * @param string $types
     * @param int $index
     *
     * @return int
     *      SQLSRV_SQLTYPE_* constant.
     *
     * @throws DbQueryArgumentException
     *      Invalid type char.
     *      Integer too large/small for SQL Server bigint.
     */
    public function nativeTypeFromTypeString(string $direction, $value, string $types, int $index)
    {
        if ($direction != 'in' && $direction != 'out') {
            throw new \InvalidArgumentException(
                $this->messagePrefix() . ' - arg $direction is not in|out.'
            );
        }

        if ($index >= strlen($types)) {
            throw new \OutOfRangeException(
                $this->messagePrefix()
                . ' - arg $index[' . $index . '] ' . $direction . '-param is not within range of arg $types length['
                . strlen($types) . '].'
            );
        }
        switch ($types{$index}) {
            case 'i':
                if ($direction == 'in') {
                    if ($value >= 0 && $value <= 255) {
                        return SQLSRV_SQLTYPE_TINYINT;
                    }
                    if ($value >= -32768 && $value <= 32767) {
                        return SQLSRV_SQLTYPE_SMALLINT;
                    }
                    if ($value >= -2147483648 && $value <= 2147483647) {
                        return SQLSRV_SQLTYPE_INT;
                    }
                    if ($value >= -pow(2, 63) && $value <= pow(2, 63) - 1) {
                        return SQLSRV_SQLTYPE_BIGINT;
                    }
                    throw new DbQueryArgumentException(
                        $this->messagePrefix()
                            . ' - arg $argument index[' . $index . '] ' . $direction . '-param char[i] value['
                            . $value . '] is ' . ($value < 0 ? 'too small (< -2^63)' : 'too large (> 2^63-1)')
                            . ' for SQL Server bigint.'
                    );
                }
                return SQLSRV_PHPTYPE_INT;
            case 'd':
                return $direction == 'in' ? SQLSRV_SQLTYPE_FLOAT :
                    SQLSRV_PHPTYPE_FLOAT;
            case 's':
                if ($direction == 'in') {
                    return SQLSRV_SQLTYPE_VARCHAR('max');
                }
                return $this->client->characterSet == 'UTF-8' ? SQLSRV_PHPTYPE_STRING('UTF-8') :
                    SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR);
            case 'b':
                return $direction == 'in' ? SQLSRV_SQLTYPE_VARBINARY('max') :
                    SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY);
        }
        throw new DbQueryArgumentException(
            $this->messagePrefix() . ' - arg $types index[' . $index . '] char[' . $types{$index}
                . '] direction[' . $direction . '] is not ' . join('|', static::PARAMETER_TYPE_CHARS) . '.'
        );
    }

    /**
     * Translate a value to native 'in' SQLSRV_SQLTYPE_*
     * or 'out' SQLSRV_PHPTYPE_* type.
     *
     * @param string $direction
     *      Values: in|out.
     * @param mixed $value
     * @param int $index
     *
     * @return int
     *      SQLSRV_SQLTYPE_* constant.
     *
     * @throws \InvalidArgumentException
     *      Arg $direction not in|out.
     * @throws DbQueryArgumentException
     *      Arg $value not supported as actual type for translation.
     *      Integer too large/small for SQL Server bigint.
     */
    public function nativeTypeFromActualType(string $direction, $value, int $index)
    {
        if ($direction != 'in' && $direction != 'out') {
            throw new \InvalidArgumentException(
                $this->messagePrefix() . ' - arg $direction is not in|out.'
            );
        }

        $type = gettype($value);
        switch ($type) {
            case 'integer':
                if ($direction == 'in') {
                    if ($value >= 0 && $value <= 255) {
                        return SQLSRV_SQLTYPE_TINYINT;
                    }
                    if ($value >= -32768 && $value <= 32767) {
                        return SQLSRV_SQLTYPE_SMALLINT;
                    }
                    if ($value >= -2147483648 && $value <= 2147483647) {
                        return SQLSRV_SQLTYPE_INT;
                    }
                    if ($value >= -pow(2, 63) && $value <= pow(2, 63) - 1) {
                        return SQLSRV_SQLTYPE_BIGINT;
                    }
                    throw new DbQueryArgumentException(
                        $this->messagePrefix()
                            . ' - arg $argument index[' . $index . '] ' . $direction . '-param value[' . $value
                            . '] is ' . ($value < 0 ? 'too small (< -2^63)' : 'too large (> 2^63-1)')
                            . ' for SQL Server bigint.'
                    );
                }
                return SQLSRV_PHPTYPE_INT;
            case 'double':
            case 'float':
                return $direction == 'in' ? SQLSRV_SQLTYPE_FLOAT :
                    SQLSRV_PHPTYPE_FLOAT;
            case 'string':
                // Cannot discern binary from string.
                if ($direction == 'in') {
                    return SQLSRV_SQLTYPE_VARCHAR('max');
                }
                return $this->client->characterSet == 'UTF-8' ? SQLSRV_PHPTYPE_STRING('UTF-8') :
                    SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR);
            default:
                if ($value instanceof \DateTime) {
                    return $direction == 'in' ? SQLSRV_SQLTYPE_DATETIME2 :
                        SQLSRV_PHPTYPE_DATETIME;
                }
                /**
                 * @see MsSqlQuery::AUTO_STRINGABLE_CLASSES
                 */
                elseif ($direction == 'in' && $type == 'object' && static::AUTO_STRINGABLE_CLASSES) {
                    foreach (static::AUTO_STRINGABLE_CLASSES as $class_name) {
                        if (is_a($value, $class_name)) {
                            return SQLSRV_SQLTYPE_VARCHAR('max');
                        }
                    }
                }
        }
        throw new DbQueryArgumentException(
            $this->messagePrefix()
                . ' - arg $arguments value at index[' . $index . '] type[' . Utils::getType($value)
                . '] direction[' . $direction
                . '] is not integer|float|string or other resolvable and supported sql argument type.'
        );
    }

    /**
     * List native types by name.
     *
     * @see MsSqlQuery::nativeTypes()
     *
     * @var int[]
     */
    protected static $nativeTypesSupported;

    /**
     * List native types by name.
     *
     * Significant unsupported types:
     * - char, nchar: because type constant function requires a length
     *
     * @return array
     */
    public function nativeTypes() : array
    {
        if (!static::$nativeTypesSupported) {
            static::$nativeTypesSupported = [
                // in types.
                'SQLTYPE_BIT' => SQLSRV_SQLTYPE_BIT,
                'SQLTYPE_TINYINT' => SQLSRV_SQLTYPE_TINYINT,
                'SQLTYPE_SMALLINT' => SQLSRV_SQLTYPE_SMALLINT,
                'SQLTYPE_INT' => SQLSRV_SQLTYPE_INT,
                'SQLTYPE_BIGINT' => SQLSRV_SQLTYPE_BIGINT,
                'SQLTYPE_FLOAT' => SQLSRV_SQLTYPE_FLOAT,
                'SQLTYPE_REAL' => SQLSRV_SQLTYPE_REAL,
                'SQLTYPE_DECIMAL(14,2)' => SQLSRV_SQLTYPE_DECIMAL(14,2),
                'SQLTYPE_VARCHAR' => SQLSRV_SQLTYPE_VARCHAR('max'),
                'SQLTYPE_NVARCHAR' => SQLSRV_SQLTYPE_NVARCHAR('max'),
                'SQLTYPE_VARBINARY' => SQLSRV_SQLTYPE_VARBINARY('max'),
                'SQLTYPE_TIME' => SQLSRV_SQLTYPE_TIME,
                'SQLTYPE_DATE' => SQLSRV_SQLTYPE_DATE,
                'SQLTYPE_DATETIME' => SQLSRV_SQLTYPE_DATETIME,
                'SQLTYPE_DATETIME2' => SQLSRV_SQLTYPE_DATETIME2,
                'SQLTYPE_UNIQUEIDENTIFIER' => SQLSRV_SQLTYPE_UNIQUEIDENTIFIER,
                // out types.
                'PHPTYPE_INT' => SQLSRV_PHPTYPE_INT,
                'PHPTYPE_FLOAT' => SQLSRV_PHPTYPE_FLOAT,
                'PHPTYPE_STRING(UTF-8)' => SQLSRV_PHPTYPE_STRING('UTF-8'),
                'PHPTYPE_STRING(SQLSRV_ENC_CHAR)' => SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR),
                'PHPTYPE_STRING(SQLSRV_ENC_BINARY)' => SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY),
                'PHPTYPE_DATETIME' => SQLSRV_PHPTYPE_DATETIME,
            ];
        }
        return static::$nativeTypesSupported;
    }

    /**
     * Get name of a native type, if supported.
     *
     * @param int $nativeType
     *
     * @return string
     *      Empty if unsupported native type.
     */
    public function nativeTypeName(int $nativeType) : string
    {
        $types = $this->nativeTypes();
        foreach ($types as $name => $number) {
            if ($nativeType == $number) {
                return $name;
            }
        }
        return '';
    }

    /**
     * Validate that arguments matches native types.
     *
     * Loose typings:
     * - bit type allows boolean and stringed 0|1
     * - integer types allow stringed integer
     * - float, real and decimal types allow integer and stringed number
     * - varchars allow \DateTime
     * - varchars and binary allow scalar not boolean
     * - date and datetimes allow \DateTime and string ISO-8601 date (YYYY-MM-DD)
     *
     * Other observations:
     * - time type requires seconds
     * - sending time as varchar fails silently, becomes 00:00:00
     *
     * @param array $arguments
     * @param array $skipIndices
     *      Skip validating buckets at these indices.
     * @param string $errorContext
     *      Non-empty: throw exception on validation failure.
     *      Values: prepare|execute|failure
     *
     * @return bool|string
     *      True on success.
     *      String error details message on error.
     *
     * @throws DbQueryArgumentException
     *      If validation failure and non-empty arg $errorContext.
     */
    public function validateArgumentsNativeType(array $arguments, array $skipIndices = [], string $errorContext = '') {
        $native = $this->nativeTypes();

        if (!$this->validate) {
            $this->validate = Validate::getInstance();
        }

        $invalids = [];
        $index = -1;
        foreach ($arguments as $arg) {
            ++$index;
            if (!$skipIndices || !in_array($index, $skipIndices)) {
                $count = count($arg);
                if (!$count) {
                    $invalids[] = 'index[' . $index . '] is entirely empty';
                    continue;
                }
                
                $direction = $arg[1] ?? SQLSRV_PARAM_IN;
                if (
                    $direction !== SQLSRV_PARAM_IN && $direction !== SQLSRV_PARAM_INOUT
                    && $direction !== SQLSRV_PARAM_OUT
                ) {
                    $invalids[] = 'index[' . $index . '] direction bucket[1]'
                        . 'is not int SQLSRV_PARAM_IN|SQLSRV_PARAM_INOUT|SQLSRV_PARAM_OUT or null';
                    continue;
                }

                // In-param.----------------------------------------------------
                // Only validate in-param if any SQLSRV_SQLTYPE_* given;
                // otherwise Sqlsrv defaults to use an SQLSRV_SQLTYPE_*
                // matching the value's actual type.
                if (
                    ($direction == SQLSRV_PARAM_IN || $direction == SQLSRV_PARAM_INOUT)
                    && !empty($arg[3])
                ) {
                    $declared_type = $arg[3];
                    $value = $arg[0];
                    $em = '';
                    $type_supported = true;
                    switch ($declared_type) {
                        case $native['SQLTYPE_BIT']:
                            if (
                                (!is_int($value) || ($value != 0 && $value != 1))
                                && !is_bool($value)
                                && (!is_string($value) || ($value !== '0' && $value !== '1'))
                            ) {
                                $em = 'type[' . Utils::getType($value)
                                    . '] is neither integer 0|1 nor boolean nor string 0|1';
                                break;
                            }
                            break;
                        case $native['SQLTYPE_TINYINT']:
                        case $native['SQLTYPE_SMALLINT']:
                        case $native['SQLTYPE_INT']:
                        case $native['SQLTYPE_BIGINT']:
                            if (!is_int($value)) {
                                if ($value === '') {
                                    $em = 'empty string is neither integer nor stringed integer';
                                    break;
                                }
                                if (!is_string($value) || $this->validate->numeric($value) !== 'integer') {
                                    $em = 'type[' . Utils::getType($value)
                                        . '] is neither integer nor stringed integer';
                                    break;
                                }
                            }
                            switch ($declared_type) {
                                case $native['SQLTYPE_TINYINT']:
                                    if ($value < 0 || $value > 255) {
                                        $em = 'value[' . $value . '] is '
                                            . ($value < 0 ? 'less than zero' : 'more than 255');
                                    }
                                    break;
                                case $native['SQLTYPE_SMALLINT']:
                                    if ($value < -32768 || $value > 32767) {
                                        $em = 'value[' . $value . '] is '
                                            . ($value < 0 ? 'less than -32768' : 'more than 32767');
                                    }
                                    break;
                                case $native['SQLTYPE_INT']:
                                    if ($value < -2147483648 || $value > 2147483647) {
                                        $em = 'value[' . $value . '] is '
                                            . ($value < 0 ? 'less than -2147483648' : 'more than 2147483647');
                                    }
                                    break;
                                case $native['SQLTYPE_BIGINT']:
                                    if ($value < -pow(2, 63) || $value > pow(2, 63) - 1) {
                                        $em = 'value[' . $value . '] is '
                                            . ($value < 0 ? 'less than -2^63' : 'more than 2^31-1');
                                    }
                                    break;
                            }
                            break;
                        case $native['SQLTYPE_FLOAT']:
                        case $native['SQLTYPE_REAL']:
                        case $native['SQLTYPE_DECIMAL(14,2)']:
                            if (!is_float($value) && !is_int($value)) {
                                if ($value === '') {
                                    $em = 'empty string is neither number nor stringed number';
                                    break;
                                }
                                // Allow stringed number.
                                if (!is_string($value) || !$this->validate->numeric($value)) {
                                    $em = 'type[' . Utils::getType($value)
                                        . '] is neither number nor stringed number';
                                    break;
                                }
                            }
                            break;
                        case $native['SQLTYPE_VARCHAR']:
                        case $native['SQLTYPE_NVARCHAR']:
                        case $native['SQLTYPE_VARBINARY']:
                            // Camnot discern binary from non-binary string.
                            if (!is_string($value)) {
                                $valid = false;
                                switch (gettype($value)) {
                                    case 'integer':
                                    case 'double':
                                    case 'float':
                                        $valid = true;
                                        break;
                                    case 'object':
                                        /**
                                         * No __toString() check, because Sqlsrv
                                         * doesn't try to stringify objects;
                                         * except for \DateTime.
                                         *
                                         * @see MsSqlQuery::AUTO_STRINGIFIES_OBJECT
                                         */
                                        if (static::AUTO_STRINGABLE_CLASSES) {
                                            foreach (static::AUTO_STRINGABLE_CLASSES as $class_name) {
                                                if (is_a($value, $class_name)) {
                                                    $valid = true;
                                                    break;
                                                }
                                            }
                                        }
                                        break;
                                }
                                if (!$valid) {
                                    $em = 'type[' . Utils::getType($value)
                                        . '] is not string, integer, float or stringable object';
                                }
                            }
                            break;
                        case $native['SQLTYPE_TIME']:
                            // MsSql time requires seconds.
                            if (
                                !is_string($value)
                                || strlen($value) < 8 || $value{5} !== ':'
                                || !$this->validate->timeISO8601($value)
                            ) {
                                $em = 'type[' . Utils::getType($value)
                                    . '] is not string time IS0-8601';
                                break;
                            }
                            break;
                        case $native['SQLTYPE_DATE']:
                        case $native['SQLTYPE_DATETIME']:
                        case $native['SQLTYPE_DATETIME2']:
                            if (
                                !($value instanceof \DateTime)
                                && (!is_string($value) || !$this->validate->dateISO8601Local($value))
                            ) {
                                $em = 'type[' . Utils::getType($value)
                                    . '] is neither \DateTime nor string date IS0-8601 YYYY-MM-DD';
                                break;
                            }
                            break;
                        case $native['SQLTYPE_UNIQUEIDENTIFIER']:
                            if (!is_string($value) || !$this->validate->uuid($value)) {
                                $em = 'type[' . Utils::getType($value) . '] is a UUID';
                                break;
                            }
                            break;
                        default:
                            $type_supported = false;
                            $em = 'index[' . $index . '] in-param native type int[' . $declared_type
                                . '] is not supported, value type[' . Utils::getType($value) . ']';
                    }
                    if ($em) {
                        if ($type_supported) {
                            $em = 'index[' . $index . '] in-param[' . $this->nativeTypeName($declared_type) . '] '
                                . $em;
                        }
                        $invalids[] = $em;
                    }
                }

                // Out-param.---------------------------------------------------
                // Only validate out-param if any SQLSRV_PHPTYPE_* given;
                // otherwise Sqlsrv defaults to use an SQLSRV_PHPTYPE_*
                // matching the value's actual type.
                // @todo: haven't tested that Sqlsrv defaults to use an SQLSRV_PHPTYPE_* matching the value's actual type.
                if (
                    ($direction == SQLSRV_PARAM_INOUT || $direction == SQLSRV_PARAM_OUT)
                    && !empty($arg[2])
                ) {
                    $declared_type = $arg[2];
                    $value = $arg[0];
                    $em = '';
                    $type_supported = true;
                    switch ($declared_type) {
                        case $native['PHPTYPE_INT']:
                            if (!is_int($value)) {
                                if ($value === '') {
                                    $em = 'empty string is neither integer nor stringed integer';
                                    break;
                                }
                                if (!is_string($value) || $this->validate->numeric($value) !== 'integer') {
                                    $em = 'type[' . Utils::getType($value)
                                        . '] is neither integer nor stringed integer';
                                    break;
                                }
                            }
                            break;
                        case $native['PHPTYPE_FLOAT']:
                            if (!is_float($value) && !is_int($value)) {
                                if ($value === '') {
                                    $em = 'empty string is neither number nor stringed number';
                                    break;
                                }
                                // Allow stringed number.
                                if (!is_string($value) || !$this->validate->numeric($value)) {
                                    $em = 'type[' . Utils::getType($value)
                                        . '] is neither number nor stringed number';
                                    break;
                                }
                            }
                            break;
                        case $native['PHPTYPE_STRING(UTF-8)']:
                        case $native['PHPTYPE_STRING(SQLSRV_ENC_CHAR)']:
                        case $native['PHPTYPE_STRING(SQLSRV_ENC_BINARY)']:
                            // Camnot discern binary from non-binary string.
                            if (!is_string($value)) {
                                $valid = false;
                                switch (gettype($value)) {
                                    case 'integer':
                                    case 'double':
                                    case 'float':
                                        $valid = true;
                                        break;
                                    case 'object':
                                        /**
                                         * No __toString() check, because Sqlsrv
                                         * doesn't try to stringify objects;
                                         * except for \DateTime.
                                         *
                                         * @see MsSqlQuery::AUTO_STRINGIFIES_OBJECT
                                         */
                                        if (static::AUTO_STRINGABLE_CLASSES) {
                                            foreach (static::AUTO_STRINGABLE_CLASSES as $class_name) {
                                                if (is_a($value, $class_name)) {
                                                    $valid = true;
                                                    break;
                                                }
                                            }
                                        }
                                        break;
                                }
                                if (!$valid) {
                                    $em = 'type[' . Utils::getType($value)
                                        . '] is not string, integer, float or stringable object';
                                }
                            }
                            break;
                        case $native['PHPTYPE_DATETIME']:
                            if (
                                !($value instanceof \DateTime)
                                && (!is_string($value) || !$this->validate->dateISO8601Local($value))
                            ) {
                                $em = 'type[' . Utils::getType($value)
                                    . '] is neither \DateTime nor string date IS0-8601 YYYY-MM-DD';
                                break;
                            }
                            break;
                        default:
                            $type_supported = false;
                            $em = 'index[' . $index . '] out-param native type int[' . $declared_type
                                . '] is not supported, value type[' . Utils::getType($value) . ']';
                    }
                    if ($em) {
                        if ($type_supported) {
                            $em = 'index[' . $index . '] out-param[' . $this->nativeTypeName($declared_type) . '] '
                                . $em;
                        }
                        $invalids[] = $em;
                    }
                }
            }
        }
        if ($invalids) {
            if ($errorContext) {
                // Prepared statement, later execution.
                if ($this->nExecution > 1) {
                    // Unset prepared statement arguments reference.
                    $this->unsetReferences();
                }
                switch ($errorContext) {
                    case 'prepare':
                        $msg = ' - arg $arguments ';
                        break;
                    case 'execute':
                        $msg = $this->isPreparedStatement ?
                            (' - aborted prepared statement execution[' . $this->nExecution . '], argument ') :
                            ' - aborted simple query execution, argument ';
                        break;
                    default:
                        $msg = ' - argument ';
                }
                throw new DbQueryArgumentException(
                    $this->messagePrefix() . $msg . join(' | ', $invalids) . '.'
                );
            }
            return join(' | ', $invalids);
        }

        return true;
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
     * @throws DbQueryArgumentException
     */
    protected function adaptArguments(string $types, array &$arguments) /*: void*/
    {
        $n_params = count($arguments);
        if (!$n_params) {
            return;
        }

        // Use buckets arg $arguments directly if all args are type qualified.
        //
        // Otherwise arg $types - or actual type detection - for those buckets
        // that aren't type qualifying arrays.

        $all_fully_typed = true;
        // List of typed buckets; key is index, value is (bool) fully typed.
        $typed__fully = [];
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
                $all_fully_typed = false;
            }
            else {
                $count = count($arg);
                // An 'out' parameter must have 3th bucket,
                // containing SQLSRV_PHPTYPE_* constant.
                // An 'in' or 'inout' parameter must have 4th bucket,
                // containing SQLSRV_SQLTYPE_* constant.
                if (!$count) {
                    throw new DbQueryArgumentException(
                        $this->messagePrefix() . ' - arg $arguments bucket ' . $i . ' is empty array.'
                    );
                }
                if ($count == 1) {
                    // 0: Value only.
                    // 1: SQLSRV_PARAM_IN|SQLSRV_PARAM_INOUT|SQLSRV_PARAM_OUT.
                    $typed__fully[$i] = false;
                    $all_fully_typed = false;
                }
                if (!isset($arg[1])) {
                    $direction = SQLSRV_PARAM_IN;
                }
                else {
                    $direction = $arg[1];
                    if (
                        $direction !== SQLSRV_PARAM_IN && $direction !== SQLSRV_PARAM_INOUT
                        && $direction !== SQLSRV_PARAM_OUT
                    ) {
                        throw new DbQueryArgumentException(
                            $this->messagePrefix() . ' - arg $arguments direction bucket at index['
                            . $i . '][1] type[' . Utils::getType($arg[1])
                            . '] is not int SQLSRV_PARAM_IN|SQLSRV_PARAM_INOUT|SQLSRV_PARAM_OUT or null.'
                        );
                    }
                }
                switch ($direction) {
                    case SQLSRV_PARAM_IN:
                        if (!empty($arg[3])) {
                            // Non-empty 'in' SQLSRV_SQLTYPE_*.
                            $typed__fully[$i] = true;
                        } else {
                            $typed__fully[$i] = $all_fully_typed = false;
                        }
                        break;
                    case SQLSRV_PARAM_INOUT:
                        if (!empty($arg[2]) && !empty($arg[3])) {
                            // Non-empty 'out' SQLSRV_PHPTYPE_*
                            // and non-empty 'in' SQLSRV_SQLTYPE_*.
                            $typed__fully[$i] = true;
                        } else {
                            $typed__fully[$i] = $all_fully_typed = false;
                        }
                        break;
                    case SQLSRV_PARAM_OUT:
                        if (!empty($arg[2])) {
                            // Non-empty 'out' SQLSRV_PHPTYPE_*
                            $typed__fully[$i] = true;
                        } else {
                            $typed__fully[$i] = $all_fully_typed = false;
                        }
                        break;
                }
            }
        }

        // All arguments are fully type qualified - stop here.------------------
        if ($all_fully_typed) {
            if (($this->validateParams & DbQuery::VALIDATE_EXECUTE)) {
                // Throws exception on validation failure
                $this->validateArgumentsNativeType($arguments, [], 'prepare');
            }
            if ($this->isPreparedStatement) {
                // Support assoc array; sqlsrv_prepare() doesn't.
                // And prevent de-referencing when using an arguments list whose
                // value buckets aren't set as &$value.
                $args = [];
                $i = -1;
                foreach ($arguments as &$arg) {
                    $args[] = $arg;
                    $args[++$i][0] =& $arg[0];
                }
                unset($arg);
                $this->arguments['prepared'] =& $args;
            }
            // Simple statement; don't refer.
            // Support assoc array; sqlsrv_query() doesn't.
            else {
                $this->arguments['simple'] = array_values($arguments);
            }

            return;
        }

        // Some arguments aren't fully type qualified.--------------------------
        // Use arg $types to establish types of args that aren't arrays.
        $tps = $types;
        if ($tps === '') {
            // Detect types, except of buckets that are type qualified array.
            $tps = $this->parameterTypesDetect($arguments, array_keys($typed__fully));
        }
        elseif (strlen($types) != $n_params) {
            throw new DbQueryArgumentException(
                $this->messagePrefix() . ' - arg $types length[' . strlen($types)
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
            );
        }
        /**
         * Validate only $types, here;
         * checks by validateArgumentsNativeType() later, if required.
         * @see MsSqlQuery::validateArgumentsNativeType()
         */
        elseif (
            ($this->validateParams & DbQuery::VALIDATE_PREPARE)
            && ($valid = $this->validateTypes($types)) !== true
        ) {
            throw new DbQueryArgumentException(
                $this->messagePrefix() . ' - arg $types ' . $valid . '.'
            );
        }

        $is_prep_stat = $this->isPreparedStatement;
        $typed_args = [];
        $validation_skip_indexes = [];
        $i = -1;
        foreach ($arguments as &$arg) {
            ++$i;
            // Not array.
            if (!isset($typed__fully[$i])) {
                // Argumment is arg value only.
                // Use type char and perhaps value.
                $typed_args[] = [
                    null,
                    SQLSRV_PARAM_IN,
                    null,
                    $this->nativeTypeFromTypeString('in', $arg, $tps, $i),
                ];
                // Pass.
                if ($is_prep_stat) {
                    $typed_args[$i][0] = &$arg;
                } else {
                    $typed_args[$i][0] = $arg;
                }
            }
            // Array.
            else {
                if ($typed__fully[$i]) {
                    $typed_args[] = [
                        null,
                        $arg[1],
                        $arg[2],
                        $arg[3] ?? null,
                    ];
                }
                else {
                    // An 'out' parameter must have 3th bucket,
                    // containing SQLSRV_PHPTYPE_* constant.
                    // An 'in' or 'inout' parameter must have 4th bucket,
                    // containing SQLSRV_SQLTYPE_* constant.
                    if (count($arg) == 1) {
                        // 0: Value only.
                        $typed_args[] = [
                            null,
                            SQLSRV_PARAM_IN,
                            null,
                            $this->nativeTypeFromActualType('in', $arg[0], $i),
                        ];
                        // Don't validate typed established from actual type.
                        $validation_skip_indexes[] = $i;
                    }
                    else {
                        $out_type = $arg[2] ?? null;
                        $in_type = $arg[3] ?? null;
                        switch ($arg[1]) {
                            case SQLSRV_PARAM_IN:
                                if (!$in_type) {
                                    $in_type = $this->nativeTypeFromActualType('in', $arg[0], $i);
                                    $validation_skip_indexes[] = $i;
                                }
                                break;
                            case SQLSRV_PARAM_INOUT:
                                if (!$out_type) {
                                    $out_type = $this->nativeTypeFromActualType('out', $arg[0], $i);
                                    if (!$in_type) {
                                        $validation_skip_indexes[] = $i;
                                    }
                                }
                                if (!$in_type) {
                                    $in_type = $this->nativeTypeFromActualType('in', $arg[0], $i);
                                }
                                break;
                            case SQLSRV_PARAM_OUT:
                                if (!$out_type) {
                                    $out_type = $this->nativeTypeFromActualType('out', $arg[0], $i);
                                    $validation_skip_indexes[] = $i;
                                }
                                break;
                        }
                        $typed_args[] = [
                            null,
                            $arg[1],
                            $out_type,
                            $in_type,
                        ];
                    }
                }
                // Pass.
                if ($is_prep_stat) {
                    $typed_args[$i][0] = &$arg[0];
                } else {
                    $typed_args[$i][0] = $arg[0];
                }
            }
        }
        // Iteration ref.
        unset($arg);

        if (
            ($this->validateParams & DbQuery::VALIDATE_PREPARE)
            && (count($validation_skip_indexes) < $n_params)
        ) {
            // Throws exception on validation failure
            $this->validateArgumentsNativeType($typed_args, $validation_skip_indexes, 'prepare');
        }
        
        if ($this->isPreparedStatement) {
            $this->arguments['prepared'] =& $typed_args;
        } else {
            // If reusable.
            unset($this->arguments['simple']);
            // Don't refer; the prospect of a slight performance gain isn't
            // worth the risk.
            // A simple query's arguments shan't refer to the outside;
            // new arguments list before every execute().
            $this->arguments['simple'] = $typed_args;
        }
    }
}

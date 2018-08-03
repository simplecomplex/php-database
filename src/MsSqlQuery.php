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

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbResultInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbConnectionException;
use SimpleComplex\Database\Exception\DbQueryException;

/**
 * MS SQL query.
 *
 * Sqlsrv handles DateTimes transparently, as strings.
 *
 * Multi-query producing more results sets is not supported by MS SQL,
 * except when calling stored procedure. For multi vs. batch query, see:
 * @see DbQueryInterface
 *
 * Inherited properties:
 * @property-read string $id
 * @property-read int $execution
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
     * List of class names of objects that automatically gets stringed,
     * and accepted as strings, by the DBMS driver.
     *
     * @var string[]
     */
    const AUTO_STRINGABLE_CLASSES = [
        \DateTime::class,
    ];

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
                    $this->client->messagePrefix()
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

        $this->getInsertId = !empty($options['insert_id']);
        if ($this->getInsertId && stripos($sql, static::SQL_INSERT_ID) === false) {
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
                $this->client->messagePrefix() . ' - can\'t prepare statement more than once.'
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
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            $this->log(__FUNCTION__);
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
                $this->client->messagePrefix()
                . ' - passing parameters to prepared statement is illegal except via call to prepare().'
            );
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sqlTampered ?? $this->sql, $arguments);
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
     * @throws DbConnectionException
     *      Is prepared statement and connection lost.
     * @throws DbQueryException
     * @throws DbRuntimeException
     *      Failing to complete sending data as chunks.
     */
    public function execute(): DbResultInterface
    {
        ++$this->execution;

        // (Sqlsrv) Even a simple statement is a 'statement'.
        if ($this->statementClosed) {
            throw new \LogicException(
                $this->client->messagePrefix()
                . ' - can\'t execute previously closed statement.'
            );
        }

        if ($this->isPreparedStatement) {
            // Require unbroken connection.
            if (!$this->client->isConnected()) {
                $errors = $this->client->getErrors();
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $this->log(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->messagePrefix() . ' - can\'t execute prepared statement when connection lost, error: '
                    . $this->client->errorsToString($errors) . '.'
                );
            }
            // bool.
            if (!@sqlsrv_execute($this->statement)) {
                $errors = $this->client->getErrors();
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $this->log(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->messagePrefix() . ' - failed executing prepared statement, error: '
                    . $this->client->errorsToString($errors) . '.'
                );
            }
        }
        else {
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
                throw new $cls_xcptn(
                    $this->messagePrefix() . ' - failed executing simple query, error: '
                    . $this->client->errorsToString($errors) . '.'
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
            $error = $this->client->getErrors(Database::ERRORS_STRING_EMPTY_NONE);
            if ($error) {
                $errors = $this->client->getErrors();
                // Unset prepared statement arguments reference.
                $this->unsetReferences();
                $this->log(__FUNCTION__);
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
     * Get native SQLSRV_SQLTYPE_* constant equivalent of a type char and a value.
     *
     * SQLTYPE Constants:
     * @see https://docs.microsoft.com/en-us/sql/connect/php/constants-microsoft-drivers-for-php-for-sql-server
     *
     * Integers:
     * @see https://docs.microsoft.com/en-us/sql/t-sql/data-types/int-bigint-smallint-and-tinyint-transact-sql
     *
     * @param integer|float|string|\DateTime $value
     * @param string $types
     *      Chars: i|d|s|b|t.
     * @param int $index
     * @param bool $actualTypeChecked
     *      False: check that $value's actual type matches the type char.
     *
     * @return int
     *      SQLSRV_SQLTYPE_* constant.
     *
     * @throws \InvalidArgumentException
     *      Non-empty arg $typeChar isn't one of i, d, s, b.
     * @throws \RuntimeException
     *      Non-empty arg $typeChar doesn't arg $value type.
     */
    public function nativeType($value, string $types, int $index, bool $actualTypeChecked = false)
    {
        if ($index < 0 || $index >= strlen($types)) {
            throw new \OutOfRangeException(
                'Arg $index[' . $index . '] is not within range of arg $types length[' . strlen($types) . '].'
            );
        }
        switch ($types{$index}) {
            case 'i':
                if (!$actualTypeChecked && !is_int($value)) {
                    throw new \RuntimeException(
                        'Arg $types index[' . $index . '] char[' . $types{$index}
                            . '] doesn\'t match actual type[' . Utils::getType($value) . '].'
                    );
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
            case 'd':
                if ($actualTypeChecked && !is_float($value)) {
                    throw new \RuntimeException(
                        'Arg $types index[' . $index . '] char[' . $types{$index}
                            . '] doesn\'t match actual type[' . Utils::getType($value) . '].'
                    );
                }
                return SQLSRV_SQLTYPE_FLOAT;
            case 's':
                if (!$actualTypeChecked && !is_string($value)) {
                    /**
                     * Sending as string works, but let's play it correctly.
                     * @see MsSqlQuery::AUTO_STRINGABLE_CLASSES
                     */
                    if ($value instanceof \DateTime) {
                        return SQLSRV_SQLTYPE_DATETIME2;
                    }
                    throw new \RuntimeException(
                        'Arg $types index[' . $index . '] char[' . $types{$index}
                            . '] doesn\'t match actual type[' . Utils::getType($value) . '].'
                    );
                }
                return SQLSRV_SQLTYPE_VARCHAR('max');
            case 'b':
                if (!$actualTypeChecked && !is_string($value)) {
                    throw new \RuntimeException(
                        'Arg $types index[' . $index . '] char[' . $types{$index}
                            . '] doesn\'t match actual type[' . Utils::getType($value) . '].'
                    );
                }
                return SQLSRV_SQLTYPE_VARBINARY('max');
        }

        throw new \InvalidArgumentException(
            'Arg $types index[' . $index . '] char[' . $types{$index}
                . '] is not '. join('|', static::PARAMETER_TYPE_CHARS) . '.'
        );
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

        $all_args_type_qualified = true;
        $type_detection_skip_indexes = [];
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
                $all_args_type_qualified = false;
            }
            else {
                $type_detection_skip_indexes[] = $i;
                $count = count($arg);
                if (!$count) {
                    throw new \InvalidArgumentException(
                        $this->client->messagePrefix() . ' - arg $arguments bucket ' . $i . ' is empty array.'
                    );
                }
                // An 'in' parameter must have 4th bucket,
                // containing SQLSRV_SQLTYPE_* constant.
                if (
                    $count == 1
                    || ($arg[1] != SQLSRV_PARAM_OUT && ($count < 4 || !$arg[3]))
                ) {
                    $all_args_type_qualified = false;
                    break;
                }
            }
        }

        if ($all_args_type_qualified) {
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

        // Use arg $types to establish types.
        $tps = $types;
        $actual_types_checked = false;
        if ($tps === '') {
            // Detect types.
            $tps = $this->parameterTypesDetect($arguments, $type_detection_skip_indexes);
            $actual_types_checked = true;
        }
        elseif (strlen($types) != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->messagePrefix() . ' - arg $types length[' . strlen($types)
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
            );
        }
        elseif (
            $this->validateArguments
            // Validate only $types, here.
            && ($invalid = $this->argumentsInvalid($types))
        ) {
            throw new \InvalidArgumentException(
                $this->client->messagePrefix() . ' - ' . $invalid . '.'
            );
        }

        $is_prep_stat = $this->isPreparedStatement;
        $type_qualifieds = [];
        $i = -1;
        foreach ($arguments as &$arg) {
            ++$i;
            if (!is_array($arg)) {
                // Argumment is arg value only.
                // Use type char and perhaps value.
                $type_qualifieds[] = [
                    null,
                    SQLSRV_PARAM_IN,
                    null,
                    $this->nativeType($arg, $tps, $i, $actual_types_checked)
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
                        $arg[3] ?? (
                            $arg[1] == SQLSRV_PARAM_OUT ? null :
                                $this->nativeType($arg[0], $tps, $i, $actual_types_checked)
                        )
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
                        $count > 1 && $arg[1] == SQLSRV_PARAM_OUT ? null :
                            $this->nativeType($arg[0], $tps, $i, $actual_types_checked)
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

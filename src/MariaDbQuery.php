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
use SimpleComplex\Database\Exception\DbConnectionException;

/**
 * MariaDB query.
 *
 * Multi-query is supported by MariaDB.
 * For multi-query explanation, see:
 * @see DbClientMultiInterface
 *
 * Cursor mode 'store' is not supported for prepared statements (by this
 * implementation) because result binding is the only way to work with 'store'd
 * prepared statement results - and result binding sucks IMHO.
 *
 * Prepared statement requires the mysqlnd driver.
 * Because a result set will eventually be handled as \mysqli_result
 * via mysqli_stmt::get_result(); only available with mysqlnd.
 * @see http://php.net/manual/en/mysqli-stmt.get-result.php
 *
 * Properties inherited from DatabaseQuery:
 * @property-read string $id
 * @property-read int $execution
 * @property-read string $cursorMode
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 * @property-read bool $transactionStarted  Value of client ditto.
 *
 * Properties inherited from DatabaseQueryMulti:
 * @property-read bool $isMultiQuery
 * @property-read bool $isRepeatStatement
 * @property-read bool $sqlAppended
 *
 * @package SimpleComplex\Database
 */
class MariaDbQuery extends DatabaseQueryMulti
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
     * @var string
     */
    const CURSOR_USE = 'use';
    const CURSOR_READ_ONLY = 'read_only';
    const CURSOR_STORE = 'store';

    /**
     * Cursor modes.
     *
     * 'use':
     * - unbuffered
     * - heavy serverside, light clientside
     * - forbids getting number of rows until all rows have been retrieved
     * - MYSQLI_CURSOR_TYPE_NO_CURSOR
     *
     * 'read_only':
     * - unbuffered
     * - MYSQLI_CURSOR_TYPE_READ_ONLY
     * - spells segmentation fault
     *
     * 'store':
     * - buffered clientside
     * - light serverside, heavy clientside
     * - allows number of rows
     * - illegal for prepared statement in this implemention because useless
     *
     * @see http://php.net/manual/en/mysqli.use-result.php
     *
     * Store vs. use at Stackoverflow:
     * @see https://stackoverflow.com/questions/9876730/mysqli-store-result-vs-mysqli-use-result
     *
     * @var string[]
     */
    const CURSOR_MODES = [
        MariaDbQuery::CURSOR_USE,
        MariaDbQuery::CURSOR_READ_ONLY,
        MariaDbQuery::CURSOR_STORE,
    ];

    /**
     * Default cursor mode.
     *
     * @var string
     */
    const CURSOR_MODE_DEFAULT = MariaDbQuery::CURSOR_USE;

    /**
     * @var string[]
     */
    const SQL_SNIPPET = [
        'select_uuid' => 'SELECT UUID()',
    ];

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
     * @see MariaDbQuery::CURSOR_MODES
     * @see MariaDbQuery::CURSOR_MODE_DEFAULT
     *
     * @var string
     */
    protected $cursorMode;

    /**
     * Create query.
     *
     * Option num_rows may override default cursor mode (not option cursor_mode)
     * and adjust to support result numRows().
     * Option affected_rows is ignored, irrelevant.
     *
     * @param DbClientInterface|DatabaseClient|MariaDbClient $client
     *      Reference to parent client.
     * @param string $sql
     * @param array $options {
     *      @var string $cursor_mode
     *      @var bool $num_rows  May adjust cursor mode to 'store'.
     *      @var bool $is_multi_query
     *          True: arg $sql contains multiple queries.
     * }
     *
     * @throws \InvalidArgumentException
     *      Propagated; arg $sql empty.
     *      Unsupported 'cursor_mode'.
     */
    public function __construct(DbClientInterface $client, string $sql, array $options = [])
    {
        parent::__construct($client, $sql, $options);

        /**
         * Option is_multi_query is handled by parent
         * @see DatabaseQueryMulti::__constructor()
         */

        if (!empty($options['cursor_mode'])) {
            if (!in_array($options['cursor_mode'], static::CURSOR_MODES, true)) {
                throw new \InvalidArgumentException(
                    $this->client->errorMessagePrefix()
                    . ' query option \'cursor_mode\' value[' . $options['cursor_mode'] . '] is invalid.'
                );
            }
            $this->cursorMode = $options['cursor_mode'];
        }
        elseif (!empty($options['num_rows'])) {
            $this->cursorMode = MariaDbQuery::CURSOR_STORE;
        }
        else {
            $this->cursorMode = static::CURSOR_MODE_DEFAULT;
        }

        // Re-connect requires client buffered cursor mode.
        if ($this->cursorMode != MariaDbQuery::CURSOR_STORE) {
            $this->client->reConnectDisable();
        }
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
     * Chainable.
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
     * @throws \LogicException
     *      Method called more than once for this query.
     *      Cursor mode is 'store'; illegal for prepared statement.
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     * @throws Exception\DbConnectionException
     *      Propagated.
     * @throws DbRuntimeException
     *      Failure to bind $arguments to native layer.
     */
    public function prepare(string $types, array &$arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            throw new \LogicException(
                $this->client->errorMessagePrefix() . ' - can\'t prepare statement more than once.'
            );
        }
        $this->isPreparedStatement = true;

        /**
         * Cursor mode 'store' is not supported for prepared statements
         * by this implementation, because useless.
         * @see MariaDbQuery
         */
        if ($this->cursorMode == MariaDbQuery::CURSOR_STORE) {
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            throw new \LogicException(
                $this->client->errorMessagePrefix()
                . ' - cursor mode \'' . MariaDbQuery::CURSOR_STORE . '\' is illegal for prepared statement.'
            );
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sqlTampered ?? $this->sql, $arguments);
        if (!$sql_fragments) {
            unset($sql_fragments);
            $n_params = 0;
            $tps = '';
        }
        else {
            $n_params = count($sql_fragments) - 1;
            unset($sql_fragments);

            $tps = $types;
            if ($tps === '') {
                // Be friendly, all strings.
                $tps = str_repeat('s', $n_params);
            }
            elseif (strlen($types) != $n_params) {
                $this->log(__FUNCTION__);
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
        }

        // Allow re-connection.
        $mysqli = $this->client->getConnection(true);
        $mysqli_stmt = null;
        if ($mysqli) {
            /** @var \mysqli_stmt|bool $mysqli_stmt */
            $mysqli_stmt = @$mysqli->prepare($this->sql);
        }
        if (!$mysqli_stmt) {
            $errors = $this->getErrors();
            $this->log(__FUNCTION__);
            $cls_xcptn = $this->client->errorsToException($errors);
            throw new $cls_xcptn(
                $this->errorMessagePrefix() . ' - failed to prepare statement, error: '
                . $this->client->errorsToString($errors) . '.'
            );
        }
        $this->statementClosed = false;
        $this->statement = $mysqli_stmt;

        if ($this->cursorMode == MariaDbQuery::CURSOR_READ_ONLY) {
            $this->statement->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY);
            $errors = $this->getErrors();
            if ($errors) {
                $this->log(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->errorMessagePrefix() . ' - query failed to set cursor mode \''
                    . MariaDbQuery::CURSOR_READ_ONLY . '\', error: ' . $this->client->errorsToString($errors) . '.'
                );
            }
        }

        if ($n_params) {
            // Support assoc array; \mysqli_stmt::bind_param() doesn't.
            // And prevent de-referencing when using an arguments list whose
            // value buckets aren't set as &$value.
            $args = [];
            foreach ($arguments as &$arg) {
                $args[] =& $arg;
            }
            unset($arg);
            $this->arguments['prepared'] =& $args;

            if (!@$mysqli_stmt->bind_param($tps, ...$this->arguments['prepared'])) {
                $errors = $this->getErrors();
                // Unset prepared statement arguments reference.
                $this->closeAndLog(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->errorMessagePrefix() . ' - failed to bind parameters to prepared statement, error: '
                    . $this->client->errorsToString($errors) . '.'
                );
            }
        }

        return $this;
    }

    /**
     * Non-prepared statement: set query arguments, for direct parameter marker
     * substitution in the sql string.
     *
     * @see DatabaseQueryMulti::parameters()
     * @see DatabaseQuery::parameters()
     */
    // public function parameters(string $types, array $arguments) : DbQueryInterface

    /**
     * Non-prepared statement: repeat base sql, and substitute it's parameter
     * markers by arguments.
     *
     * @see DatabaseQueryMulti::repeat()
     */
    // public function repeat(string $types, array $arguments) : DbQueryMultiInterface

    /**
     * Non-prepared statement: append sql to previously defined sql.
     *
     * @see DatabaseQueryMulti::append()
     */
    // public function append(string $sql, string $types, array $arguments) : DbQueryMultiInterface

    /**
     * Any query must be executed, even non-prepared statement.
     *
     * Actual execution
     * ----------------
     * Prepared statement:
     * @see \mysqli_stmt::execute()
     * Multi-query:
     * @see \MySQLi::multi_query()
     * Simple query:
     * @see \MySQLi::real_query()
     *
     * @return DbResultInterface|MariaDbResult
     *
     * @throws \LogicException
     *      Is prepared statement and the statement is previously closed.
     * @throws DbConnectionException
     *      Is prepared statement and connection lost.
     * @throws DbRuntimeException
     */
    public function execute(): DbResultInterface
    {
        ++$this->execution;

        if ($this->isPreparedStatement) {
            // (MySQLi) Only a prepared statement is a 'statement'.
            if ($this->statementClosed) {
                throw new \LogicException(
                    $this->client->errorMessagePrefix()
                    . ' - can\'t execute previously closed prepared statement.'
                );
            }
            // Require unbroken connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection();
            if (!$mysqli) {
                $errors = $this->getErrors();
                // Unset prepared statement arguments reference.
                $this->closeAndLog(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->client->errorMessagePrefix()
                    . ' - can\'t execute prepared statement'
                    . (isset($errors[2014]) ? ', forgot to exhaust/free result set(s) of another query?' :
                        ' when connection lost')
                    . ', error: ' . $this->client->errorsToString($errors) . '.'
                );
            }

            /**
             * Reset to state after prepare, if CURSOR_TYPE_READ_ONLY.
             * @see https://dev.mysql.com/doc/refman/8.0/en/mysql-stmt-attr-set.html
             */
            if ($this->execution && $this->cursorMode == MariaDbQuery::CURSOR_READ_ONLY) {
                $this->statement->reset();
                $errors = $this->getErrors();
                if ($errors) {
                    $this->log(__FUNCTION__);
                    $cls_xcptn = $this->client->errorsToException($errors);
                    throw new $cls_xcptn(
                        $this->errorMessagePrefix() . ' - failed to reset prepared statement, error: '
                        . $this->client->errorsToString($errors) . '.'
                    );
                }
            }

            // bool.
            if (!@$this->statement->execute()) {
                $errors = $this->getErrors();
                // Unset prepared statement arguments reference.
                $this->closeAndLog(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->errorMessagePrefix() . ' - failed executing prepared statement, error: '
                    . $this->client->errorsToString($errors) . '.'
                );
            }
        }
        elseif ($this->isMultiQuery) {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection(true);
            if (
                !$mysqli
                // bool.
                || !@$mysqli->multi_query($this->sqlTampered ?? $this->sql)
                || @$mysqli->errno
            ) {
                // Use MariaDb::getErrors() because not statement.
                $errors = $this->client->getErrors();
                $this->log(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->errorMessagePrefix() . ' - failed executing multi-query, error: '
                    . $this->client->errorsToString($errors) . '.'
                );
            }
        }
        else {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection(true);
            if (
                !$mysqli
                // bool.
                || !@$mysqli->real_query($this->sqlTampered ?? $this->sql)
            ) {
                // Use MariaDb::getErrors() because not statement.
                $errors = $this->client->getErrors();
                $this->log(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->errorMessagePrefix() . ' - failed executing simple query, error: '
                    . $this->client->errorsToString($errors) . '.'
                );
            }
        }

        $class_result = static::CLASS_RESULT;
        /** @var DbResultInterface|MariaDbResult */
        return new $class_result($this, $mysqli, $this->statement);
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
        $this->unsetReferences();
        if ($this->statementClosed === false) {
            $this->statementClosed = true;
            if ($this->statement) {
                @$this->statement->close();
                $this->statement = null;
            }
        }
    }


    // Helpers.-----------------------------------------------------------------

    /**
     * Parameter value escaper.
     *
     * Escapes %_ unless instance var hasLikeClause.
     *
     * @param string $str
     *
     * @return string
     *
     * @throws DbConnectionException
     */
    public function escapeString(string $str) : string
    {
        // Allow re-connection.
        $mysqli = $this->client->getConnection(true);
        if (!$mysqli) {
            $errors = $this->getErrors();
            // Unset prepared statement arguments reference.
            $this->closeAndLog(__FUNCTION__);
            throw new DbConnectionException(
                $this->client->errorMessagePrefix() . ' - query can\'t escape string when connection lost, error: '
                . $this->client->errorsToString($errors) . '.'
            );
        }

        $s = $mysqli->real_escape_string($str);

        return $this->hasLikeClause ? $s : addcslashes($s, '%_');
    }

    /**
     * Get RMDBS/driver native error(s) recorded as array,
     * concatenated string or empty string.
     *
     * Query must append to (client) general errors, because \mysqli_stmt
     * has own separate error list.
     *
     * @see MariaDbClient::getErrors()
     * @see DatabaseClient::formatNativeErrors()
     *
     * @param int $toString
     *      1: on no error returns message indication just that.
     *      2: on no error return empty string.
     *
     * @return array|string
     *      Array: key is error code.
     */
    public function getErrors(int $toString = 0)
    {
        $list = $this->client->getErrors();
        if ($this->statement && ($errors = $this->statement->error_list)) {
            $append = [];
            foreach ($errors as $error) {
                $append[] = [
                    'code' => $error['errno'] ?? 0,
                    'sqlstate' => $error['sqlstate'] ?? '00000',
                    'msg' => $error['error'] ?? '',
                ];
            }
            unset($errors);
            $append = $this->client->formatNativeErrors($append);
            if (!$list) {
                $list =& $append;
            } else {
                foreach ($append as $code => $error) {
                    if (!array_key_exists($code, $list)) {
                        $list[$code] = $error;
                    }
                }
            }
        }
        return !$toString ? $list :
            $this->client->errorsToString($list, $toString == Database::ERRORS_STRING_EMPTY_NONE);
    }
}

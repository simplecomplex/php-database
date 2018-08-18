<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbResultInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbQueryArgumentException;
use SimpleComplex\Database\Exception\DbConnectionException;

/**
 * MariaDB query.
 *
 *
 * Prepared statement are 'use' and num-rows unavailable
 * -----------------------------------------------------
 * Result mode 'store' is not supported for prepared statements (by this
 * implementation) because result binding is MySQLi's only way to work with
 * 'store'd prepared statement results - and result binding sucks IMHO.
 * @see \mysqli_stmt::get_result()
 * Getting number of rows isn't possible in result mode 'use', so you can't
 * get num rows for prepared statement.
 *
 *
 * Multi-query
 * -----------
 * Multi-query is supported by MariaDB; for multi vs. batch query, see:
 * @see DbQuery
 * Multi-query is even required for batch query; multiple non-selecting queries.
 * Calling a single stored procedure does NOT require multi-query, not even for
 * stored procedure returning more result sets.
 * Using multi-query in production is probably a mistake. A prepared statement
 * calling a stored procedure is safer.
 *
 * NB: An error in a multi-query might not be detected until all result sets
 * have been next'ed; seen when attempting to truncate disregarding foreign key.
 * @see MariaDbResult::nextSet()
 *
 *
 * Argument object stringification
 * -------------------------------
 * MySQLi attempts to stringify object as query argument, but doesn't check
 * if any __toString() method.
 *
 *
 * Prepared statement requires the mysqlnd driver.
 * Because a result set will eventually be handled as \mysqli_result
 * via mysqli_stmt::get_result(); only available with mysqlnd.
 * @see http://php.net/manual/en/mysqli-stmt.get-result.php
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
 * @property-read int $multiQuery  True if >=4.
 * @property-read bool $sqlAppended
 *
 * @package SimpleComplex\Database
 */
class MariaDbQuery extends DbQuery
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
     * MySQLi attempts to stringify object, and fails (fatally)
     * if no __toString() method.
     *
     * @see DbQuery::AUTO_STRINGIFIES_OBJECT
     *
     * @var int
     */
    const AUTO_STRINGIFIES_OBJECT = 1;

    /**
     * Validate on failure + check for non-stringables before execution.
     *
     * Option (int) validate_params overrules.
     *
     * Doing check for non-stringable object is highly recommended
     * because MySQLi prepared statement execute exits when stumbling upon
     * a non-stringable object.
     * It doesn't event throw an E_* error; simply stops script execution.
     * Observed with stdClass and \DateTime.
     *
     * @see mysqli_stmt::execute()
     * @see DbQuery::AUTO_STRINGIFIES_OBJECT
     * @see DbQuery::VALIDATE_STRINGABLE_EXEC
     */
    const VALIDATE_PARAMS = DbQuery::VALIDATE_FAILURE | DbQuery::VALIDATE_STRINGABLE_EXEC;

    /**
     * @var string
     */
    const CURSOR_USE = 'use';
    const CURSOR_READ_ONLY = 'read_only';
    const CURSOR_STORE = 'store';

    /**
     * Result modes/cursor types.
     *
     * 'use':
     * - unbuffered
     * - number of rows forbidden
     * - MYSQLI_CURSOR_TYPE_NO_CURSOR
     *
     * 'read_only':
     * - unbuffered
     * - MYSQLI_CURSOR_TYPE_READ_ONLY
     * - spells segmentation fault
     *
     * 'store':
     * - buffered client side; light server side, heavy client side
     * - illegal for prepared statement in this implementation
     *
     * @see http://php.net/manual/en/mysqli.use-result.php
     *
     * Store vs. use at Stackoverflow:
     * @see https://stackoverflow.com/questions/9876730/mysqli-store-result-vs-mysqli-use-result
     *
     * @var string[]
     */
    const RESULT_MODES = [
        MariaDbQuery::CURSOR_USE,
        MariaDbQuery::CURSOR_READ_ONLY,
        MariaDbQuery::CURSOR_STORE,
    ];

    /**
     * Default result mode.
     *
     * @var string
     */
    const RESULT_MODE_DEFAULT = MariaDbQuery::CURSOR_USE;

    /**
     * Auto-detect multi-query; check for semicolon in sql.
     *
     * Do turn off multi-query detection when creating stored procedure;
     * may well contain statement-terminating semicolons, which shan't
     * be interpreted as query separator.
     *
     * BEWARE of using literal parameter values in arg $sql; a value containing
     * semicolon _will_ trigger multi-query auto-detection.
     * Do stick to passing all parameter values to prepare() or parameters().
     *
     * Query option detect_multi overrides this constant.
     * @see MariaDbClient::OPTIONS_SPECIFIC
     * @see MariaDbClient::__construct()
     *
     * @var bool
     */
    const DETECT_MULTI = true;

    /**
     * RMDBS specific query options supported, adding to generic options.
     *
     * Specific options:
     * - multi_query: sql contains more queries, or calls a stored procedure
     * - detect_query: auto-detect multi-query, check for semicolon in sql
     *
     * BEWARE of using literal parameter values in arg $sql; a value containing
     * semicolon _will_ trigger multi-query auto-detection.
     * Do stick to passing all parameter values to prepare() or parameters().
     *
     * @see DbQuery::OPTIONS_GENERIC
     *
     * @var string[]
     */
    const OPTIONS_SPECIFIC = [
        'multi_query',
        'detect_multi',
    ];

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
     * Option (str) result_mode.
     *
     * @see MariaDbQuery::RESULT_MODES
     * @see MariaDbQuery::RESULT_MODE_DEFAULT
     *
     * @var string
     */
    protected $resultMode;

    /**
     * Whether multi-query (or batch query); true if >=4.
     *
     * Values, bit mask logic:
     * - zero: no, and no auto-detection
     * - 1: auto-detection on by class constant default
     * - 2: auto-detection on by query option
     * - 4: turned on by option multi_query
     * - 8: turned on by behaviour; like the append() method
     * - 17: (1 + 16) auto-detected, triggered by constant default
     * - 18: (2 + 16) auto-detected, triggerd by query option
     *
     * @var int
     */
    protected $multiQuery = 0;

    /**
     * @var bool
     */
    protected $sqlAppended = false;

    /**
     * Create query.
     *
     * Option num_rows may override default result mode (not option result_mode)
     * and adjust to support result numRows().
     *
     * Allowed options:
     * @see DbQuery::OPTIONS_GENERIC
     * @see MariaDbQuery::OPTIONS_SPECIFIC
     * @see MsSqlQuery::OPTIONS_SPECIFIC
     *
     * @param DbClientInterface|DbClient|MariaDbClient $client
     *      Reference to parent client.
     * @param string $sql
     * @param array $options {
     *      @var string $cursor_mode
     *      @var bool $num_rows  May adjust cursor mode to 'store'.
     *      @var bool $detect_multi
     *          Turn multi-query auto-detection on/off.
     *      @var bool $multi_query
     *          True: arg $sql contains multiple queries.
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
        elseif (!empty($options['num_rows'])) {
            $this->resultMode = MariaDbQuery::CURSOR_STORE;
        }
        else {
            $this->resultMode = static::RESULT_MODE_DEFAULT;
        }

        // Re-connect requires client buffered result mode.
        if ($this->resultMode != MariaDbQuery::CURSOR_STORE) {
            $this->client->reConnectDisable();
        }

        /**
         * Is multi-query (or batch query)?
         *
         * Values, bit mask logic:
         * - zero: no, and no auto-detection
         * - 1: auto-detection on by class constant default
         * - 2: auto-detection on by query option
         * - 4: turned on by option multi_query
         * - 8: turned on by behaviour; call to append()
         * - 17: (1 + 16) auto-detected, triggered by constant default
         * - 18: (2 + 16) auto-detected, triggerd by query option
         */
        if (!empty($options['multi_query'])) {
            $this->multiQuery = 4;
        }
        elseif (isset($options['detect_multi'])) {
            if ($options['detect_multi']) {
                // query option + detected >< just query option.
                $this->multiQuery = strpos($this->sql, ';') ? 18 : 2;
            }
            // Otherwise false option rules out class constant.
        }
        elseif (static::DETECT_MULTI) {
            // constant default + detected >< just constant default.
            $this->multiQuery = strpos($this->sql, ';') ? 17 : 1;
        }

        $this->explorableIndex[] = 'multiQuery';
        $this->explorableIndex[] = 'sqlAppended';
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
     *      Empty: uses $arguments' actual types.
     * @param array &$arguments
     *      By reference.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \LogicException
     *      Method called more than once for this query.
     *      Result mode is 'store'; illegal for prepared statement.
     * @throws DbQueryArgumentException
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     *      On $types or $arguments validation failure.
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
                $this->messagePrefix() . ' - can\'t prepare statement more than once.'
            );
        }
        $this->isPreparedStatement = true;

        /**
         * Result mode 'store' is not supported for prepared statements
         * by this implementation, because useless.
         * @see MariaDbQuery
         */
        if ($this->resultMode == MariaDbQuery::CURSOR_STORE) {
            // Unset prepared statement arguments reference.
            $this->unsetReferences();
            throw new \LogicException(
                $this->messagePrefix()
                . ' - result mode \'' . MariaDbQuery::CURSOR_STORE . '\' is illegal for prepared statement.'
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
            if (!$tps) {
                // Detect types.
                $tps = $this->parameterTypesDetect($arguments);
            }
            elseif (strlen($types) != $n_params) {
                $this->log(__FUNCTION__);
                throw new DbQueryArgumentException(
                    $this->messagePrefix() . ' - arg $types length[' . strlen($types)
                    . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
                );
            }
            else if (($this->validateParams & DbQuery::VALIDATE_PREPARE)) {
                if (($valid_or_msg = $this->validateTypes($types)) !== true) {
                    throw new DbQueryArgumentException(
                        $this->messagePrefix() . ' - arg $types ' . $valid_or_msg . '.'
                    );
                }
                // Throws exception on failure.
                $this->validateArguments($types, $arguments, 'prepare');
            }
            // Record for execute(); validateParams: 1|3.
            $this->parameterTypes = $tps;
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
                $this->messagePrefix() . ' - failed to prepare statement, error: '
                . $this->client->errorsToString($errors) . '.'
            );
        }
        $this->statementClosed = false;
        $this->statement = $mysqli_stmt;

        if ($this->resultMode == MariaDbQuery::CURSOR_READ_ONLY) {
            $this->statement->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY);
            $errors = $this->getErrors();
            if ($errors) {
                $this->log(__FUNCTION__);
                $cls_xcptn = $this->client->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->messagePrefix() . ' - query failed to set result mode \''
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
                    $this->messagePrefix() . ' - failed to bind parameters to prepared statement, error: '
                    . $this->client->errorsToString($errors) . '.'
                );
            }
        }

        return $this;
    }

    /**
     * Non-prepared statement: set query arguments, for direct substition
     * in the sql string.
     *
     * The base sql remains reusable - if option reusable - allowing more
     * ->parameters()->execute(), much like a prepared statement
     * (except arguments aren't referred).
     *
     * @see DbQuery::parameters()
     *
     * @param string $types
     *      Empty: uses $arguments' actual types.
     * @param array $arguments
     *      Values to substitute sql parameter markers with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \LogicException
     *      Another sql string has been appended to base sql.
     *      Propagated.
     * @throws DbQueryArgumentException
     *      Propagated.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->sqlAppended) {
            throw new \LogicException(
                $this->messagePrefix()
                . ' - passing parameters to base sql is illegal after another sql string has been appended.'
            );
        }

        return parent::parameters($types, $arguments);
    }

    /**
     * Non-prepared statement: append sql to previously defined sql.
     *
     * Turns the full query into multi-query.
     *
     * Chainable.
     *
     * @param string $sql
     * @param string $types
     *      Empty: uses $arguments' actual types.
     * @param array $arguments
     *      Values to substitute sql parameter markers with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \LogicException
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Arg $sql empty.
     * @throws DbQueryArgumentException
     *      Propagated; parameters/arguments count mismatch.
     */
    public function append(string $sql, string $types, array $arguments) : DbQueryInterface
    {
        $sql_appendix = trim($sql, static::SQL_TRIM);
        if ($sql_appendix) {
            throw new \InvalidArgumentException(
                $this->messagePrefix() . ' - arg $sql length[' . strlen($sql) . '] is effectively empty.'
            );
        }

        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new \LogicException(
                $this->messagePrefix() . ' - appending to prepared statement is illegal.'
            );
        }

        /**
         * @see MariaDbQuery::$multiQuery
         */
        $this->multiQuery = 8;
        $this->sqlAppended = true;

        if (!$this->sqlTampered) {
            // First time appending.
            $this->sqlTampered = $this->sql;
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($sql_appendix, $arguments);

        $this->sqlTampered .= '; ' . (
            !$sql_fragments ? $sql_appendix :
                $this->substituteParametersByArgs($sql_fragments, $types, $arguments)
            );

        return $this;
    }

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
     *      Repeated execution of simple query without truthy option reusable
     *      and intermediate call to parameters().
     * @throws DbConnectionException
     *      Is prepared statement and connection lost.
     * @throws DbRuntimeException
     */
    public function execute(): DbResultInterface
    {
        ++$this->nExecution;

        if ($this->isPreparedStatement) {
            // Validate arguments before execution?.
            if ($this->validateParams && $this->parameterTypes && !empty($this->arguments['prepared'])) {
                if (($this->validateParams & DbQuery::VALIDATE_EXECUTE)) {
                    // Throws exception on validation failure.
                    $this->validateArguments($this->parameterTypes, $this->arguments['prepared'], 'execute');
                }
                elseif (($this->validateParams & DbQuery::VALIDATE_STRINGABLE_EXEC)) {
                    // Throws exception on validation failure.
                    $this->validateArgumentsStringable(
                        $this->parameterTypes, $this->arguments['prepared'], 'execute'
                    );
                }
            }

            // (MySQLi) Only a prepared statement is a 'statement'.
            if ($this->statementClosed) {
                throw new \LogicException(
                    $this->messagePrefix()
                    . ' - can\'t do execution[' . $this->nExecution . '] on previously closed prepared statement.'
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
                    $this->messagePrefix()
                    . ' - can\'t do execution[' . $this->nExecution . '] of prepared statement'
                    . (isset($errors[2014]) ? ', forgot to exhaust/free result set(s) of another query?' :
                        ' when connection lost')
                    . ', error: ' . $this->client->errorsToString($errors) . '.'
                );
            }

            /**
             * Reset to state after prepare, if CURSOR_TYPE_READ_ONLY.
             * @see https://dev.mysql.com/doc/refman/8.0/en/mysql-stmt-attr-set.html
             */
            if ($this->nExecution > 1 && $this->resultMode == MariaDbQuery::CURSOR_READ_ONLY) {
                $this->statement->reset();
                $errors = $this->getErrors();
                if ($errors) {
                    $this->log(__FUNCTION__);
                    $cls_xcptn = $this->client->errorsToException($errors);
                    throw new $cls_xcptn(
                        $this->messagePrefix()
                        . ' - failed to reset prepared statement for execution[' . $this->nExecution . '], error: '
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
                // Validate parameters on query failure.
                if (
                    ($this->validateParams & DbQuery::VALIDATE_FAILURE)
                    && !empty($this->arguments['prepared']) && $cls_xcptn != DbConnectionException::class
                ) {
                    if ((
                        $valid_or_msg = $this->validateArguments($this->parameterTypes, $this->arguments['prepared'])
                        ) !== true
                    ) {
                        $msg = 'parameter error: ' . $valid_or_msg . '. DBMS error: ';
                    }
                    else {
                        $msg = 'no parameter error observed, DBMS error: ';
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

            // Validate arguments before execution?.
            if ($this->validateParams && $this->parameterTypes && !empty($this->arguments['simple'])) {
                if (($this->validateParams & DbQuery::VALIDATE_EXECUTE)) {
                    // Throws exception on validation failure.
                    $this->validateArguments($this->parameterTypes, $this->arguments['simple'], 'execute');
                }
                elseif (($this->validateParams & DbQuery::VALIDATE_STRINGABLE_EXEC)) {
                    // Throws exception on validation failure.
                    $this->validateArguments($this->parameterTypes, $this->arguments['simple'], 'execute');
                }
            }

            if ($this->multiQuery >= 4) {
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
                    // Validate parameters on query failure.
                    if (
                        ($this->validateParams & DbQuery::VALIDATE_FAILURE)
                        && $this->parameterTypes && !empty($this->arguments['simple'])
                        && $cls_xcptn != DbConnectionException::class
                    ) {
                        if ((
                            $valid_or_msg = $this->validateArguments($this->parameterTypes, $this->arguments['simple'])
                            ) !== true
                        ) {
                            $msg = 'parameter error: ' . $valid_or_msg . '. DBMS error: ';
                        } else {
                            $msg = 'no parameter error observed, DBMS error: ';
                        }
                    } else {
                        $msg = 'error: ';
                    }
                    throw new $cls_xcptn(
                        $this->messagePrefix() . ' - failed executing multi-query, '
                        . $msg . $this->client->errorsToString($errors) . '.'
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
                    // Validate parameters on query failure.
                    if (
                        ($this->validateParams & DbQuery::VALIDATE_FAILURE)
                        && $this->parameterTypes && !empty($this->arguments['simple'])
                        && $cls_xcptn != DbConnectionException::class
                    ) {
                        if ((
                            $valid_or_msg = $this->validateArguments($this->parameterTypes, $this->arguments['simple'])
                            ) !== true
                        ) {
                            $msg = 'parameter error: ' . $valid_or_msg . '. DBMS error: ';
                        } else {
                            $msg = 'no parameter error observed, DBMS error: ';
                        }
                    } else {
                        $msg = 'error: ';
                    }
                    throw new $cls_xcptn(
                        $this->messagePrefix() . ' - failed executing simple query, '
                        . $msg . $this->client->errorsToString($errors) . '.'
                    );
                }
            }
        }

        $class_result = static::CLASS_RESULT;
        /** @var DbResultInterface|MariaDbResult */
        return new $class_result($this, $mysqli, $this->statement);
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
                $this->messagePrefix() . ' - query can\'t escape string when connection lost, error: '
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
     * @see DbClient::formatNativeErrors()
     * @see DbError::AS_STRING
     * @see DbError::AS_STRING_EMPTY_ON_NONE
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
            $this->client->errorsToString($list, $toString == DbError::AS_STRING_EMPTY_ON_NONE);
    }
}

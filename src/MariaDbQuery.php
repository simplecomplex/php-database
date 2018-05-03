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
 * Maria DB query.
 *
 * Multi-query is supported by Maria DB.
 * For multi-query explanation, see:
 * @see DbClientMultiInterface
 *
 * NB: Prepared statement requires the mysqlnd driver.
 * Because a result set will eventually be handled as \mysqli_result
 * via mysqli_stmt::get_result(); only available with mysqlnd.
 * @see http://php.net/manual/en/mysqli-stmt.get-result.php
 *
 * Inherited properties:
 * @property-read string $id
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 * @property-read bool $isMultiQuery
 * @property-read bool $isRepeatStatement
 * @property-read bool $sqlAppended
 *
 * Own read-onlys:
 * @property-read string $cursorMode
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
     * @param string $sql
     * @param array $options {
     *      @var string $cursor_mode
     *          Ignored if making prepared statement; will always be 'store'.
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
                $this->client->errorMessagePrefix() . ' - query cannot prepare statement more than once.'
            );
        }
        $this->isPreparedStatement = true;
        // Prepared statement's result will be store'd
        // - via \mysqli_stmt::get_result() - because \mysqli_stmt misses
        // result methods like fetch_array().
        $this->cursorMode = 'store';

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sql, $arguments);
        $n_params = count($sql_fragments) - 1;
        unset($sql_fragments);

        $tps = $types;
        if ($n_params) {
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
        }

        // Allow re-connection.
        $mysqli = $this->client->getConnection(true);

        /** @var \mysqli_stmt|bool $mysqli_stmt */
        $mysqli_stmt = @$mysqli->prepare($this->sql);
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
     * @throws DbInterruptionException
     *      Is prepared statement and connection lost.
     * @throws DbQueryException
     */
    public function execute(): DbResultInterface
    {
        if ($this->isPreparedStatement) {
            // (MySQLi) Only a prepared statement is a 'statement'.
            if ($this->statementClosed) {
                throw new \LogicException(
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
            if (!@$mysqli->multi_query($this->sqlTampered ?? $this->sql)) {
                $this->log(__FUNCTION__);
                throw new DbQueryException(
                    $this->errorMessagePrefix()
                    . ' - failed executing multi-query, with error: ' . $this->client->nativeError() . '.'
                );
            }
        }
        else {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $mysqli = $this->client->getConnection(true);
            // bool.
            if (!@$mysqli->real_query($this->sqlTampered ?? $this->sql)) {
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
     * @param string $str
     *
     * @return string
     */
    public function escapeString(string $str) : string
    {
        // Allow re-connection.
        $s = $this->client->getConnection(true)
            ->real_escape_string($str);

        return $this->hasLikeClause ? $s : addcslashes($s, '%_');
    }
}

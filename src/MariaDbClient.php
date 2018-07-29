<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbQueryInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbConnectionException;

/**
 * MariaDB client.
 *
 *
 * Native options (examples):
 * - MYSQLI_OPT_CONNECT_TIMEOUT
 * - MYSQLI_SERVER_PUBLIC_KEY
 * @see http://php.net/manual/en/mysqli.options.php
 *
 * Native flags (examples):
 * - MYSQLI_CLIENT_COMPRESS
 * - MYSQLI_CLIENT_FOUND_ROWS
 * - MYSQLI_CLIENT_SSL
 * @see http://php.net/manual/en/mysqli.real-connect.php
 *
 * Unless using the mysqlnd driver PHP ini mysqli.reconnect must be falsy.
 *
 * Inherited read-onlys:
 * @property-read string $type
 * @property-read string $name
 * @property-read string $host
 * @property-read int $port
 * @property-read string $database
 * @property-read string $user
 * @property-read array $options
 * @property-read array $optionsResolved
 * @property-read string $characterSet
 * @property-read bool $transactionStarted
 * @property-read int $timesConnected
 * @property-read bool $reConnect
 *
 * Own properties:
 * @property-read string[] $flags
 * @property-read int $flagsResolved
 *
 * @package SimpleComplex\Database
 */
class MariaDbClient extends DbClient
{
    /**
     * Class name of \SimpleComplex\Database\MariaDbQuery or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_QUERY = \Package\Library\CustomMariaDbQuery::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MariaDbQuery
     *
     * @var string
     */
    const CLASS_QUERY = MariaDbQuery::class;

    /**
     * Class name of DatabaseErrorCodes class.
     *
     * @see MariaDbError
     * @see DbClient::errorsToException()
     *
     * @var string
     */
    const CLASS_ERROR_CODES = MariaDbError::class;

    /**
     * Default database server port.
     *
     * @var int
     */
    const SERVER_PORT = 3306;

    /**
     * Shorthand name to PHP MySQLi native option name.
     *
     * @see MariaDbClient::getConnection()
     *
     * @var string[]
     */
    const OPTION_SHORTHANDS = [
        // int. default: CONNECT_TIMEOUT.
        'connect_timeout' => 'MYSQLI_OPT_CONNECT_TIMEOUT',
        // str. default: CHARACTER_SET
        'character_set' => 'character_set',
    ];

    /**
     * @var string
     */
    protected $type = 'mariadb';

    /**
     * MySQLi connection flags.
     *
     * MYSQLI_CLIENT_* constant names, not constant values.
     *
     * @var string[]
     */
    protected $flags = [];

    /**
     * Connection flags resolved.
     *
     * Bitmask.
     *
     * @var int
     */
    protected $flagsResolved;

    /**
     * Object representing the connection.
     *
     * @var \MySQLi
     */
    protected $mySqlI;

    /**
     * Configures database client.
     *
     * Connection to the database server is created later, on demand.
     *
     * Options may be passed in root of arg databaseInfo
     * as well as in the options bucket.
     * That includes the flags array; if any.
     *
     * @see MariaDbClient::OPTION_SHORTHANDS
     *
     * MySQLi connection options:
     * @see http://php.net/manual/en/mysqli.options.php
     * MySQLi connection flags:
     * @see http://php.net/manual/en/mysqli.real-connect.php
     *
     * @param string $name
     * @param array $databaseInfo {
     *      @var string $host
     *      @var string $port  Optional, defaults to class constant SERVER_PORT.
     *      @var string $database
     *      @var string $user
     *      @var string $pass
     *      @var array $options
     *          Keys are PHP constant names or OPTION_SHORTHANDS keys.
     *      @var string[] $flags
     *          Database type specific bitmask flags, by name not value;
     *          'MYSQLI_CLIENT_COMPRESS', not MYSQLI_CLIENT_COMPRESS.
     *          Alternatively, set the flags in $options['flags'].
     * }
     */
    public function __construct(string $name, array $databaseInfo)
    {
        // Overrides parent constructor to secure connection flags.

        parent::__construct($name, $databaseInfo);

        /**
         * Parent constructor passes all non-standard $databaseInfo buckets,
         * like $databaseInfo['flags'], to options.
         *
         * @see DbClient::DATABASE_INFO
         */
        if (isset($this->options['flags'])) {
            $this->flags = $this->options['flags'];
            unset($this->options['flags']);
        }

        $this->explorableIndex[] = 'flags';
        $this->explorableIndex[] = 'flagsResolved';
    }

    /**
     * Create a query.
     *
     * For options, see:
     * @see MariaDbQuery::__construct()
     *
     * @see DbClient::query()
     */
    // public function query(string $sql, array $options = []) : DbQueryInterface

    /**
     * Create a single query which for sure won't be seen as multi-query.
     *
     * Has no effect if later calling append() on the query object.
     *
     * Convenience method, passing false option detect_multi to query()
     * has the same effect.
     * @see MariaDbQuery::__construct()
     * @see DbClient::query()
     *
     * @param string $sql
     * @param array $options
     *
     * @return $this|DbQueryInterface
     */
    public function singleQuery(string $sql, array $options = []) : DbQueryInterface
    {
        // Set multi_query option.
        $opts =& $options;
        $opts['detect_multi'] = false;

        $class_query = static::CLASS_QUERY;
        /** @var DbQueryInterface|MariaDbQuery */
        return new $class_query(
            $this,
            $sql,
            $opts
        );
    }

    /**
     * Create a multi-query.
     *
     * Multi-query is even required for batch query; multiple non-selecting
     * queries.
     * Calling a single stored procedure does not require multi-query, as long as
     * single result set.
     *
     * NB: An error in a multi-query might not be detected until all result sets
     * have been next'ed; seen when attempting to truncate disregarding foreign key.
     * @see MariaDbResult::nextSet()
     *
     * Convenience method, passing option multi_query to query() has the same
     * effect.
     * @see MariaDbQuery::__construct()
     * @see DbClient::query()
     *
     * @param string $sql
     * @param array $options
     *
     * @return $this|DbQueryInterface
     */
    public function multiQuery(string $sql, array $options = []) : DbQueryInterface
    {
        // Set multi_query option.
        $opts =& $options;
        $opts['multi_query'] = true;

        $class_query = static::CLASS_QUERY;
        /** @var DbQueryInterface|MariaDbQuery */
        return new $class_query(
            $this,
            $sql,
            $opts
        );
    }

    /**
     * Errs if previously started transaction isn't committed/rolled-back.
     *
     * Fails unless InnoDB.
     *
     * @return void
     *      Throws exception on failure.
     *
     * @throws \LogicException
     *      Previously started transaction isn't committed/rolled-back.
     * @throws DbRuntimeException
     */
    public function transactionStart()
    {
        if ($this->transactionStarted) {
            throw new \LogicException(
                $this->messagePrefix() . ' - previously started transaction isn\'t committed/rolled-back.'
            );
        }
        // Allow re-connection.
        if (
            !$this->getConnection(true)
            || !@$this->mySqlI->begin_transaction()
        ) {
            $errors = $this->getErrors();
            $cls_xcptn = $this->errorsToException($errors);
            throw new $cls_xcptn(
                $this->messagePrefix() . ' - failed to start transaction, error: '
                . $this->errorsToString($errors) . '.'
            );
        }
        $this->transactionStarted = true;
    }

    /**
     * Ignored if no ongoing transaction.
     *
     * Fails unless InnoDB.
     *
     * @return void
     *      Throws exception on failure.
     *
     * @throws DbConnectionException
     *      Connection lost.
     * @throws DbRuntimeException
     */
    public function transactionCommit()
    {
        if ($this->transactionStarted) {
            $msg = null;
            // Require unbroken connection.
            if (!$this->isConnected()) {
                $msg = ' - can\'t commit transaction, connection lost, error: ';
            }
            elseif (!@$this->mySqlI->commit()) {
                $msg = ' - failed to commit transaction, error: ';
            }
            if ($msg) {
                $errors = $this->getErrors();
                $cls_xcptn = $this->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->messagePrefix() . $msg . $this->errorsToString($errors) . '.'
                );
            }
            $this->transactionStarted = false;
        }
    }

    /**
     * Ignored if no ongoing transaction.
     *
     * Fails unless InnoDB.
     *
     * @return void
     *      Throws exception on failure.
     *
     * @throws DbConnectionException
     *      Connection lost.
     * @throws DbRuntimeException
     */
    public function transactionRollback()
    {
        if ($this->transactionStarted) {
            $msg = null;
            // Require unbroken connection.
            if (!$this->isConnected()) {
                $msg = ' - can\'t rollback transaction, connection lost, error: ';
            }
            elseif (!@$this->mySqlI->rollback()) {
                $msg = ' - failed to rollback transaction, error: ';
            }
            if ($msg) {
                $errors = $this->getErrors();
                $cls_xcptn = $this->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->messagePrefix() . $msg . $this->errorsToString($errors) . '.'
                );
            }
            $this->transactionStarted = false;
        }
    }

    /**
     * @return bool
     */
    public function isConnected() : bool
    {
        // Unless using the mysqlnd driver PHP ini mysqli.reconnect
        // must be falsy. Otherwise MySQLi::ping() may re-connect.
        return $this->mySqlI && $this->mySqlI->ping();
    }

    /**
     * @see DbClient::__destruct()
     *
     * @return void
     */
    public function disConnect()
    {
        if ($this->mySqlI) {
            @$this->mySqlI->close();
            $this->mySqlI = null;
        }
    }


    // Helpers.-----------------------------------------------------------------

    /**
     * Get RMDBS/driver native error(s) recorded as array,
     * concatenated string or empty string.
     *
     * @see DbClient::formatNativeErrors()
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
        $list = [];
        if ($this->mySqlI && ($errors = @$this->mySqlI->error_list)) {
            foreach ($errors as $error) {
                $list[] = [
                    'code' => $error['errno'] ?? 0,
                    'sqlstate' => $error['sqlstate'] ?? '00000',
                    'msg' => $error['error'] ?? '',
                ];
            }
        }
        if ($this->errorConnect) {
            $list[] = $this->errorConnect;
        }
        return $this->formatNativeErrors($list, $toString);
    }

    /**
     * Resolve options.
     *
     * Chainable.
     *
     * Public to facilitate option debugging prior to attempt to connect.
     *
     * @see MariaDbClient::getConnection()
     * @see MariaDbClient::OPTION_SHORTHANDS
     * @see MariaDbClient::$optionsResolved
     * @see MariaDbClient::$flagsResolved
     *
     * @return $this|DbClient|MariaDbClient
     *      Throws exception on error.
     *
     * @throws \LogicException
     *      Invalid option.
     */
    public function optionsResolve() : DbClient
    {
        if (!$this->optionsResolved) {
            $this->optionsResolved = [];
            // Copy.
            $options = $this->options;

            /**
             * Secure connection timeout.
             * @see MariaDbClient::OPTION_SHORTHANDS
             */
            if (!empty($options['connect_timeout'])) {
                $options['MYSQLI_OPT_CONNECT_TIMEOUT'] = (int) $options['connect_timeout'];
            }
            elseif (empty($options['MYSQLI_OPT_CONNECT_TIMEOUT'])) {
                $options['MYSQLI_OPT_CONNECT_TIMEOUT'] = static::CONNECT_TIMEOUT;
            }
            unset($options['connect_timeout']);

            /**
             * Remove character set option; handled prior to this, elsewhere.
             * @see MariaDbClient::characterSetResolve()
             * @see MariaDbClient::OPTION_SHORTHANDS
             */
            unset($options['character_set']);

            foreach ($options as $name => $value) {
                // Name must be (string) name, not constant value.
                if (ctype_digit('' . $name)) {
                    throw new \LogicException(
                        $this->messagePrefix()
                        . ' - option[' . $name . '] is integer, must be string name of PHP constant.'
                    );
                }
                $constant = @constant($name);
                if ($constant === null) {
                    throw new \LogicException(
                        $this->messagePrefix() . ' - invalid option[' . $name . '] value[' . $value
                        . '], there\'s no PHP constant by that name.'
                    );
                }
                $this->optionsResolved[$constant] = $value;
            }
            unset($options);

            // Do (MySQLi specialty) connection flags.
            $flags = 0;
            if ($this->flags) {
                foreach ($this->flags as $name) {
                    // Name must be (string) name, not constant value.
                    if (ctype_digit('' . $name)) {
                        throw new \LogicException(
                            $this->messagePrefix() . ' - flag[' . $name
                            . '] is integer, must be string name of MYSQLI_CLIENT_* PHP constant.'
                        );
                    }
                    $constant = @constant($name);
                    if ($constant === null) {
                        throw new \LogicException(
                            $this->messagePrefix()
                            . ' - invalid flag[' . $name . '], there\'s no PHP constant by that name.'
                        );
                    }
                    // Set if missing; bitwise Or (inclusive or).
                    $flags = $flags | $constant;
                }
            }
            $this->flagsResolved = $flags;
        }
        return $this;
    }

    /**
     * Resolve character set, for constructor.
     *
     * Character set must be available even before any connection,
     * (at least) for external use.
     *
     * @return void
     */
    protected function characterSetResolve()
    {
        $charset = !empty($this->options['character_set']) ? $this->options['character_set'] : static::CHARACTER_SET;

        // MySQLi::set_charset() needs other format.
        if ($charset == 'UTF-8') {
            $charset = 'utf8';
        }

        $this->characterSet = $charset;
    }


    // Package protected.-------------------------------------------------------

    /**
     * Attempts to re-connect if connection lost and arg $reConnect,
     * unless re-connection is disabled.
     *
     * Always sets connection timeout and connection character set.
     *
     * Re-connection gets disabled:
     * - temporarily when a transaction is started.
     * - permanently when a query doesn't use client buffered result mode
     *
     * @internal Package protected; for MariaDbQuery|DbQueryInterface.
     *
     * @see MariaDbClient::optionsResolve()
     *
     * @param bool $reConnect
     *
     * @return \MySQLi|bool
     *      \MySQLi: connection (re-)established.
     *      False: no connection.
     *
     * @throws \LogicException
     *      Propagated, from optionsResolve().
     *      Failure to set option.
     */
    public function getConnection(bool $reConnect = false)
    {
        // Unless using the mysqlnd driver PHP ini mysqli.reconnect
        // must be falsy. Otherwise MySQLi::ping() may re-connect.
        if (!$this->mySqlI || !$this->mySqlI->ping()) {
            $this->errorConnect = null;
            // Unless first time.
            if ($this->timesConnected) {
                if (!$reConnect || !$this->reConnect) {
                    $this->errorConnect = [
                        'code' => static::ERROR_CODE_CONNECT,
                        'sqlstate' => '08003',
                        'msg' => 'No connection, '
                            . (!$this->reConnect ? 're-connect disabled' : 'arg $reConnect false') . '.'
                    ];
                    return false;
                }
                if ($this->transactionStarted) {
                    $this->errorConnect = [
                        'code' => static::ERROR_CODE_CONNECT,
                        'sqlstate' => '08003',
                        'msg' => 'Connection lost during unfinished transaction.',
                    ];
                    return false;
                }
            }

            // Don't check for failing mysql_init().
            $mysqli = mysqli_init();

            if (!$this->optionsResolved) {
                $this->optionsResolve();
            }

            foreach ($this->optionsResolved as $int => $value) {
                if (!@$mysqli->options($int, $value)) {
                    // There's no means of getting native error (yet).
                    $this->errorConnect = [
                        'code' => static::ERROR_CODE_CONNECT,
                        'sqlstate' => '08000',
                        'msg' => 'Failed setting connection option[' . $int . '] value[' . $value . '].',
                    ];
                    return false;
                }
            }

            if (
                !@$mysqli->real_connect(
                    $this->host,
                    $this->user,
                    $this->pass,
                    $this->database,
                    $this->port,
                    '',
                    $this->flagsResolved
                )
                || $mysqli->connect_errno
            ) {
                // Can only access connect_errno, not \MySQLi::errno (yet).
                $this->errorConnect = [
                    'code' => static::ERROR_CODE_CONNECT,
                    'sqlstate' => '08000',
                    'msg' => 'Connect to host[' . $this->host . '] port[' . $this->port . '] failed with: ('
                        . $mysqli->connect_errno . ') ' . $mysqli->connect_error . '.'
                ];
                return false;
            }

            // Can't access native errors prior to successful connection.
            $this->mySqlI = $mysqli;

            if (!@$this->mySqlI->set_charset($this->characterSet)) {
                $this->errorConnect = [
                    'code' => static::ERROR_CODE_CONNECT,
                    'sqlstate' => '08000',
                    'msg' => 'Failed setting connection character set[' . $this->characterSet . '].',
                ];
                return false;
            }

            ++$this->timesConnected;
        }

        return $this->mySqlI;
    }
}

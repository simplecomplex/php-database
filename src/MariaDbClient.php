<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Exception\DbLogicalException;
use SimpleComplex\Database\Exception\DbOptionException;
use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbConnectionException;
use SimpleComplex\Database\Exception\DbInterruptionException;


/**
 * Maria DB client.
 *
 * Suppresses PHP errors with @ to prevent dupe messages in logs.
 *
 * Unless using the mysqlnd driver PHP ini mysqli.reconnect must be falsy.
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
 * @property-read string[] $flags
 * @property-read int $flagsResolved
 *
 * @package SimpleComplex\Database
 */
class MariaDbClient extends AbstractDbClient
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
    protected $flags;

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
     * }
     */
    public function __construct(string $name, array $databaseInfo)
    {
        // Overrides parent constructor to secure connection flags.

        parent::__construct($name, $databaseInfo);

        $this->flags = $databaseInfo['flags'] ?? [];
        $this->explorableIndex[] = 'flags';
        $this->explorableIndex[] = 'flagsResolved';
    }

    /**
     * Attempts to re-connect if connection lost and arg $reConnect.
     *
     * Always sets connection timeout and connection character set.
     *
     * @param bool $reConnect
     *
     * @return \MySQLi|bool
     *      False: no connection and not arg $reConnect.
     *      \MySQLi: connection (re-)established.
     *
     * @throws DbOptionException
     *      Invalid option.
     *      Failure to set option.
     * @throws DbConnectionException
     */
    public function getConnection(bool $reConnect = false)
    {
        // Unless using the mysqlnd driver PHP ini mysqli.reconnect
        // must be falsy. Otherwise MySQLi::ping() may re-connect.
        if (!$this->mySqlI || !$this->mySqlI->ping()) {
            if (!$reConnect) {
                return false;
            }

            $mysqli = mysqli_init();

            if (!$this->optionsResolved) {
                $this->optionsResolved = [];
                // Copy.
                $options = $this->options;

                // Secure connection timeout.
                if (!empty($options['connect_timeout'])) {
                    $options['MYSQLI_OPT_CONNECT_TIMEOUT'] = (int) $options['connect_timeout'];
                }
                elseif (empty($options['MYSQLI_OPT_CONNECT_TIMEOUT'])) {
                    $options['MYSQLI_OPT_CONNECT_TIMEOUT'] = static::CONNECT_TIMEOUT;
                }
                unset($options['connect_timeout']);

                /**
                 * Character set shan't be an option (any longer);
                 * handled elsewhere.
                 * @see MariaDbClient::characterSetResolve()
                 */
                unset($options['character_set']);

                foreach ($options as $name => $value) {
                    // Name must be (string) name, not constant value.
                    if (ctype_digit('' . $name)) {
                        throw new DbOptionException(
                            $this->errorMessagePreamble()
                            . ' - option[' . $name . '] is integer, must be string name of PHP constant.'
                        );
                    }
                    $constant = @constant($name);
                    if (!$constant) {
                        throw new DbOptionException(
                            $this->errorMessagePreamble() . ' - invalid option[' . $name . '] value[' . $value
                            . '], there\'s no PHP constant by that name.'
                        );
                    }
                    $this->optionsResolved[$constant] = $value;
                }
                unset($options);

                $flags = 0;
                if ($this->flags) {
                    foreach ($this->flags as $name) {
                        // Name must be (string) name, not constant value.
                        if (ctype_digit('' . $name)) {
                            throw new DbOptionException(
                                $this->errorMessagePreamble() . ' - flag[' . $name
                                . '] is integer, must be string name of MYSQLI_CLIENT_* PHP constant.'
                            );
                        }
                        $constant = @constant($name);
                        if ($constant === null) {
                            throw new DbOptionException(
                                $this->errorMessagePreamble()
                                . ' - invalid flag[' . $name . '], there\'s no PHP constant by that name.'
                            );
                        }
                        // Set if missing; bitwise Or (inclusive or).
                        $flags = $flags | $constant;
                    }
                }
                $this->flagsResolved = $flags;
            }

            foreach ($this->optionsResolved as $int => $value) {
                if (!@$mysqli->options($int, $value)) {
                    throw new DbOptionException(
                        $this->errorMessagePreamble()
                        . ' - failed to set ' . $this->type . ' option[' . $int . '] value[' . $value
                        // @todo: failure to set MySQLi connect options spell ordinary error or connect_error?
                        . '], with error: ' . $this->nativeError()
                    );
                }
            }

            if (
                !@$mysqli->real_connect(
                    $this->host,
                    $this->user,
                    $this->pass,
                    $this->database,
                    $this->port,
                    null,
                    $this->flagsResolved
                )
                || $mysqli->connect_errno
            ) {
                throw new DbConnectionException(
                    $this->errorMessagePreamble()
                    . ' - connect to host[' . $this->host . '] port[' . $this->port
                    . '] failed, with error: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error . '.'
                );
            }
            $this->mySqlI = $mysqli;

            if (!@$this->mySqlI->set_charset($this->characterSet)) {
                throw new DbOptionException(
                    $this->errorMessagePreamble()
                    . ' - setting connection character set[' . $this->characterSet
                    . '] failed, with error: ' . $this->nativeError() . '.'
                );
            }
        }

        return $this->mySqlI;
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

    public function disConnect()
    {
        if ($this->mySqlI) {
            @$this->mySqlI->close();
            $this->mySqlI = null;
        }
    }

    /**
     * Errs if previously started transaction isn't committed/rolled-back.
     *
     * Fails unless InnoDB.
     *
     * @return void
     *      Throws exception on failure.
     *
     * @throws DbLogicalException
     *      Previously started transaction isn't committed/rolled-back.
     * @throws DbRuntimeException
     */
    public function transactionStart()
    {
        if ($this->transactionStarted) {
            throw new DbLogicalException(
                $this->errorMessagePreamble() . ' - previously started transaction isn\'t committed/rolled-back.'
            );
        }
        // Allow re-connection.
        $this->getConnection(true);
        if (!@$this->mySqlI->begin_transaction()) {
            throw new DbRuntimeException(
                $this->errorMessagePreamble()
                . ' - failed to start transaction, with error: ' . $this->nativeError() . '.'
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
     * @throws DbInterruptionException
     *      Connection lost.
     * @throws DbRuntimeException
     */
    public function transactionCommit()
    {
        if ($this->transactionStarted) {
            // Require unbroken connection.
            if (!$this->isConnected()) {
                throw new DbInterruptionException(
                    $this->errorMessagePreamble() . ' - can\'t commit, connection lost.'
                );
            }
            if (!@$this->mySqlI->commit()) {
                throw new DbRuntimeException(
                    $this->errorMessagePreamble()
                    . ' - failed to commit transaction, with error: ' . $this->nativeError() . '.'
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
     * @throws DbInterruptionException
     *      Connection lost.
     * @throws DbRuntimeException
     */
    public function transactionRollback()
    {
        if ($this->transactionStarted) {
            // Require unbroken connection.
            if (!$this->isConnected()) {
                throw new DbInterruptionException(
                    $this->errorMessagePreamble() . ' - can\'t rollback, connection lost.'
                );
            }
            if (!@$this->mySqlI->rollback()) {
                throw new DbRuntimeException(
                    $this->errorMessagePreamble()
                    . ' - failed to rollback transaction, with error: ' . $this->nativeError() . '.'
                );
            }
            $this->transactionStarted = false;
        }
    }


    // Helpers.-----------------------------------------------------------------

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

    /**
     * @param bool $emptyOnNone
     *      False: on no error returns message indication just that.
     *      True: on no error return empty string.
     *
     * @return string
     */
    public function nativeError(bool $emptyOnNone = false) : string
    {
        if ($this->mySqlI && ($code = $this->mySqlI->errno)) {
            return '(' . $this->mySqlI->errno . ') ' . rtrim($this->mySqlI->error, '.') . '.';
        }
        return $emptyOnNone ? '' : '- no native error recorded -';
    }
}

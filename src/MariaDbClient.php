<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Exception\DbOptionException;
use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbConnectionException;
use SimpleComplex\Database\Exception\DbInterruptionException;


/**
 * Maria DB client.
 *
 * Suppresses PHP errors with @ to prevent dupe messages in logs.
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
     * @var bool
     */
    protected $optionsChecked;

    /**
     * Object representing the connection.
     *
     * @var \MySQLi
     */
    protected $mySqlI;

    /**
     * Attempts to re-connect if previous connection lost.
     *
     * Always sets connection timeout (MYSQLI_OPT_CONNECT_TIMEOUT).
     *
     * @param bool $checkOnly
     *      Check if connected.
     *
     * @return \MySQLi|bool
     *      Bool: if arg $checkOnly.
     *
     * @throws DbOptionException
     *      Failure to set option.
     * @throws DbConnectionException
     */
    public function getConnection(bool $checkOnly = false)
    {
        if ($checkOnly) {
            return $this->mySqlI && $this->mySqlI->ping();
        }
        if (!$this->mySqlI || !$this->mySqlI->ping()) {
            $mysqli = mysqli_init();

            if (!$this->optionsChecked) {
                // Secure connection timeout.
                if (!empty($this->options['connect_timeout'])) {
                    $this->options['MYSQLI_OPT_CONNECT_TIMEOUT'] = (int) $this->options['connect_timeout'];
                } elseif (!empty($this->options['MYSQLI_OPT_CONNECT_TIMEOUT'])) {
                    $this->options['MYSQLI_OPT_CONNECT_TIMEOUT'] = (int) $this->options['MYSQLI_OPT_CONNECT_TIMEOUT'];
                } else {
                    $this->options['MYSQLI_OPT_CONNECT_TIMEOUT'] = static::CONNECT_TIMEOUT;
                }
                unset($this->options['connect_timeout']);

                // Secure character set.
                if (empty($this->options['character_set'])) {
                    $this->options['character_set'] = static::CHARACTER_SET;
                }

                // MySQLi::set_charset() needs other format.
                if ($this->options['character_set'] == 'UTF-8') {
                    $this->options['character_set'] = 'utf8';
                }

                $this->optionsChecked = true;
            }

            foreach ($this->options as $name => $value) {
                // Character set shan't be set as option.
                if ($name == 'character_set') {
                    continue;
                }
                // Name must be (string) name, not constant value.
                if (ctype_digit('' . $name)) {
                    throw new DbOptionException(
                        'Database option[' . $name . '] is integer, must be string name of MYSQLI_... PHP constant.'
                    );
                }
                $constant = constant($name);
                if (!$constant) {
                    throw new DbOptionException(
                        'Database failed setting option[' . $name . '] value[' . $value
                        . '], because there is no PHP constant by that name.'
                    );
                }
                if (!$mysqli->options($constant, $value)) {
                    throw new DbOptionException(
                        'Database failed to set ' . $this->type . ' option[' . $name . '] value[' . $value . '].'
                    );
                }
            }

            $flags = 0;
            if ($this->flags) {
                foreach ($this->flags as $name) {
                    // Name must be (string) name, not constant value.
                    if (ctype_digit('' . $name)) {
                        throw new DbOptionException(
                            'Database flag[' . $name
                            . '] is integer, must be string name of MYSQLI_CLIENT_... PHP constant.'
                        );
                    }
                    $constant = constant($name);
                    if (!$constant) {
                        throw new DbOptionException(
                            'Database failed setting flag[' . $name
                            . '], because there is no PHP constant by that name.'
                        );
                    }
                    // Set if missing; bitwise Or (inclusive or).
                    $flags = $flags | $constant;
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
                    $flags
                )
                || $mysqli->connect_errno
            ) {
                throw new DbConnectionException(
                    'Database type connect to host[' . $this->host . '] port[' . $this->port
                    . '] failed, with error: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error . '.'
                );
            }
            $this->mySqlI = $mysqli;

            if (!@$this->mySqlI->set_charset($this->options['character_set'])) {
                throw new DbOptionException(
                    'Database setting connection character set[' . $this->options['character_set']
                    . '] failed, with error: ' . $this->getNativeError() . '.'
                );
            }
        }

        return $this->mySqlI;
    }

    public function disConnect()
    {
        if ($this->mySqlI) {
            @$this->mySqlI->close();
            $this->mySqlI = null;
        }
    }

    /**
     * @param bool $emptyOnNone
     *      False: on no error returns message indication just that.
     *      True: on no error return empty string.
     *
     * @return string
     */
    public function getNativeError(bool $emptyOnNone = false) : string
    {
        if ($this->mySqlI && ($code = $this->mySqlI->errno)) {
            return '(' . $this->mySqlI->errno . ') ' . rtrim($this->mySqlI->error, '.') . '.';
        }
        return $emptyOnNone ? '' : '- no native error recorded -';
    }

    /**
     * Fails unless InnoDB.
     *
     * @return void
     *      Throws exception on failure.
     *
     * @throws DbRuntimeException
     */
    public function transactionStart()
    {
        // Allow new connection.
        $this->getConnection(true);

        if (!@$this->mySqlI->begin_transaction()) {
            throw new DbRuntimeException(
                'Database failed to start transaction, with error: ' . $this->getNativeError() . '.'
            );
        }
    }

    /**
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
        // Require unbroken connection.
        if (!$this->getConnection(true)) {
            throw new DbInterruptionException('Database can\'t commit, connection lost.');
        }

        if (!@$this->mySqlI->commit()) {
            throw new DbRuntimeException(
                'Database failed to commit transaction, with error: ' . $this->getNativeError() . '.'
            );
        }
    }

    /**
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
        // Require unbroken connection.
        if (!$this->getConnection(true)) {
            throw new DbInterruptionException('Database can\'t commit, connection lost.');
        }

        if (!@$this->mySqlI->rollback()) {
            throw new DbRuntimeException(
                'Database failed to rollback transaction, with error: ' . $this->getNativeError() . '.'
            );
        }
    }
}

<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbConnectionException;
use SimpleComplex\Database\Exception\DbInterruptionException;

/**
 * MS SQL client.
 *
 * Suppresses PHP errors with @ to prevent dupe messages in logs.
 *
 * Options:
 * @see https://docs.microsoft.com/en-us/sql/connect/php/connection-option
 *
 * @package SimpleComplex\Database
 */
class MsSqlClient extends AbstractDbClient
{
    /**
     * Class name of \SimpleComplex\Database\MsSqlQuery or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_QUERY = \Package\Library\CustomMsSqlQuery::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MsSqlQuery
     *
     * @var string
     */
    const CLASS_QUERY = MsSqlQuery::class;

    /**
     * Default database server port.
     *
     * @var int
     */
    const SERVER_PORT = 1433;

    /**
     * Shorthand name to PHP Sqlsrv native option name.
     *
     * @see MsSqlClient::getConnection()
     *
     * @var string[]
     */
    const OPTION_SHORTHANDS = [
        // int. default: CONNECT_TIMEOUT.
        'connect_timeout' => 'LoginTimeout',
        // str. default: CHARACTER_SET
        'character_set' => 'CharacterSet',
    ];

    /**
     * @var string
     */
    protected $type = 'mssql';

    /**
     * @var bool
     */
    protected $optionsChecked;

    /**
     * @var resource
     */
    protected $connection;

    /**
     * @param bool $checkOnly
     *      Check if connected.
     *
     * @return resource|bool
     *      Bool: if arg $checkOnly.
     *
     * @throws DbConnectionException
     */
    public function getConnection(bool $checkOnly = false)
    {
        if ($checkOnly) {
            return !!$this->connection;
        }
        if (!$this->connection) {
            if (!$this->optionsChecked) {
                // Secure connection timeout.
                if (!empty($this->options['connect_timeout'])) {
                    $this->options['LoginTimeout'] = (int) $this->options['connect_timeout'];
                } elseif (!empty($this->options['LoginTimeout'])) {
                    $this->options['LoginTimeout'] = (int) $this->options['LoginTimeout'];
                } else {
                    $this->options['LoginTimeout'] = static::CONNECT_TIMEOUT;
                }
                unset($this->options['connect_timeout']);

                // Secure character set.
                if (!empty($this->options['character_set'])) {
                    $this->options['CharacterSet'] = (int) $this->options['character_set'];
                } else {
                    $this->options['CharacterSet'] = static::CHARACTER_SET;
                }
                unset($this->options['character_set']);

                // Only two character sets supported.
                if ($this->options['CharacterSet'] != 'UTF-8') {
                    $this->options['CharacterSet'] = 'SQLSRV_ENC_CHAR';
                }

                // Remove possible dupes.
                unset(
                    $this->options['Database'], $this->options['Authentication'],
                    $this->options['UID'], $this->options['PWD']
                );

                $this->optionsChecked = true;
            }

            $connection_info = [
                'Database' => $this->database,
                // SQL Server Authentication.
                'Authentication' => 'SqlPassword',
                'UID' => $this->user,
                'PWD' => $this->pass,
            ];

            $connection = @sqlsrv_connect($this->host . ', ' . $this->port, $connection_info + $this->options);
            if (!$connection) {
                throw new DbConnectionException(
                    'Database type connect to host[' . $this->host . '] port[' . $this->port
                    . '] failed, with error: ' . $this->getNativeError() . '.'
                );
            }
            $this->connection = $connection;
        }

        return $this->connection;
    }

    public function disConnect()
    {
        if ($this->connection) {
            @sqlsrv_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * NB: An error may not belong to current connection;
     * Sqlsrv's error getter takes no connection argument.
     *
     * @param bool $emptyOnNone
     *      False: on no error returns message indication just that.
     *      True: on no error return empty string.
     *
     * @return string
     */
    public function getNativeError(bool $emptyOnNone = false) : string
    {
        if (($errors = sqlsrv_errors())) {
            $list = [];
            foreach ($errors as $error) {
                if (!empty($error['SQLSTATE'])) {
                    $em = '(SQLSTATE: ' . $error['SQLSTATE'] . ') ';
                } elseif (!empty($error['code'])) {
                    $em = '(code: ' . $error['code'] . ') ';
                } else {
                    $em = '';
                }
                $list[] = $em . rtrim($error['message'] ?? '', '.');
            }
            return join(' ', $list);
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

        if (!@sqlsrv_begin_transaction($this->connection)) {
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

        if (!@sqlsrv_commit($this->connection)) {
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

        if (!@sqlsrv_rollback($this->connection)) {
            throw new DbRuntimeException(
                'Database failed to rollback transaction, with error: ' . $this->getNativeError() . '.'
            );
        }
    }
}

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
     * @var resource
     */
    protected $connection;

    /**
     * Attempts to re-connect if connection lost and arg $reConnect.
     *
     * Always sets connection timeout and connection character set.
     *
     * Uses standard database user authentication;
     * - SQL Server Authentication/SqlPassword.
     * Windows user authentication not supported.
     *
     * @param bool $reConnect
     *
     * @return resource|bool
     *      False: no connection and not arg $reConnect.
     *      Resource: connection (re-)established.
     *
     * @throws DbConnectionException
     */
    public function getConnection(bool $reConnect = false)
    {
        if (!$this->connection) {
            if (!$reConnect) {
                return false;
            }

            if (!$this->optionsResolved) {
                // Copy.
                $options = $this->options;
                
                // Secure connection timeout.
                if (!empty($options['connect_timeout'])) {
                    $options['LoginTimeout'] = (int) $options['connect_timeout'];
                }
                elseif (empty($options['LoginTimeout'])) {
                    $options['LoginTimeout'] = static::CONNECT_TIMEOUT;
                }
                unset($options['connect_timeout']);

                /**
                 * Character set shan't be an option (any longer);
                 * handled elsewhere.
                 * @see MsSqlClient::characterSetResolve()
                 */
                unset($options['character_set']);

                // user+pass shan't be recorded in resolved options.
                unset(
                    $options['UID'], $options['PWD']
                );

                $this->optionsResolved =& $options;

                // Set/overwrite required options.
                $this->optionsResolved['Database'] = $this->database;
                $this->optionsResolved['CharacterSet'] = $this->characterSet;
                // SQL Server Authentication.
                $this->optionsResolved['Authentication'] = 'SqlPassword';
            }

            $connection = @sqlsrv_connect(
                $this->host . ', ' . $this->port,
                $this->optionsResolved + [
                    'UID' => $this->user,
                    'PWD' => $this->pass,
                ]
            );
            if (!$connection) {
                throw new DbConnectionException(
                    $this->errorMessagePreamble()
                    . ' connect to host[' . $this->host . '] port[' . $this->port
                    . '] failed, with error: ' . $this->nativeError() . '.'
                );
            }
            $this->connection = $connection;
        }

        return $this->connection;
    }

    /**
     * @return bool
     */
    public function isConnected() : bool
    {
        return !!$this->connection;
    }

    public function disConnect()
    {
        if ($this->connection) {
            @sqlsrv_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Errs if previously started transaction isn't committed/rolled-back.
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
        if (!@sqlsrv_begin_transaction($this->connection)) {
            throw new DbRuntimeException(
                $this->errorMessagePreamble()
                . ' - failed to start transaction, with error: ' . $this->nativeError() . '.'
            );
        }
    }

    /**
     * Ignored if no ongoing transaction.
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
            if (!@sqlsrv_commit($this->connection)) {
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
            if (!@sqlsrv_rollback($this->connection)) {
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
        if (!empty($this->options['character_set'])) {
            $charset = $this->options['character_set'];
        } elseif (!empty($this->options['CharacterSet'])) {
            $charset = $this->options['CharacterSet'];
        } else {
            $charset = static::CHARACTER_SET;
        }

        // Only two character sets supported.
        if ($charset != 'UTF-8') {
            $charset = 'SQLSRV_ENC_CHAR';
        }

        $this->characterSet = $charset;
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
    public function nativeError(bool $emptyOnNone = false) : string
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
}

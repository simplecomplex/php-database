<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbQueryInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbConnectionException;

/**
 * MS SQL client.
 *
 * Suppresses PHP errors with @ to prevent dupe messages in logs.
 *
 * Options:
 * @see https://docs.microsoft.com/en-us/sql/connect/php/connection-option
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
 * @property-read array|string $info  Driver info, string if not connected.
 *
 * @package SimpleComplex\Database
 */
class MsSqlClient extends DbClient
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
     * Class name of DatabaseErrorCodes class.
     *
     * @see MsSqlError
     * @see DbClient::errorsToException()
     *
     * @var string
     */
    const CLASS_ERROR_CODES = MsSqlError::class;

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
        // int. default: TLS_TRUST_SELF_SIGNED
        'tls_trust_self_signed' => 'TrustServerCertificate',
    ];

    /**
     * Whether to trust self-signed TLS certificate.
     *
     * Constructor $databaseInfo option (bool) tls_trust_self_signed.
     *
     * @var int
     *      0|1.
     */
    const TLS_TRUST_SELF_SIGNED = 0;

    /**
     * @var string
     */
    protected $type = 'mssql';

    /**
     * @var resource
     */
    protected $connection;

    /**
     * @inheritdoc
     *
     * @see DbClient::__construct()
     */
    public function __construct(string $name, array $databaseInfo)
    {
        parent::__construct($name, $databaseInfo);

        $this->explorableIndex[] = 'info';
    }

    /**
     * Create a query for calling a stored procedure.
     *
     * @param string $sql
     * @param array $options
     *
     * @return DbQueryInterface
     */
    public function call(string $sql, array $options = []) : DbQueryInterface
    {
        // sqlsrv requires no special handling when calling stored procedure.
        $class_query = static::CLASS_QUERY;
        /** @var DbQueryInterface|MariaDbQuery */
        return new $class_query(
            $this,
            $sql,
            $options
        );
    }

    /**
     * Create a query.
     *
     * For options, see:
     * @see MsSqlQuery::__construct()
     *
     * @see DbClient::query()
     */
    // public function query(string $sql, array $options = []) : DbQueryInterface

    /**
     * Errs if previously started transaction isn't committed/rolled-back.
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
                $this->errorMessagePrefix() . ' - previously started transaction isn\'t committed/rolled-back.'
            );
        }
        // Allow re-connection.
        if (
            !$this->getConnection(true)
            || !@sqlsrv_begin_transaction($this->connection)
        ) {
            $errors = $this->getErrors();
            $cls_xcptn = $this->errorsToException($errors);
            throw new $cls_xcptn(
                $this->errorMessagePrefix() . ' - failed to start transaction, error: '
                . $this->errorsToString($errors) . '.'
            );
        }
    }

    /**
     * Ignored if no ongoing transaction.
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
            elseif (!@sqlsrv_commit($this->connection)) {
                $msg = ' - failed to commit transaction, error: ';
            }
            if ($msg) {
                $errors = $this->getErrors();
                $cls_xcptn = $this->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->errorMessagePrefix() . $msg . $this->errorsToString($errors) . '.'
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
            elseif (!@sqlsrv_rollback($this->connection)) {
                $msg = ' - failed to rollback transaction, error: ';
            }
            if ($msg) {
                $errors = $this->getErrors();
                $cls_xcptn = $this->errorsToException($errors);
                throw new $cls_xcptn(
                    $this->errorMessagePrefix() . $msg . $this->errorsToString($errors) . '.'
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
        return !!$this->connection;
    }

    /**
     * @see DbClient::__destruct()
     *
     * @return void
     */
    public function disConnect()
    {
        if ($this->connection) {
            @sqlsrv_close($this->connection);
            $this->connection = null;
        }
    }


    // Helpers.-----------------------------------------------------------------

    /**
     * Get RMDBS/driver native error(s) recorded as array,
     * concatenated string or empty string.
     *
     * NB: An error may not belong to current connection;
     * Sqlsrv's error getter takes no connection argument.
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
        if (($errors = sqlsrv_errors())) {
            foreach ($errors as $error) {
                $list[] = [
                    'code' => $error['code'] ?? 0,
                    'sqlstate' => $error['SQLSTATE'] ?? '00000',
                    'msg' => $error['message'] ?? '',
                ];
            }
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
     * @see MsSqlClient::getConnection()
     * @see MsSqlClient::OPTION_SHORTHANDS
     * @see MsSqlClient::$optionsResolved
     *
     * @return DbClient|MsSqlClient
     *      Throws exception on error.
     */
    public function optionsResolve() : DbClient
    {
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

            // Secure TLS trust self-signed.
            if (isset($options['tls_trust_self_signed'])) {
                $options['TrustServerCertificate'] = (int) $options['tls_trust_self_signed'];
                unset($options['tls_trust_self_signed']);
            }
            elseif (!isset($options['TrustServerCertificate'])) {
                $options['TrustServerCertificate'] = static::TLS_TRUST_SELF_SIGNED;
            }

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


    // Package protected.-------------------------------------------------------

    /**
     * Attempts to re-connect if connection lost and arg $reConnect,
     * unless re-connection is disabled.
     *
     * Always sets:
     * - connection timeout; (int) LoginTimeout
     * - connection character set; (str) CharacterSet
     * - whether to trust self-signed TLS certificate; (int) TrustServerCertificate
     *
     * Uses standard database user authentication;
     * - SQL Server Authentication/SqlPassword.
     * Windows user authentication not supported.
     *
     * Re-connection gets disabled:
     * - temporarily when a transaction is started.
     * - permanently when a query doesn't use client buffered result mode
     *
     * @internal Package protected; for MsSqlQuery|DbQueryInterface.
     *
     * @see MsSqlClient::optionsResolve()
     *
     * @param bool $reConnect
     *
     * @return resource|bool
     *      Resource: connection (re-)established.
     *      False: no connection.
     *
     * @throws DbConnectionException
     *      Connection lost during unfinished transaction.
     *      Failure to (re)connect.
     */
    public function getConnection(bool $reConnect = false)
    {
        if (!$this->connection) {
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

            if (!$this->optionsResolved) {
                $this->optionsResolve();
            }

            $connection = @sqlsrv_connect(
                $this->host . ', ' . $this->port,
                $this->optionsResolved + [
                    'UID' => $this->user,
                    'PWD' => $this->pass,
                ]
            );
            if (!$connection) {
                $this->errorConnect = [
                    'code' => static::ERROR_CODE_CONNECT,
                    'sqlstate' => '08000',
                    'msg' => 'Connect to host[' . $this->host . '] port[' . $this->port . '] failed.'
                ];
                return false;
            }
            $this->connection = $connection;

            ++$this->timesConnected;
        }

        return $this->connection;
    }


    // Explorable.--------------------------------------------------------------

    /**
     * Get a read-only property.
     *
     * @see DbClient::__get()
     *
     * @param string $name
     *
     * @return mixed
     *
     * @throws \OutOfBoundsException
     *      Propagated.
     */
    public function __get(string $name)
    {
        if ($name == 'info') {
            if (!$this->isConnected()) {
                return 'Not connected to server.';
            }
            return @sqlsrv_server_info($this->connection);
        }
        return parent::__get($name);
    }
}

<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

/**
 * Database client interface.
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
interface DbClientInterface
{
    /**
     * Configures database client.
     *
     * Connection to the database server is created later, on demand.
     *
     * @see MariaDbClient::__construct()
     * @see MsSqlClient::__construct()
     *
     * @param string $name
     * @param array $databaseInfo
     */
    public function __construct(string $name, array $databaseInfo);

    /**
     * Disable re-connection permanently.
     *
     * @see MariaDbClient::reConnectDisable()
     * @see MsSqlClient::reConnectDisable()
     *
     * @return $this|DbClientInterface
     */
    public function reConnectDisable() : DbClientInterface;

    /**
     * Create a query.
     *
     * @see MariaDbClient::query()
     * @see MsSqlClient::query()
     *
     * @param string $sql
     * @param array $options
     *
     * @return DbQueryInterface
     */
    public function query(string $sql, array $options = []) : DbQueryInterface;

    /**
     * @see MariaDbClient::transactionStart()
     * @see MsSqlClient::transactionStart()
     *
     * @return void
     *      Throws exception on failure.
     */
    public function transactionStart();

    /**
     * @see MariaDbClient::transactionCommit()
     * @see MsSqlClient::transactionCommit()
     *
     * @return void
     *      Throws exception on failure.
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Connection lost.
     */
    public function transactionCommit();

    /**
     * @see MariaDbClient::transactionRollback()
     * @see MsSqlClient::transactionRollback()
     *
     * @return void
     *      Throws exception on failure.
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Connection lost.
     */
    public function transactionRollback();

    /**
     * @see MariaDbClient::isConnected()
     * @see MsSqlClient::isConnected()
     *
     * @return bool
     */
    public function isConnected() : bool;

    /**
     * Close database server connection.
     *
     * @see MariaDbClient::disConnect()
     * @see MsSqlClient::disConnect()
     */
    public function disConnect();


    // Helpers.-----------------------------------------------------------------

    /**
     * Get RMDBS/driver native error(s) recorded as array,
     * concatenated string or empty string.
     *
     * @see MariaDbClient::getErrors()
     * @see MsSqlClient::getErrors()
     *
     * @param int $stringed
     *      1: on no error returns message indicating no error.
     *      2: on no error return empty string.
     *
     * @return array|string
     *      Array: key is error code.
     */
    public function getErrors(int $stringed = 0);


    // Package protected.-------------------------------------------------------

    /**
     * Used by query instance, on demand.
     *
     * Attempts to re-connect if connection lost and arg $reConnect,
     * unless re-connection is disabled.
     *
     * Re-connection gets disabled:
     * - temporarily when a transaction is started.
     * - permanently when a query doesn't use client buffered result mode
     *
     * @internal Package protected; for DbQueryInterface.
     *
     * @see MariaDbClient::getConnection()
     * @see MsSqlClient::getConnection()
     *
     * @param bool $reConnect
     *
     * @return mixed|bool
     *      Mixed: connection (re-)established.
     *      False: no connection.
     */
    public function getConnection(bool $reConnect = false);
}

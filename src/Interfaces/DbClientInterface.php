<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

/**
 * Database client interface.
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
     * @param string $name
     * @param array $databaseInfo
     */
    public function __construct(string $name, array $databaseInfo);

    /**
     * Attempts to re-connect if connection lost and arg $reConnect.
     *
     * @param bool $reConnect.
     *
     * @return mixed|bool
     *      False: no connection and not arg $reConnect.
     *      Mixed: connection (re-)established.
     *
     * throws \SimpleComplex\Database\Exception\DbConnectionException
     */
    public function getConnection(bool $reConnect = false);

    /**
     * @return bool
     */
    public function isConnected() : bool;

    /**
     * Close database server connection.
     */
    public function disConnect();

    /**
     * @param string $baseQuery
     * @param bool $isMulti
     *      True: arg $baseQuery contains multiple queries.
     *
     * @return DbQueryInterface
     */
    public function query(string $baseQuery, bool $isMulti = false) : DbQueryInterface;

    /**
     * @return void
     *      Throws exception on failure.
     */
    public function transactionStart();

    /**
     * @return void
     *      Throws exception on failure.
     *
     * @throws \SimpleComplex\Database\Exception\DbInterruptionException
     *      Connection lost.
     */
    public function transactionCommit();

    /**
     * @return void
     *      Throws exception on failure.
     *
     * @throws \SimpleComplex\Database\Exception\DbInterruptionException
     *      Connection lost.
     */
    public function transactionRollback();

    /**
     * @param bool $emptyOnNone
     *      False: on no error returns message indication just that.
     *      True: on no error return empty string.
     *
     * @return string
     */
    public function nativeError(bool $emptyOnNone = false) : string;
}

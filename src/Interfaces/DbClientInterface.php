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
     * @param string $name
     * @param array $databaseInfo
     */
    public function __construct(string $name, array $databaseInfo);

    /**
     * Attempts to re-connect if previous connection lost.
     *
     * @param bool $checkOnly
     *      Check if connected.
     *
     * @return mixed|bool
     *      Bool: if arg $checkOnly.
     *
     * throws \SimpleComplex\Database\Exception\DbConnectionException
     */
    public function getConnection(bool $checkOnly = false);

    /**
     * Close database server connection.
     */
    public function disConnect();

    /**
     * @param bool $emptyOnNone
     *      False: on no error returns message indication just that.
     *      True: on no error return empty string.
     *
     * @return string
     */
    public function getNativeError(bool $emptyOnNone = false) : string;

    /**
     * @param string $name
     *
     * @return DbQueryInterface
     */
    public function query(string $name = '') : DbQueryInterface;

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
}

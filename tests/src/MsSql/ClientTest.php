<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database\MsSql;

use PHPUnit\Framework\TestCase;
use SimpleComplex\Tests\Database\TestHelper;
use SimpleComplex\Tests\Database\BrokerTest;
use SimpleComplex\Tests\Database\ConfigurationTest;

use SimpleComplex\Database\DatabaseBroker;
use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\MsSqlClient;

/**
 *
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MsSql/ClientTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class ClientTest extends TestCase
{
    /**
     * @see BrokerTest::testInstantiation()
     * @see ConfigurationTest::testMsSql()
     *
     * @return DbClientInterface|MsSqlClient
     */
    public function testInstantiation()
    {
        /** @var DatabaseBroker $db_broker */
        $db_broker = (new BrokerTest())->testInstantiation();
        $database_info = (new ConfigurationTest())->testMsSql();

        $client = $db_broker->getClient('test_scx_mssql', 'mssql', $database_info);
        static::assertInstanceOf(MsSqlClient::class, $client);

        return $client;
    }

    /**
     * @param DbClientInterface|MsSqlClient $client
     *
     * @depends testInstantiation
     */
    public function testConnection(DbClientInterface $client)
    {
        $connection = $client->getConnection(true);
        static::assertIsResource($connection);
    }

    /**
     * Throw \LogicException: client arg databaseInfo[pass] is empty.
     *
     * @see BrokerTest::testInstantiation()
     * @see ConfigurationTest::testMsSql()
     *
     * @expectedException \LogicException
     */
    public function testOptionEmpty()
    {
        /** @var DatabaseBroker $db_broker */
        $db_broker = (new BrokerTest())->testInstantiation();
        $database_info = (new ConfigurationTest())->testMsSql();

        $database_info['pass'] = '';
        /**
         * @throws \LogicException
         *     Database arg databaseInfo key[pass] is empty.
         */
        $db_broker->getClient('option-empty-client', 'mssql', $database_info);
    }

    /**
     * Throw DbConnectionException: client databaseInfo[database] doesn't exist.
     *
     * @see BrokerTest::testInstantiation()
     * @see ConfigurationTest::testMsSql()
     *
     * @expectedException \SimpleComplex\Database\Exception\DbConnectionException
     */
    public function testMalConnectionDatabaseNonexist()
    {
        /** @var DatabaseBroker $db_broker */
        $db_broker = (new BrokerTest())->testInstantiation();
        $database_info = (new ConfigurationTest())->testMsSql();

        $database_info['database'] = 'nonexistent_database';
        $client = $db_broker->getClient('database-non-exist-client', 'mssql', $database_info);
        static::assertInstanceOf(MsSqlClient::class, $client);

        $client->getConnection();
        static::assertSame(false, $client->isConnected());
        $errors = $client->getErrors();
        static::assertNotEmpty($errors);
        TestHelper::logVariable('Connection errors', $errors);
        /**
         * @throws \SimpleComplex\Database\Exception\DbConnectionException
         */
        $client->query('SELECT * FROM parent')->execute();
    }
}

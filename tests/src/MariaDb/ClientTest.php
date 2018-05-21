<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database\MariaDb;

use PHPUnit\Framework\TestCase;
use SimpleComplex\Tests\Database\TestHelper;
use SimpleComplex\Tests\Database\BrokerTest;
use SimpleComplex\Tests\Database\ConfigurationTest;

use SimpleComplex\Database\DatabaseBroker;
use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\MariaDbClient;

/**
 *
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MariaDb/ClientTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class ClientTest extends TestCase
{
    /**
     * @see BrokerTest::testInstantiation()
     * @see ConfigurationTest::testMariaDb()
     *
     * @return DbClientInterface|MariaDbClient
     */
    public function testInstantiation()
    {
        /** @var DatabaseBroker $db_broker */
        $db_broker = (new BrokerTest())->testInstantiation();
        $database_info = (new ConfigurationTest())->testMariaDb();

        $client = $db_broker->getClient('test_scx_mariadb', 'mariadb', $database_info);
        $this->assertInstanceOf(MariaDbClient::class, $client);

        return $client;
    }

    /**
     * @param DbClientInterface|MariaDbClient $client
     *
     * @depends testInstantiation
     */
    public function testConnection(DbClientInterface $client)
    {
        $connection = $client->getConnection(true);
        $this->assertInstanceOf(\mysqli::class, $connection);
    }

    /**
     * Throw \LogicException: client arg databaseInfo[pass] is empty.
     *
     * @see BrokerTest::testInstantiation()
     * @see ConfigurationTest::testMariaDb()
     *
     * @expectedException \LogicException
     */
    public function testOptionEmpty()
    {
        /** @var DatabaseBroker $db_broker */
        $db_broker = (new BrokerTest())->testInstantiation();
        $database_info = (new ConfigurationTest())->testMariaDb();

        $database_info['pass'] = '';
        /**
         * @throws \LogicException
         *     Database arg databaseInfo key[pass] is empty.
         */
        $db_broker->getClient('option-empty-client', 'mariadb', $database_info);
    }

    /**
     * Throw DbConnectionException: client databaseInfo[database] doesn't exist.
     *
     * @see BrokerTest::testInstantiation()
     * @see ConfigurationTest::testMariaDb()
     *
     * @expectedException \SimpleComplex\Database\Exception\DbConnectionException
     */
    public function testMalConnectionDatabaseNonexist()
    {
        /** @var DatabaseBroker $db_broker */
        $db_broker = (new BrokerTest())->testInstantiation();
        $database_info = (new ConfigurationTest())->testMariaDb();

        $database_info['database'] = 'nonexistent_database';
        $client = $db_broker->getClient('database-non-exist-client', 'mariadb', $database_info);
        $this->assertInstanceOf(MariaDbClient::class, $client);

        $client->getConnection();
        $this->assertSame(false, $client->isConnected());
        $errors = $client->getErrors();
        $this->assertNotEmpty($errors);
        TestHelper::logVariable('Connection errors', $errors);
        /**
         * @throws \SimpleComplex\Database\Exception\DbConnectionException
         */
        $client->query('SELECT * FROM parent')->execute();
    }
}

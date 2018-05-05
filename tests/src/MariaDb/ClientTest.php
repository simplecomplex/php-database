<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database\MariaDb;

use PHPUnit\Framework\TestCase;

use SimpleComplex\Database\DatabaseBroker;
use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\MariaDbClient;

use SimpleComplex\Tests\Database\BrokerTest;
use SimpleComplex\Tests\Database\ConfigurationTest;

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
     * @see BrokerTest::testInstantiation
     * @see ConfigurationTest::testMariaDb()
     *
     * @return DbClientInterface|MariaDbClient
     */
    public function testInstantiation()
    {
        /** @var DatabaseBroker $dbBroker */
        $dbBroker = (new BrokerTest())->testInstantiation();
        $databaseInfo = (new ConfigurationTest())->testMariaDb();

        $client = $dbBroker->getClient('scx_mssql_test', 'mariadb', $databaseInfo);
        $this->assertInstanceOf(MariaDbClient::class, $client);

        return $client;
    }

    /**
     *
     * @depends testInstantiation
     *
     * @param DbClientInterface|MariaDbClient $client
     *
    public function testOptions(DbClientInterface $client)
    {
        $client->optionsResolve();
    }*/

    /**
     * @param DbClientInterface|MariaDbClient $client
     *
     * @depends testInstantiation
     */
    public function testConnection(DbClientInterface $client)
    {
        $connection = $client->getConnection(true);
        $this->assertInstanceOf(\mysqli::class, $connection);
        $connected = $client->isConnected();
        $this->assertTrue($connected, 'Not connected');
    }
}

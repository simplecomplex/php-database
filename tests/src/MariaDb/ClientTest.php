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

use SimpleComplex\Tests\Database\Log;
use SimpleComplex\Tests\Database\Broker\BrokerTest;
use SimpleComplex\Tests\Database\ConfigurationTest;

/**
 * @package SimpleComplex\Tests\Database
 */
class ClientTest extends TestCase
{
    /**
     * @depends BrokerTest::testInstantiation
     * @depends ConfigurationTest::testMariaDb()
     *
     * @param DatabaseBroker $dbBroker
     * @param array $databaseInfo
     *
     * @return DbClientInterface|MariaDbClient
     */
    public function testInstantiation(DatabaseBroker $dbBroker, array $databaseInfo)
    {
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
     * @depends testInstantiation
     */
    public function testConnection(DbClientInterface $client)
    {
        $client->getConnection();
        $connected = $client->isConnected();
        $this->assertTrue($connected, 'Not connected');
    }
}

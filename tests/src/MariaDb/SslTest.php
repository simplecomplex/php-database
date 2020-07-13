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

use SimpleComplex\Tests\Database\BrokerTest;

use SimpleComplex\Database\DatabaseBroker;
use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\MariaDbClient;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/SslTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class SslTest extends TestCase
{
    /**
     * @see BrokerTest::testInstantiation()
     *
     * @return DbClientInterface|MariaDbClient
     */
    public function testInstantiation()
    {
        /** @var DatabaseBroker $db_broker */
        $db_broker = (new BrokerTest())->testInstantiation();

        $client = $db_broker->getClient('test_scx_mariadb_ssl', 'mariadb', [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'test_scx_mariadb',
            'user' => 'test_scx_mariadb_ssl',
            'pass' => '1234',
            'ssl_private_key' => '/etc/mysql/certs-client/client-key.pem',
            'ssl_public_key' => '/etc/mysql/certs-client/client-cert.pem',
            'ssl_ca_file' => '/etc/mysql/certs-client/ca.pem',
            'ssl_cipher' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES256-SHA384:ECDHE-RSA-AES256-SHA384:DHE-RSA-AES128-GCM-SHA256:DHE-DSS-AES128-GCM-SHA256:DHE-RSA-AES128-SHA256:DHE-DSS-AES128-SHA256:DHE-DSS-AES256-GCM-SHA384:DHE-RSA-AES256-SHA256:DHE-DSS-AES256-SHA256:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES256-SHA:ECDHE-ECDSA-AES256-SHA:DHE-DSS-AES128-SHA:DHE-RSA-AES128-SHA:DHE-RSA-AES256-SHA:AES128-GCM-SHA256:DH-DSS-AES128-GCM-SHA256:ECDH-ECDSA-AES128-GCM-SHA256:AES256-GCM-SHA384:DH-DSS-AES256-GCM-SHA384:ECDH-ECDSA-AES256-GCM-SHA384:AES128-SHA256:DH-DSS-AES128-SHA256:ECDH-ECDSA-AES128-SHA256:AES256-SHA256:DH-DSS-AES256-SHA256:ECDH-ECDSA-AES256-SHA384:AES128-SHA:DH-DSS-AES128-SHA:ECDH-ECDSA-AES128-SHA:AES256-SHA:DH-DSS-AES256-SHA:ECDH-ECDSA-AES256-SHA:DHE-RSA-AES256-GCM-SHA384:DH-RSA-AES128-GCM-SHA256:ECDH-RSA-AES128-GCM-SHA256:DH-RSA-AES256-GCM-SHA384:ECDH-RSA-AES256-GCM-SHA384',
            'flags' => 'MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT',
        ]);

        static::assertInstanceOf(MariaDbClient::class, $client);

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
        static::assertInstanceOf(\mysqli::class, $connection);
    }

    /**
     * @see SslTest::testInstantiation()
     */
    public function testResult()
    {
        /** @var MariaDbClient $client */
        $client = (new static())->testInstantiation();

        $data = $client->query(
            'SELECT * FROM child LIMIT 1'
        )->execute()->fetchArray();

        static::assertIsArray($data);
    }
}

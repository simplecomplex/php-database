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

use SimpleComplex\Database\MariaDbClient;
use SimpleComplex\Database\MariaDbQuery;
use SimpleComplex\Database\MariaDbResult;

/**
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MariaDb/ResultTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class ResultTest extends TestCase
{
    /**
     * Throw \InvalidArgumentException: query arg $sql effectively empty.
     *
     * @see BrokerTest::testInstantiation
     * @see ConfigurationTest::testMariaDb()
     */
    public function testMalArgSqlEmpty()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @var MariaDbQuery $query */
        $query = $client->multiQuery(
            'SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent'
            //'TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent'
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        $client->log('result', $result->nextSet());
        $client->log('result', $result->nextSet());
        $client->log('result', $result->nextSet());
        $client->log('result', $result->nextSet());
    }

}

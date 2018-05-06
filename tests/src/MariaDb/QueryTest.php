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

/**
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MariaDb/QueryTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class QueryTest extends TestCase
{
    /**
     * Throw \InvalidArgumentException: query arg $sql effectively empty.
     *
     * @see BrokerTest::testInstantiation
     * @see ConfigurationTest::testMariaDb()
     *
     * @expectedException \InvalidArgumentException
     */
    public function testMalArgSqlEmpty()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        $client->query(MariaDbQuery::SQL_TRIM . MariaDbQuery::SQL_TRIM);
    }

    /**
     * Throw \InvalidArgumentException: query option[cursor_mode] value invalid.
     *
     * @see BrokerTest::testInstantiation
     * @see ConfigurationTest::testMariaDb()
     *
     * @expectedException \InvalidArgumentException
     */
    public function testMalOptionCursorModeBad()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        $client->query(MariaDbQuery::SQL_SNIPPET['select_uuid'], [
            'cursor_mode' => 'rubbish',
        ]);
    }
}

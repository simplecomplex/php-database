<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database\MsSql;

use PHPUnit\Framework\TestCase;

use SimpleComplex\Database\MsSqlClient;
use SimpleComplex\Database\MsSqlQuery;

/**
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MsSql/QueryTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class QueryTest extends TestCase
{
    /**
     * Throw \InvalidArgumentException: query arg $sql effectively empty.
     *
     * @see ClientTest::testInstantiation()
     *
     * @expectedException \InvalidArgumentException
     */
    public function testMalArgSqlEmpty()
    {
        /** @var MsSqlClient $client */
        $client = (new ClientTest())->testInstantiation();

        /**
         * @throws \InvalidArgumentException
         *      Arg $sql resolves to empty.
         */
        $client->query(MsSqlQuery::SQL_TRIM . MsSqlQuery::SQL_TRIM);
    }

    /**
     * Throw \InvalidArgumentException: query option[cursor_mode] value invalid.
     *
     * @see ClientTest::testInstantiation()
     *
     * @expectedException \InvalidArgumentException
     */
    public function testMalOptionCursorModeBad()
    {
        /** @var MsSqlClient $client */
        $client = (new ClientTest())->testInstantiation();

        /**
         * @throws \InvalidArgumentException
         *      Arg $option['cursor_mode'] invalid.
         */
        $client->query(MsSqlQuery::SQL_SNIPPET['select_uuid'], [
            'cursor_mode' => 'rubbish',
        ]);
    }
}

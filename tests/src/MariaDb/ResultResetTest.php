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
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MariaDb/ResetResultTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class ResultResetTest extends TestCase
{
    /**
     * Throw DbQueryException: can't truncate due to foreign key constraint.
     *
     * @see ClientTest::testInstantiation
     *
     * @expectedException \SimpleComplex\Database\Exception\DbQueryException
     */
    public function testMalTruncateForeignKeys()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @var MariaDbQuery $query */
        $query = $client->multiQuery(
            'TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent'
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        // NB: MySQL multi-queries aren't executed until getting result sets,
        // not when execute()/MySQLi::multi_query().

        /**
         * @throws \SimpleComplex\Database\Exception\DbQueryException
         *      Due to foreign key constraint.
         */
        while($result->nextSet() !== null) {}
    }

    /**
     * Truncates all database tables, to reset test data.
     *
     * @see ClientTest::testInstantiation
     */
    public function testResetMultiQueryTruncate()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @var MariaDbQuery $query */
        $query = $client->multiQuery(
            'SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent'
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        $i = -1;
        while(($success = $result->nextSet()) !== null) {
            $this->assertSame(
                true,
                $success,
                'Result set[' . (++$i) . '] was type[' . gettype($success) . '] ~bool[' . !!$success . '].'
            );
        }
    }

}

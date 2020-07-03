<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018-2019 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database\MariaDb;

use PHPUnit\Framework\TestCase;
use SimpleComplex\Tests\Database\TestHelper;
use SimpleComplex\Tests\Database\Stringable;

use SimpleComplex\Utils\Bootstrap;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\Time\Time;

use SimpleComplex\Database\MariaDbClient;
use SimpleComplex\Database\DbQuery;
use SimpleComplex\Database\MariaDbQuery;
use SimpleComplex\Database\MariaDbResult;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit backend/vendor/simplecomplex/database/tests/src/MariaDb/StoredProcedureTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class StoredProcedureTest extends TestCase
{

    /**
     * @see \SimpleComplex\Database\DbQuery::VALIDATE_PARAMS
     */
    const VALIDATE_PARAMS = DbQuery::VALIDATE_FAILURE | DbQuery::VALIDATE_STRINGABLE_EXEC;

    /**
     * @see ResetTest::testResetStructure()
     */
    public function testReset()
    {
        $reset_test = new ResetTest();
        $reset_test->testResetStructure();
        /** @var MariaDbClient $client */
        $client = $reset_test->testResetPopulate();
        static::assertInstanceOf(MariaDbClient::class, $client);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testInsert()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'CALL typishInsert (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbsss';

        $time = new Time('2001-01-01');
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.0,
            '_2_decimal' => '2.0',
            '_3_varchar' => __FUNCTION__,
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->ISODate,
            '_6_datetime' => $time->ISODate,
            '_7_text' => 'MySQLi is not convincing',
        ];
        TestHelper::queryPrepareLogOnError($query, $types, $args);
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MariaDbResult::class, $result);

        static::assertSame(1, $result->affectedRows());
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testInsertSelect()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'CALL typishInsertSelect (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbsss';

        $time = new Time('2001-01-01');
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.0,
            '_2_decimal' => '2.0',
            '_3_varchar' => __FUNCTION__,
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->ISODate,
            '_6_datetime' => $time->ISODate,
            '_7_text' => 'MySQLi is not convincing',
        ];
        TestHelper::queryPrepareLogOnError($query, $types, $args);
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MariaDbResult::class, $result);

        //$this->assertSame(1, $result->affectedRows());

        //$this->assertSame(1, $result->nextSet());

        $record = $result->fetchArray();
        static::assertIsArray($record);
        TestHelper::logVariable(__FUNCTION__, $record);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testInsertSelectSelect()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'CALL typishInsertSelectSelect (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbsss';

        $time = new Time('2001-01-01');
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.0,
            '_2_decimal' => '2.0',
            '_3_varchar' => __FUNCTION__,
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->ISODate,
            '_6_datetime' => $time->ISODate,
            '_7_text' => 'MySQLi is not convincing',
        ];
        TestHelper::queryPrepareLogOnError($query, $types, $args);
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MariaDbResult::class, $result);

        //$this->assertSame(1, $result->affectedRows());

        //$this->assertSame(1, $result->nextSet());

        $insert_id = $result->fetchField();
        static::assertIsInt($insert_id);
        TestHelper::logVariable(__FUNCTION__, $insert_id);

        static::assertSame(true, $result->nextSet());
        $record = $result->fetchArray();
        static::assertIsArray($record);

        TestHelper::logVariable(__FUNCTION__, $record);
    }

}

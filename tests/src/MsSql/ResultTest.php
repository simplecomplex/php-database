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

use SimpleComplex\Time\Time;

use SimpleComplex\Database\MsSqlClient;
use SimpleComplex\Database\DbQuery;
use SimpleComplex\Database\DbResult;
use SimpleComplex\Database\MsSqlResult;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MsSql/ResultTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class ResultTest extends TestCase
{

    /**
     * @see \SimpleComplex\Database\DbQuery::VALIDATE_PARAMS
     */
    const VALIDATE_PARAMS = DbQuery::VALIDATE_PREPARE | DbQuery::VALIDATE_EXECUTE | DbQuery::VALIDATE_FAILURE;

    /**
     * @see ResetTest::testResetStructure()
     */
    public function testResetStructure()
    {
        $reset_test = new ResetTest();
        /** @var MsSqlClient $client */
        $client = $reset_test->testResetStructure();
        static::assertInstanceOf(MsSqlClient::class, $client);
    }

    /**
     * @see ClientTest::testInstantiation()
     * @see QueryArgumentTest::testQueryArgumentsReferred()
     */
    public function testEmptyFetchField()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT TOP(1) * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $column_by_index = $result->fetchField(4);
        static::assertNull($column_by_index);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $column_by_name = $result->fetchField(0, '_3_varchar');
        static::assertNull($column_by_index);

        //TestHelper::logVariable('', [ $column_by_index, $column_by_name]);

        $query = $client->query(
            'SELECT * FROM typish
            ORDER BY id ASC
            -- OFFSET 1 ROWS FETCH NEXT 1 ROWS ONLY is illegal :-(
            OFFSET 2 ROWS FETCH NEXT 1 ROWS ONLY',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $column_by_index = $result->fetchField(4);
        static::assertNull($column_by_index);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $column_by_name = $result->fetchField(0, '_3_varchar');
        static::assertNull($column_by_index);

        //TestHelper::logVariable('', [ $column_by_index, $column_by_name]);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testEmptyFetchArray()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_assoc = $result->fetchArray();
        static::assertNull($fetch_assoc);

        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_num = $result->fetchArray(DbResult::FETCH_NUMERIC);
        static::assertNull($fetch_num);

        //TestHelper::logVariable('fetch array assoc, fetch array num', [ $fetch_assoc, $fetch_num]);

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_assoc = $result->fetchArrayAll();
        static::assertIsArray($fetch_assoc);
        static::assertEmpty($fetch_assoc);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_num = $result->fetchArrayAll(DbResult::FETCH_NUMERIC);
        static::assertIsArray($fetch_num);
        static::assertEmpty($fetch_num);

        //TestHelper::logVariable('fetch all arrays assoc, fetch all arrays num', [ $fetch_assoc, $fetch_num]);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testEmptyFetchArrayAllListByColumn()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_assoc = $result->fetchArrayAll(DbResult::FETCH_ASSOC, '_3_varchar');
        static::assertIsArray($fetch_assoc);
        static::assertEmpty($fetch_assoc);

        //TestHelper::logVariable('fetch all arrays assoc+list by column', $fetch_assoc);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testEmptyFetchObject()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_object = $result->fetchObject();
        static::assertNull($fetch_object);

        $fetch_typed = $result->fetchObject(Typish::class);
        static::assertNull($fetch_typed);

        $fetch_typed_w_args = $result->fetchObject(Typish::class, ['hello']);
        static::assertNull($fetch_typed_w_args);

        //TestHelper::logVariable('fetch object, fetch typed', [$fetch_object, $fetch_typed, $fetch_typed_w_args]);

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_object = $result->fetchObjectAll();
        static::assertIsArray($fetch_object);
        static::assertEmpty($fetch_object);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_typed = $result->fetchObjectAll(Typish::class);
        static::assertIsArray($fetch_typed);
        static::assertEmpty($fetch_typed);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_typed_w_args = $result->fetchObjectAll(Typish::class, '_3_varchar', ['hello']);
        static::assertIsArray($fetch_typed_w_args);
        static::assertEmpty($fetch_typed_w_args);

        //TestHelper::logVariable('fetch all objects, fetch all typed, fetch all typed with args', [$fetch_object, $fetch_typed, $fetch_typed_w_args]);
    }

    /**
     * @see ResetTest::testResetStructure()
     * @see ResetTest::testResetPopulate()
     */
    public function testReset()
    {
        $reset_test = new ResetTest();
        $reset_test->testResetStructure();
        /** @var MsSqlClient $client */
        $client = $reset_test->testResetPopulate();
        static::assertInstanceOf(MsSqlClient::class, $client);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testQueryInsertId()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'insert_id' => true,
            ]
        );

        $types = 'idssbsss';

        $time = new Time();
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.1,
            '_2_decimal' => '2.2',
            '_3_varchar' => 'insert id as int',
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->ISODate,
            '_6_datetime' => $time,
            '_7_nvarchar' => 'n varchar',
        ];
        TestHelper::queryPrepareLogOnError($query, $types, $args);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $insert_id = $result->insertId('i');
        static::assertIsInt($insert_id);
        static::assertNotEmpty($insert_id);

        $args['_0_int'] = 1;
        $args['_1_float'] = 2.2;
        $args['_2_decimal'] = '3.3';
        $args['_3_varchar'] = 'insert id as float';
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $insert_id = $result->insertId('d');
        static::assertIsFloat($insert_id);
        static::assertNotEmpty($insert_id);

        $args['_0_int'] = 2;
        $args['_1_float'] = 3.3;
        $args['_2_decimal'] = '4.4';
        $args['_3_varchar'] = 'insert id as string';
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $insert_id = $result->insertId('s');
        static::assertIsString($insert_id);
        static::assertNotEmpty($insert_id);
    }

    /**
     * @see ClientTest::testInstantiation()
     * @see QueryArgumentTest::testQueryArgumentsReferred()
     */
    public function testFetchField()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT TOP(1) * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $column_by_index = $result->fetchField(4);
        static::assertIsString($column_by_index);
        static::assertNotEmpty($column_by_index);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $column_by_name = $result->fetchField(0, '_3_varchar');
        static::assertIsString($column_by_name);
        static::assertNotEmpty($column_by_name);

        //TestHelper::logVariable('', [ $column_by_index, $column_by_name]);

        $query = $client->query(
            'SELECT * FROM typish
            ORDER BY id ASC
            -- OFFSET 1 ROWS FETCH NEXT 1 ROWS ONLY is illegal :-(
            OFFSET 2 ROWS FETCH NEXT 1 ROWS ONLY',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $column_by_index = $result->fetchField(4);
        static::assertIsString($column_by_index);
        static::assertNotEmpty($column_by_index);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $column_by_name = $result->fetchField(0, '_3_varchar');
        static::assertIsString($column_by_name);
        static::assertNotEmpty($column_by_name);

        //TestHelper::logVariable('', [ $column_by_index, $column_by_name]);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testFetchArray()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_assoc = $result->fetchArray();
        static::assertIsArray($fetch_assoc);
        static::assertNotEmpty($fetch_assoc);

        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_num = $result->fetchArray(DbResult::FETCH_NUMERIC);
        static::assertIsArray($fetch_num);
        static::assertNotEmpty($fetch_num);

        //TestHelper::logVariable('fetch array assoc, fetch array num', [ $fetch_assoc, $fetch_num]);

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_assoc = $result->fetchArrayAll();
        static::assertIsArray($fetch_assoc);
        static::assertNotEmpty($fetch_assoc);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_num = $result->fetchArrayAll(DbResult::FETCH_NUMERIC);
        static::assertIsArray($fetch_num);
        static::assertNotEmpty($fetch_num);

        //TestHelper::logVariable('fetch all arrays assoc, fetch all arrays num', [ $fetch_assoc, $fetch_num]);
    }

    /**
     * @see ClientTest::testInstantiation()
     *
     * @expectedException \InvalidArgumentException
     */
    public function testFetchArrayAllListByColumn()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_assoc = $result->fetchArrayAll(DbResult::FETCH_ASSOC, '_3_varchar');
        static::assertIsArray($fetch_assoc);
        static::assertNotEmpty($fetch_assoc);

        //TestHelper::logVariable('fetch all arrays assoc+list by column', $fetch_assoc);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        /**
         * Non-empty arg $list_by_column when arg $as is FETCH_NUMERIC.
         * @throws \InvalidArgumentException
         */
        $result->fetchArrayAll(DbResult::FETCH_NUMERIC, '_3_varchar');
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testFetchObject()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_object = $result->fetchObject();
        static::assertInstanceOf(\stdClass::class, $fetch_object);

        $fetch_typed = $result->fetchObject(Typish::class);
        static::assertInstanceOf(Typish::class, $fetch_typed);

        $fetch_typed_w_args = $result->fetchObject(Typish::class, ['hello']);
        static::assertInstanceOf(Typish::class, $fetch_typed_w_args);

        //TestHelper::logVariable('fetch object, fetch typed', [$fetch_object, $fetch_typed, $fetch_typed_w_args]);

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_object = $result->fetchObjectAll();
        static::assertIsArray($fetch_object);
        static::assertNotEmpty($fetch_object);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_typed = $result->fetchObjectAll(Typish::class);
        static::assertIsArray($fetch_typed);
        static::assertNotEmpty($fetch_typed);

        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_typed_w_args = $result->fetchObjectAll(Typish::class, '_3_varchar', ['hello']);
        static::assertIsArray($fetch_typed_w_args);
        static::assertNotEmpty($fetch_typed_w_args);

        //TestHelper::logVariable('fetch all objects, fetch all typed, fetch all typed with args', [$fetch_object, $fetch_typed, $fetch_typed_w_args]);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testFetchFieldAll()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_index = $result->fetchFieldAll(4);
        static::assertIsArray($fetch_index);
        static::assertNotEmpty($fetch_index);
        //TestHelper::logVariable('fetch index', $fetch_index);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_name = $result->fetchFieldAll(0, '_3_varchar');
        static::assertIsArray($fetch_name);
        static::assertNotEmpty($fetch_name);
        //TestHelper::logVariable('fetch name', $fetch_name);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_name_listbyname = $result->fetchFieldAll(0, '_3_varchar', '_2_decimal');
        static::assertIsArray($fetch_name_listbyname);
        static::assertNotEmpty($fetch_name_listbyname);
        //TestHelper::logVariable('fetch name list by column', $fetch_name_listbyname);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testFetchFieldNoRow()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM emptyish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepareLogOnError($query);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_index = $result->fetchField(1);
        static::assertNull($fetch_index);
        //TestHelper::logVariable('fetch index', $fetch_index);

        /** @var MsSqlResult $result */
        $result = TestHelper::logOnError('query execute', $query, 'execute');
        static::assertInstanceOf(MsSqlResult::class, $result);
        $fetch_index = $result->fetchFieldAll(1);
        static::assertIsArray($fetch_index);
        static::assertEmpty($fetch_index);
        //TestHelper::logVariable('fetch all index', $fetch_index);
    }
}

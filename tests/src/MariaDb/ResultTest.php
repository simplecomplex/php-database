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

use SimpleComplex\Time\Time;

use SimpleComplex\Database\MariaDbClient;
use SimpleComplex\Database\DbQuery;
use SimpleComplex\Database\DbResult;
use SimpleComplex\Database\MariaDbResult;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/ResultTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class ResultTest extends TestCase
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
    public function testQueryInsertId()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'insert_id' => true,
            ]
        );

        $types = 'idssbss';

        $time = new Time();
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.1,
            '_2_decimal' => '2.2',
            '_3_varchar' => 'insert id as int',
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->ISODate,
            '_6_datetime' => $time->ISODate,
        ];
        $query->prepare($types, $args);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $insert_id = $result->insertId('i');
        static::assertIsInt($insert_id);
        static::assertNotEmpty($insert_id);

        $args['_0_int'] = 1;
        $args['_1_float'] = 2.2;
        $args['_2_decimal'] = '3.3';
        $args['_3_varchar'] = 'insert id as float';
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $insert_id = $result->insertId('d');
        static::assertIsFloat($insert_id);
        static::assertNotEmpty($insert_id);

        $args['_0_int'] = 2;
        $args['_1_float'] = 3.3;
        $args['_2_decimal'] = '4.4';
        $args['_3_varchar'] = 'insert id as string';
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $insert_id = $result->insertId('s');
        static::assertIsString($insert_id);
        static::assertNotEmpty($insert_id);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testFetchField()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'SELECT * FROM typish
            LIMIT 1',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        $args = []; $query->prepare('', $args);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $column_by_index = $result->fetchField(4);
        static::assertIsString($column_by_index);
        static::assertNotEmpty($column_by_index);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $column_by_name = $result->fetchField(0, '_3_varchar');
        static::assertIsString($column_by_name);
        static::assertNotEmpty($column_by_name);

        $query = $client->query(
            'SELECT * FROM typish
            LIMIT 1 OFFSET 1',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        $args = []; $query->prepare('', $args);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $column_by_index = $result->fetchField(4);
        static::assertIsString($column_by_index);
        static::assertNotEmpty($column_by_index);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $column_by_name = $result->fetchField(0, '_3_varchar');
        static::assertIsString($column_by_name);
        static::assertNotEmpty($column_by_name);
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
        $args = []; $query->prepare('', $args);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_assoc = $result->fetchArray();
        static::assertIsArray($fetch_assoc);
        static::assertNotEmpty($fetch_assoc);

        $fetch_num = $result->fetchArray(DbResult::FETCH_NUMERIC);
        static::assertIsArray($fetch_num);
        static::assertNotEmpty($fetch_num);

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        $args = []; $query->prepare('', $args);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_assoc = $result->fetchArrayAll();
        static::assertIsArray($fetch_assoc);
        static::assertNotEmpty($fetch_assoc);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_num = $result->fetchArrayAll(DbResult::FETCH_NUMERIC);
        static::assertIsArray($fetch_num);
        static::assertNotEmpty($fetch_num);
    }

    /**
     * @see ClientTest::testInstantiation()
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
        $args = []; $query->prepare('', $args);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_assoc = $result->fetchArrayAll(DbResult::FETCH_ASSOC, '_3_varchar');
        static::assertIsArray($fetch_assoc);
        static::assertNotEmpty($fetch_assoc);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        /**
         * Non-empty arg $list_by_column when arg $as is FETCH_NUMERIC.
         * @throws \InvalidArgumentException
         */
        static::expectException(\InvalidArgumentException::class);
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
        $args = []; $query->prepare('', $args);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_object = $result->fetchObject();
        static::assertInstanceOf(\stdClass::class, $fetch_object);

        $fetch_typed = $result->fetchObject(Typish::class);
        static::assertInstanceOf(Typish::class, $fetch_typed);

        $fetch_typed_w_args = $result->fetchObject(Typish::class, ['hello']);
        static::assertInstanceOf(Typish::class, $fetch_typed_w_args);

        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        $args = []; $query->prepare('', $args);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_object = $result->fetchObjectAll();
        static::assertIsArray($fetch_object);
        static::assertNotEmpty($fetch_object);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_typed = $result->fetchObjectAll(Typish::class);
        static::assertIsArray($fetch_typed);
        static::assertNotEmpty($fetch_typed);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_typed_w_args = $result->fetchObjectAll(Typish::class, '_3_varchar', ['hello']);
        static::assertIsArray($fetch_typed_w_args);
        static::assertNotEmpty($fetch_typed_w_args);
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
        $args = []; $query->prepare('', $args);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_index = $result->fetchFieldAll(4);
        static::assertIsArray($fetch_index);
        static::assertNotEmpty($fetch_index);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_name = $result->fetchFieldAll(0, '_3_varchar');
        static::assertIsArray($fetch_name);
        static::assertNotEmpty($fetch_name);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_name_listbyname = $result->fetchFieldAll(0, '_3_varchar', '_2_decimal');
        static::assertIsArray($fetch_name_listbyname);
        static::assertNotEmpty($fetch_name_listbyname);
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
        $args = []; $query->prepare('', $args);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_index = $result->fetchField(1);
        static::assertNull($fetch_index);

        /** @var MariaDbResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_index = $result->fetchFieldAll(1);
        static::assertIsArray($fetch_index);
        static::assertEmpty($fetch_index);
    }

    /**
     * @see ClientTest::testInstantiation()
     */
    public function testTypedIntFloat()
    {
        $client = (new ClientTest())->testInstantiation();

        // Prepared statement should always return typed int and float.
        $query = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
            ]
        );
        $args = []; $query->prepare('', $args);

        $result = $query->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_assoc = $result->fetchArray();
        static::assertIsArray($fetch_assoc);
        static::assertNotEmpty($fetch_assoc);
        static::assertIsInt($fetch_assoc['id'], 'typed int');
        static::assertIsInt($fetch_assoc['_0_int'], 'typed int');
        static::assertIsFloat($fetch_assoc['_1_float'], 'typed float');

        // Non-prepared statement should also return typed int and float
        // due to MYSQLI_OPT_INT_AND_FLOAT_NATIVE:1.
        $result = $client->query(
            'SELECT * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
            ]
        )->execute();
        static::assertInstanceOf(MariaDbResult::class, $result);
        $fetch_assoc = $result->fetchArray();
        static::assertIsArray($fetch_assoc);
        static::assertNotEmpty($fetch_assoc);
        static::assertIsInt($fetch_assoc['id'], 'typed int');
        static::assertIsInt($fetch_assoc['_0_int'], 'typed int');
        static::assertIsFloat($fetch_assoc['_1_float'], 'typed float');
    }

}

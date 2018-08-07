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
use SimpleComplex\Tests\Database\Stringable;

use SimpleComplex\Database\MsSqlClient;
use SimpleComplex\Database\DbQuery;
use SimpleComplex\Database\MsSqlQuery;
use SimpleComplex\Database\MsSqlResult;
use SimpleComplex\Utils\Time;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit backend/vendor/simplecomplex/database/tests/src/MsSql/QueryArgumentTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class QueryArgumentTest extends TestCase
{

    /**
     * @see \SimpleComplex\Database\DbQuery::VALIDATE_PARAMS
     */
    const VALIDATE_PARAMS = DbQuery::VALIDATE_PREPARE | DbQuery::VALIDATE_EXECUTE | DbQuery::VALIDATE_FAILURE;

    /**
     * Arguments referred; old-school pattern.
     *
     * @see ResetTest::testResetPopulate()
     */
    public function testQueryArgumentsReferred()
    {
        $reset_test = new ResetTest();
        $reset_test->testResetStructure();
        /** @var MsSqlClient $client */
        $client = $reset_test->testResetPopulate();

        /** @var MsSqlQuery $query */
        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $time = new Time();
        $_0_int = 0;
        $_1_float = 1.0;
        $_2_decimal = '2.0';
        $_3_varchar = 'arguments referred';
        $_4_blob = sprintf("%08d", decbin(4));
        $_5_date = $time->getDateISOlocal();
        $_6_datetime = '' . $time;
        $_7_nvarchar = 'n varchar';

        $types = 'idssbsss';

        $args = [
            &$_0_int,
            &$_1_float,
            &$_2_decimal,
            &$_3_varchar,
            &$_4_blob,
            &$_5_date,
            &$_6_datetime,
            &$_7_nvarchar,
        ];
        TestHelper::queryPrepare($query, $types, $args);
        /** @var MsSqlResult $result */
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $affected_rows = $result->affectedRows();
        $this->assertInternalType('int', $affected_rows);
        $this->assertSame(1, $affected_rows);

        $_1_float = 1.1;
        $_2_decimal = '2.2';
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Update numerically indexed arguments buckets.
     *
     * @see ClientTest::testInstantiation()
     */
    public function testQueryArgumentsIndexed()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbsss';

        $time = new Time();
        $args = [
            0,
            1.0,
            '2.0',
            'arguments indexed',
            sprintf("%08d", decbin(4)),
            $time->getDateISOlocal(),
            '' . $time,
            'n varchar',
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        $args[1] = 1.1;
        $args[2] = '2.2';
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Update assoc. keyed arguments buckets.
     *
     * @see ClientTest::testInstantiation()
     */
    public function testQueryArgumentsKeyed()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbsss';

        $time = new Time();
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.0,
            '_2_decimal' => '2.0',
            '_3_varchar' => 'arguments keyed',
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->getDateISOlocal(),
            '_6_datetime' => '' . $time,
            '_7_nvarchar' => 'n varchar',
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        $args['_1_float'] = 1.1;
        $args['_2_decimal'] = 2.2;
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Does the DBMS stringify objects having __toString() method?
     *
     * @see ClientTest::testInstantiation()
     *
     * @expectedException \SimpleComplex\Database\Exception\DbRuntimeException
     */
    public function testQueryArgumentsStringable()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbsss';

        $time = new Time();
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.0,
            '_2_decimal' => '2.0',
            '_3_varchar' => new \DateTime('2000-01-01'), //'stringable', //new Stringable('stringable'),
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->getDateISOlocal(),

            // \DateTime gets successfully stringed.
            '_6_datetime' => new \DateTime('2000-01-01'),

            '_7_nvarchar' => 'n varchar',
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        /**
         * Sqlsrv apparantly doesn't use an object's __toString() method.
         * Sqlsrv \DateTime handling must be class specific;
         * see \DateTime argument right above.
         *
         * If
         * @see DbQuery::VALIDATE_PARAMS
         * is
         * @see DbQuery::VALIDATE_ALWAYS
         * @throws \SimpleComplex\Database\Exception\DbQueryArgumentException
         *
         * Else
         * @throws \SimpleComplex\Database\Exception\DbRuntimeException
         */
        $args['_3_varchar'] = new Stringable('stringable');

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Update type qualified arguments referred; old-school pattern.
     *
     * @see ClientTest::testInstantiation()
     */
    public function testQueryArgumentsTypeQualifiedSimple()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        //$types = 'idssbsss';
        $types = '';

        $time = new Time();
        $_0_int = 0;
        $_1_float = 1.0;
        $_2_decimal = '2.0';
        $_3_varchar = 'sqlsrv arguments referred';
        $_4_blob = sprintf("%08d", decbin(4));
        $_5_date = $time;
        $_6_datetime = $time;
        $_7_nvarchar = 'n varchar';

        $args = [
            [
                &$_0_int,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_INT,
            ],
            [
                &$_1_float,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_FLOAT,
            ],
            [
                &$_2_decimal,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DECIMAL(14,2),
            ],
            [
                &$_3_varchar,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARCHAR('max'),
            ],
            [
                &$_4_blob,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARBINARY('max'),
            ],
            [
                &$_5_date,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DATE,
            ],
            [
                &$_6_datetime,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DATETIME2,
            ],
            [
                &$_7_nvarchar,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_NVARCHAR('max'),
            ],
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        $_1_float = 1.1;
        $_2_decimal = '2.2';
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Update type qualified numerically indexed arguments buckets.
     *
     * @see ClientTest::testInstantiation()
     */
    public function testQueryArgumentsTypeQualifiedIndexed()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        //$types = 'idssbsss';
        $types = '';

        $time = new Time();
        $args = [
            [
                0,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_INT,
            ],
            [
                1.0,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_FLOAT,
            ],
            [
                '2.0',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DECIMAL(14,2),
            ],
            [
                'sqlsrv arguments indexed',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARCHAR('max'),
            ],
            [
                sprintf("%08d", decbin(4)),
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARBINARY('max'),
            ],
            [
                $time,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DATE,
            ],
            [
                $time,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DATETIME2,
            ],
            [
                'n varchar',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_NVARCHAR('max'),
            ],
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        $args[1][0] = 1.1;
        $args[2][0] = '2.2';
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Update type qualified assoc. keyed arguments buckets.
     *
     * @see ClientTest::testInstantiation()
     */
    public function testQueryArgumentsTypeQualifiedKeyed()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        //$types = 'idssbsss';
        $types = '';

        $time = new Time();
        $args = [
            '_0_int' => [
                0,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_INT,
            ],
            '_1_float' => [
                1.0,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_FLOAT,
            ],
            '_2_decimal' => [
                '2.0',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DECIMAL(14,2),
            ],
            '_3_varchar' => [
                'sqlsrv arguments keyed',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARCHAR('max'),
            ],
            '_4_blob' => [
                sprintf("%08d", decbin(4)),
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARBINARY('max'),
            ],
            '_5_date' => [
                $time,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DATE,
            ],
            '_6_datetime' => [
                $time,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DATETIME2,
            ],
            '_7_nvarchar' => [
                'n varchar',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_NVARCHAR('max'),
            ],
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        $args['_1_float'][0] = 1.1;
        $args['_2_decimal'][0] ='2.2';
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Detect argument types, using arguments' actual types.
     *
     * @see ClientTest::testInstantiation()
     */
    public function testQueryArgumentTypesDetect()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, null, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = '';

        $time = new Time();
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.0,
            '_2_decimal' => '2.0',
            '_3_varchar' => 'arguments types detected',
            /*'_4_blob' => [
                sprintf("%08d", decbin(4)),
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARBINARY('max'),
            ],*/
            '_5_date' => $time,
            '_6_datetime' => $time,
            '_7_nvarchar' => 'n varchar',
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Detect argument types, using arguments' actual types.
     *
     * @see ClientTest::testInstantiation()
     */
    public function testQueryArgumentTypeQualifiedPartially()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbsss';

        $time = new Time();
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.0,
            '_2_decimal' => '2.0',
            '_3_varchar' => 'arguments type qualified partially',
            '_4_blob' => [
                sprintf("%08d", decbin(4)),
                //new TestHelper(),
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARBINARY('max'),
            ],
            '_5_date' => $time,
            '_6_datetime' => [
                $time,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARCHAR('max'),
            ],
            '_7_nvarchar' => 'n varchar',
        ];
        TestHelper::queryPrepare($query, $types, $args);

        //TestHelper::logVariable(__FUNCTION__ . ' query', $query);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }



    /**
     * Type qualifying arguments, checked strictly.
     *
     * @see ClientTest::testInstantiation()
     */
    public function testQueryArgumentsTypeQualifiedStrict()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_nvarchar,
                _8_bit, _9_time, _10_uuid)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'name' => __FUNCTION__,
                'validate_params' => 3,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        //$types = 'idssbsss';
        $types = '';

        $time = new Time();
        $args = [
            '_0_int' => [
                0,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_INT,
            ],
            '_1_float' => [
                1.0,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_FLOAT,
            ],
            '_2_decimal' => [
                '2.0',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DECIMAL(14,2),
            ],
            '_3_varchar' => [
                'type qualified strict',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARCHAR('max'),
            ],
            '_4_blob' => [
                sprintf("%08d", decbin(4)),
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_VARBINARY('max'),
            ],
            '_5_date' => [
                $time,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_DATE,
            ],
            '_6_datetime' => [
                $time,
                SQLSRV_PARAM_IN,
                null,
                //SQLSRV_SQLTYPE_DATETIME2,
                SQLSRV_SQLTYPE_DATETIME,
            ],
            '_7_nvarchar' => [
                'n varchar',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_NVARCHAR('max'),
            ],
            '_8_bit' => [
                0,
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_BIT,
            ],
            '_9_time' => [
                '10:30:01',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_TIME,
            ],
            '_10_uuid' => [
                '123e4567-e89b-12d3-a456-426655440000',
                SQLSRV_PARAM_IN,
                null,
                SQLSRV_SQLTYPE_UNIQUEIDENTIFIER,
            ],
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        $args['_0_int'][0] = '1';
        //$args['_0_int'][0] = 'hest';
        $args['_1_float'][0] = '1.1';
        $args['_2_decimal'][0] ='2.2';
        $args['_3_varchar'][0] = 'type qualified strict updated';
        $args['_5_date'][0] = $time->getDateISOlocal();
        //$args['_5_date'][0] = 'cykel';
        $args['_6_datetime'][0] = $time->getDateISOlocal();
        $args['_8_bit'][0] = true;
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }
}

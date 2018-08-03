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

use SimpleComplex\Database\MsSqlClient;
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
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $time = new Time();
        $_0_int = 0;
        $_1_float = 1.0;
        $_2_decimal = '2.0';
        $_3_varchar = 'arguments referred';
        $_4_blob = decbin(4);
        $_5_date = $time->getDateISOlocal();
        $_6_datetime = '' . $time;

        $types = 'idssbss';

        $args = [
            &$_0_int,
            &$_1_float,
            &$_2_decimal,
            &$_3_varchar,
            &$_4_blob,
            &$_5_date,
            &$_6_datetime,
        ];
        $query->prepare($types, $args);
        /** @var MsSqlResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $affected_rows = $result->affectedRows();
        $this->assertInternalType('int', $affected_rows);
        $this->assertSame(1, $affected_rows);

        $_1_float = 1.1;
        $_2_decimal = '2.2';
        $result = $query->execute();
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
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbss';

        $time = new Time();
        $args = [
            0,
            1.0,
            '2.0',
            'arguments indexed',
            decbin(4),
            $time->getDateISOlocal(),
            '' . $time,
        ];
        $query->prepare($types, $args);
        $result = $query->execute();
        $this->assertSame(1, $result->affectedRows());

        $args[1] = 1.1;
        $args[2] = '2.2';
        $result = $query->execute();
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
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbss';

        $time = new Time();
        $args = [
            '_0_int' => 0,
            '_1_float' => 1.0,
            '_2_decimal' => '2.0',
            '_3_varchar' => 'arguments keyed',
            '_4_blob' => decbin(4),
            '_5_date' => $time->getDateISOlocal(),
            '_6_datetime' => '' . $time,
        ];
        $query->prepare($types, $args);
        $result = $query->execute();
        $this->assertSame(1, $result->affectedRows());

        $args['_1_float'] = 1.1;
        $args['_2_decimal'] = 2.2;
        $result = $query->execute();
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
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        //$types = 'idssbss';
        $types = '';

        $time = new Time();
        $_0_int = 0;
        $_1_float = 1.0;
        $_2_decimal = '2.0';
        $_3_varchar = 'sqlsrv arguments referred';
        $_4_blob = decbin(4);
        $_5_date = $time;
        $_6_datetime = $time;

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
        ];
        $query->prepare($types, $args);
        $result = $query->execute();
        $this->assertSame(1, $result->affectedRows());

        $_1_float = 1.1;
        $_2_decimal = '2.2';
        $result = $query->execute();
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
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        //$types = 'idssbss';
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
                decbin(4),
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
        ];
        $query->prepare($types, $args);
        $result = $query->execute();
        $this->assertSame(1, $result->affectedRows());

        $args[1][0] = 1.1;
        $args[2][0] = '2.2';
        $result = $query->execute();
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
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        //$types = 'idssbss';
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
                decbin(4),
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
        ];
        $query->prepare($types, $args);
        $result = $query->execute();
        $this->assertSame(1, $result->affectedRows());

        $args['_1_float'][0] = 1.1;
        $args['_2_decimal'][0] ='2.2';
        $result = $query->execute();
        $this->assertSame(1, $result->affectedRows());
    }
}

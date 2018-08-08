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
use SimpleComplex\Tests\Database\TestHelper;
use SimpleComplex\Tests\Database\Stringable;

use SimpleComplex\Utils\Bootstrap;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\Time;

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
     * Arguments referred; old-school pattern.
     *
     * @see ResetTest::testResetPopulate()
     */
    public function testQueryArgumentsReferred()
    {
        $reset_test = new ResetTest();
        $reset_test->testResetStructure();
        /** @var MariaDbClient $client */
        $client = $reset_test->testResetPopulate();

        /** @var MariaDbQuery $query */
        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
                'affected_rows' => true,
            ]
        );

        $types = 'idssbss';

        $time = new Time();
        $_0_int = 0;
        $_1_float = 1.0;
        $_2_decimal = '2.0';
        $_3_varchar = 'arguments referred';
        $_4_blob = sprintf("%08d", decbin(4));
        $_5_date = $time->getDateISOlocal();
        $_6_datetime = '' . $time;
        
        $args = [
            &$_0_int,
            &$_1_float,
            &$_2_decimal,
            &$_3_varchar,
            &$_4_blob,
            &$_5_date,
            &$_6_datetime,
        ];
        TestHelper::queryPrepare($query, $types, $args);
        /** @var MariaDbResult $result */
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $affected_rows = $result->affectedRows();
        $this->assertInternalType('int', $affected_rows);
        $this->assertSame(1, $affected_rows);

        $_1_float = 1.1;
        $_2_decimal = '2.2';
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
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
                'validate_params' => static::VALIDATE_PARAMS,
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
            sprintf("%08d", decbin(4)),
            $time->getDateISOlocal(),
            '' . $time,
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        $args[1] = 1.1;
        $args[2] = '2.2';
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
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
                'validate_params' => static::VALIDATE_PARAMS,
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
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->getDateISOlocal(),
            '_6_datetime' => '' . $time,
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        $args['_1_float'] = 1.1;
        $args['_2_decimal'] = '2.2';
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
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
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
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
            '_3_varchar' => 'stringable',
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->getDateISOlocal(),
            '_6_datetime' => '' . $time,
            '_7_text' => '',
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $this->assertSame(1, $result->affectedRows());

        /**
         * But MySQLi doesn't check if object has __toString() method.
         *
         * If
         * @see DbQuery::VALIDATE_PARAMS
         * is
         * @see DbQuery::VALIDATE_ALWAYS
         * @throws \SimpleComplex\Database\Exception\DbQueryArgumentException
         *
         * Else
         * throws fatal error :-(
         *
         * @throws \SimpleComplex\Database\Exception\DbRuntimeException
         */
        $args['_6_datetime'] = new \DateTime('2000-01-01');
        // Yes, MySQLi attempts to stringify object.
        $args['_7_text'] = new Stringable('stringable');

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
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
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, null, ?, ?)',
            [
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
            //'_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->getDateISOlocal(),
            '_6_datetime' => '' . $time,
        ];
        TestHelper::queryPrepare($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }

    /**
     * Does the DBMS stringify objects having __toString() method?
     *
     * @see ClientTest::testInstantiation()
     *
     * @expectedException \SimpleComplex\Database\Exception\DbRuntimeException
     */
    public function testSimpleQueryArgumentsStringable()
    {
        $client = (new ClientTest())->testInstantiation();

        $query = $client->query(
            'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'validate_params' => 0, //static::VALIDATE_PARAMS,
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
            '_3_varchar' => 'simple stringable',
            '_4_blob' => sprintf("%08d", decbin(4)),
            '_5_date' => $time->getDateISOlocal(),
            /**
             * But MySQLi doesn't check if object has __toString() method.
             *
             * If
             * @see DbQuery::VALIDATE_PARAMS
             * is
             * @see DbQuery::VALIDATE_ALWAYS
             * @throws \SimpleComplex\Database\Exception\DbQueryArgumentException
             *
             * Else
             * throws fatal error :-(
             *
             * @throws \SimpleComplex\Database\Exception\DbRuntimeException
             */
            '_6_datetime' => new \DateTime('2000-01-01'),
            // Yes, MySQLi attempts to stringify object.
            '_7_text' => new Stringable('simple stringable'),
        ];
        TestHelper::queryParameters($query, $types, $args);
        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $this->assertSame(1, $result->affectedRows());
    }
}

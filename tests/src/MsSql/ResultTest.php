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
 * backend/vendor/bin/phpunit backend/vendor/simplecomplex/database/tests/src/MsSql/ResultTest.php
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
    public function testReset()
    {
        $reset_test = new ResetTest();
        $reset_test->testResetStructure();
        /** @var MsSqlClient $client */
        $client = $reset_test->testResetPopulate();
        $this->assertInstanceOf(MsSqlClient::class, $client);
    }

    /**
     * @see ClientTest::testInstantiation()
     * @see QueryArgumentTest::testQueryArgumentsReferred()
     */
    public function testFetchColumn()
    {
        $client = (new ClientTest())->testInstantiation();

        (new QueryArgumentTest())->testQueryArgumentsReferred();

        $query = $client->query(
            'SELECT TOP(1) * FROM typish',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepare($query);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $column_by_index = $result->fetchColumn(4);
        $this->assertInternalType('string', $column_by_index);
        $this->assertNotEmpty($column_by_index);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $column_by_name = $result->fetchColumn(0, '_3_varchar');
        $this->assertInternalType('string', $column_by_name);
        $this->assertNotEmpty($column_by_name);

        TestHelper::logVariable('', [ $column_by_index, $column_by_name]);

        $query = $client->query(
            'SELECT * FROM typish
            ORDER BY id ASC
            -- FETCH NEXT 1 is illegal :-(
            OFFSET 1 ROWS FETCH NEXT 2 ROWS ONLY',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepare($query);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $column_by_index = $result->fetchColumn(4);
        $this->assertInternalType('string', $column_by_index);
        $this->assertNotEmpty($column_by_index);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MsSqlResult::class, $result);
        $column_by_name = $result->fetchColumn(0, '_3_varchar');
        $this->assertInternalType('string', $column_by_name);
        $this->assertNotEmpty($column_by_name);

        TestHelper::logVariable('', [ $column_by_index, $column_by_name]);
    }
}

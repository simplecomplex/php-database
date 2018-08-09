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

use SimpleComplex\Utils\Time;

use SimpleComplex\Database\MariaDbClient;
use SimpleComplex\Database\DbQuery;
use SimpleComplex\Database\MariaDbQuery;
use SimpleComplex\Database\MariaDbResult;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit backend/vendor/simplecomplex/database/tests/src/MariaDb/ResultTest.php
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
        $this->assertInstanceOf(MariaDbClient::class, $client);
    }

    /**
     * @see ClientTest::testInstantiation()
     * @see QueryArgumentTest::testQueryArgumentsReferred()
     */
    public function testFetchColumn()
    {
        (new QueryArgumentTest())->testQueryArgumentsReferred();

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
        TestHelper::queryPrepare($query);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $column_by_index = $result->fetchColumn(4);
        $this->assertInternalType('string', $column_by_index);
        $this->assertNotEmpty($column_by_index);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $column_by_name = $result->fetchColumn(0, '_3_varchar');
        $this->assertInternalType('string', $column_by_name);
        $this->assertNotEmpty($column_by_name);

        TestHelper::logVariable('', [ $column_by_index, $column_by_name]);

        $query = $client->query(
            'SELECT * FROM typish
            LIMIT 1 OFFSET 1',
            [
                'name' => __FUNCTION__,
                'validate_params' => static::VALIDATE_PARAMS,
                'sql_minify' => true,
            ]
        );
        TestHelper::queryPrepare($query);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $column_by_index = $result->fetchColumn(4);
        $this->assertInternalType('string', $column_by_index);
        $this->assertNotEmpty($column_by_index);

        $result = TestHelper::queryExecute($query);
        $this->assertInstanceOf(MariaDbResult::class, $result);
        $column_by_name = $result->fetchColumn(0, '_3_varchar');
        $this->assertInternalType('string', $column_by_name);
        $this->assertNotEmpty($column_by_name);

        TestHelper::logVariable('', [ $column_by_index, $column_by_name]);
    }

}

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

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\MariaDbClient;
use SimpleComplex\Database\MariaDbQuery;
use SimpleComplex\Database\MariaDbResult;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit backend/vendor/simplecomplex/database/tests/src/MariaDb/ResetTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class ResetTest extends TestCase
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
        $query = $client->query(
            'TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent',
            [
                //'detect_multi' => true,
            ]
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

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
        $query = $client->query(
            'SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent',
            [
                //'detect_multi' => true,
            ]
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);
        // Do traverse all result sets; an erroring query in a MariaDB/MySQL
        // multi-query might not materialize until traversed.
        TestHelper::logOnError('traverse all result sets', $result, 'depleteSets');
    }

    /**
     * Inserts getting sql from file.
     *
     * @see ClientTest::testInstantiation
     *
     * @return DbClientInterface|MariaDbClient
     */
    public function testResetStructure()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        // Get .sql file containing inserts.
        $file_path = TestHelper::fileFind('MariaDb/sql/test_scx_mariadb.structure.sql', 'tests');
        $this->assertInternalType('string', $file_path);
        $this->assertNotEmpty($file_path);

        $sql = file_get_contents($file_path);
        $this->assertInternalType('string', $sql);
        $this->assertNotEmpty($sql);

        /** @var MariaDbQuery $query */
        $query = $client->query(
            $sql,
            [
                //'detect_multi' => true,
            ]
        );
        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);
        // Do traverse all result sets; an erroring query in a MariaDB/MySQL
        // multi-query might not materialize until traversed.
        TestHelper::logOnError('traverse all result sets', $result, 'depleteSets');

        // Get .sql file containing inserts.
        $file_path = TestHelper::fileFind('MariaDb/sql/routines/test_scx_mariadb.drop.sql', 'tests');
        $sql = file_get_contents($file_path);
        /** @var MariaDbQuery $query */
        $query = $client->query($sql, [
                //'detect_multi' => false,
                'sql_minify' => false,
            ]);
        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);
        // Do traverse all result sets; an erroring query in a MariaDB/MySQL
        // multi-query might not materialize until traversed.
        TestHelper::logOnError('traverse all result sets', $result, 'depleteSets');

        $file_path = TestHelper::fileFind('MariaDb/sql/routines/test_scx_mariadb.typish-insert.sql', 'tests');
        $sql = file_get_contents($file_path);
        /** @var MariaDbQuery $query */
        $query = $client->query($sql, [
            'detect_multi' => false,
            'sql_minify' => false,
        ]);
        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        $file_path = TestHelper::fileFind('MariaDb/sql/routines/test_scx_mariadb.typish-insert-select.sql', 'tests');
        $sql = file_get_contents($file_path);
        /** @var MariaDbQuery $query */
        $query = $client->query($sql, [
            'detect_multi' => false,
            'sql_minify' => false,
        ]);
        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        $file_path = TestHelper::fileFind('MariaDb/sql/routines/test_scx_mariadb.typish-insert-select-select.sql', 'tests');
        $sql = file_get_contents($file_path);
        /** @var MariaDbQuery $query */
        $query = $client->query($sql, [
            'detect_multi' => false,
            'sql_minify' => false,
        ]);
        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        return $client;
    }

    /**
     * Inserts getting sql from file.
     *
     * @see ClientTest::testInstantiation
     *
     * @return DbClientInterface|MariaDbClient
     */
    public function testResetPopulate()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        // Get .sql file containing inserts.
        $file_path = TestHelper::fileFind('MariaDb/sql/test_scx_mariadb.data.sql', 'tests');
        $this->assertInternalType('string', $file_path);
        $this->assertNotEmpty($file_path);

        $sql = file_get_contents($file_path);
        $this->assertInternalType('string', $sql);
        $this->assertNotEmpty($sql);

        /** @var MariaDbQuery $query */
        $query = $client->query(
            $sql,
            [
                //'detect_multi' => true,
            ]
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);
        // Do traverse all result sets; an erroring query in a MariaDB/MySQL
        // multi-query might not materialize until traversed.
        TestHelper::logOnError('traverse all result sets', $result, 'depleteSets');

        return $client;
    }

}

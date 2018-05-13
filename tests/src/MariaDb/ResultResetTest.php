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
use SimpleComplex\Tests\Database\TestHelper;

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
        $query = $client->query(
            'TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent',
            [
                'detect_multi' => true,
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
                'detect_multi' => true,
            ]
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        $i = -1;
        while (($success = $result->nextSet())) {
            $this->assertSame(
                true,
                $success
            );
        }
    }

    /**
     * Inserts getting sql from file.
     *
     * @see ClientTest::testInstantiation
     */
    public function testResetPopulate()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        // Get .sql file containing inserts.
        $document_root = \SimpleComplex\Utils\CliEnvironment::getInstance()->documentRoot;
        $this->assertInternalType('string', $document_root);
        $this->assertNotEmpty($document_root);
        $file_path = '/vendor/simplecomplex/database/tests/src/MariaDb/sql/test_scx_mariadb.data.sql';
        $file_exists = file_exists($document_root . $file_path);
        if ($file_exists) {
            $file_path = $document_root . $file_path;
        } else {
            $file_exists = file_exists($document_root . '/backend' . $file_path);
            if ($file_exists) {
                $file_path = $document_root . '/backend' . $file_path;
            }
        }

        $document_root = TestHelper::documentRoot();
        $file_path = TestHelper::PATH_TESTS_SRC . '/MariaDb/sql/test_scx_mariadb.data.sql';
        $file_exists = TestHelper::fileExists($file_path);
        if ($file_exists) {
            $file_path = $document_root . $file_path;
        } else {
            $file_exists = file_exists($document_root . '/backend' . $file_path);
            if ($file_exists) {
                $file_path = $document_root . '/backend' . $file_path;
            }
        }



        if (!$file_exists) {
            TestHelper::logVariable('Failed finding file test_scx_mariadb.data.sql, tried:', [
                $document_root . $file_path,
                $document_root . '/backend' . $file_path
            ]);
        }
        $this->assertTrue($file_exists);

        $sql = file_get_contents($file_path);
        $this->assertInternalType('string', $sql);
        $this->assertNotEmpty($sql);

        /** @var MariaDbQuery $query */
        $query = $client->query(
            $sql,
            [
                'detect_multi' => true,
            ]
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        while (($success = $result->nextSet())) {
            $this->assertSame(
                true,
                $success
            );
        }
    }

}

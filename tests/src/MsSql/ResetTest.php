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

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\MsSqlClient;
use SimpleComplex\Database\MsSqlQuery;
use SimpleComplex\Database\MsSqlResult;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit backend/vendor/simplecomplex/database/tests/src/MsSql/ResetTest.php
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
        /** @var MsSqlClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @var MsSqlQuery $query */
        $query = $client->query(
            'TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent'
        );

        /** @var MsSqlResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MsSqlResult::class, $result);

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
        /** @var MsSqlClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @var MsSqlQuery $query */
        $query = $client->query('
; ALTER TABLE child DROP CONSTRAINT fk_child_parent_id_a
; ALTER TABLE child DROP CONSTRAINT fk_child_parent_id_b
; ALTER TABLE relationship DROP CONSTRAINT fk_relationship_spouse_a
; ALTER TABLE relationship DROP CONSTRAINT fk_relationship_spouse_b

; TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent;

; ALTER TABLE child ADD
CONSTRAINT fk_child_parent_id_a
FOREIGN KEY(parentA)
REFERENCES parent(id)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION

; ALTER TABLE child ADD
CONSTRAINT fk_child_parent_id_b
FOREIGN KEY(parentB)
REFERENCES parent(id)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION

; ALTER TABLE relationship ADD
CONSTRAINT fk_relationship_spouse_a
FOREIGN KEY(spouseA)
REFERENCES parent(id)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION

; ALTER TABLE relationship ADD
CONSTRAINT fk_relationship_spouse_b
FOREIGN KEY(spouseB)
REFERENCES parent(id)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION
'
        );

        /** @var MsSqlResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MsSqlResult::class, $result);
        // Traversing all result sets is obsolete when using MsSql, but anyway.
        TestHelper::logOnError('traverse all result sets', $result, 'depleteSets');
    }

    /**
     * Drops and recreates tables via sql from file.
     *
     * @see ClientTest::testInstantiation
     *
     * @return DbClientInterface|MsSqlClient
     */
    public function testResetStructure()
    {
        /** @var MsSqlClient $client */
        $client = (new ClientTest())->testInstantiation();

        // Get .sql file containing inserts.
        $file_path = TestHelper::fileFind('MsSql/sql/test_scx_mssql.structure.sql', 'tests');
        static::assertInternalType('string', $file_path);
        static::assertNotEmpty($file_path);

        $sql = file_get_contents($file_path);
        static::assertInternalType('string', $sql);
        static::assertNotEmpty($sql);

        /** @var MsSqlQuery $query */
        $query = $client->query(
            $sql,
            [
            ]
        );

        /** @var MsSqlResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MsSqlResult::class, $result);
        // Traversing all result sets is obsolete when using MsSql, but anyway.
        TestHelper::logOnError('traverse all result sets', $result, 'depleteSets');

        return $client;
    }

    /**
     * Inserts getting sql from file.
     *
     * @see ClientTest::testInstantiation
     *
     * @return DbClientInterface|MsSqlClient
     */
    public function testResetPopulate()
    {
        /** @var MsSqlClient $client */
        $client = (new ClientTest())->testInstantiation();

        // Get .sql file containing inserts.
        $file_path = TestHelper::fileFind('MsSql/sql/test_scx_mssql.data.sql', 'tests');
        static::assertInternalType('string', $file_path);
        static::assertNotEmpty($file_path);

        $sql = file_get_contents($file_path);
        static::assertInternalType('string', $sql);
        static::assertNotEmpty($sql);

        /** @var MsSqlQuery $query */
        $query = $client->query(
            $sql,
            [
            ]
        );

        /** @var MsSqlResult $result */
        $result = $query->execute();
        static::assertInstanceOf(MsSqlResult::class, $result);
        // Traversing all result sets is obsolete when using MsSql, but anyway.
        TestHelper::logOnError('traverse all result sets', $result, 'depleteSets');

        return $client;
    }
}

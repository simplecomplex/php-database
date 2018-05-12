<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database\MsSql;

use PHPUnit\Framework\TestCase;

use SimpleComplex\Database\MsSqlClient;
use SimpleComplex\Database\MsSqlQuery;
use SimpleComplex\Database\MsSqlResult;

/**
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MsSql/ResetResultTest.php
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
        /** @var MsSqlClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @var MsSqlQuery $query */
        $query = $client->query(
            'TRUNCATE TABLE child; TRUNCATE TABLE relationship; TRUNCATE TABLE parent'
        );

        /** @var MsSqlResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MsSqlResult::class, $result);

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
        $this->assertInstanceOf(MsSqlResult::class, $result);

        $i = -1;
        while (($success = $result->nextSet())) {
            $this->assertSame(
                true,
                $success,
                'Result set[' . (++$i) . '] was type[' . gettype($success) . '] ~bool[' . !!$success . '].'
            );
        }
    }

}

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
use SimpleComplex\Tests\Database\TestHelper;

use SimpleComplex\Database\MariaDbClient;
use SimpleComplex\Database\MariaDbQuery;
use SimpleComplex\Database\MariaDbResult;

/**
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MariaDb/PopulateTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class PopulateTest extends TestCase
{
    /**
     * Throw DbQueryException: can't truncate due to foreign key constraint.
     *
     * @see ClientTest::testInstantiation
     */
    public function testInsert()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @noinspection SqlResolve */
        /** @var MariaDbQuery $query_insert */
        $query_insert = $client->query(
            'INSERT INTO parent (lastName, firstName, birthday) VALUES (?, ?, ?)'
        );

        /** @noinspection SqlResolve */
        /** @var MariaDbQuery $query_select */
        $query_select = $client->query(
            'SELECT * FROM parent WHERE id = ?'
        );

        $args_insert = [
            'lastName' => 'Cognomen',
            'firstName' => 'Praenomena',
            'birthday' => '1970-01-01',
        ];
        $query_insert->prepare('sss', $args_insert);
        /** @var MariaDbResult $result_insert */
        $result_insert = $query_insert->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result_insert);
        TestHelper::logVariable('set type', $result_insert);
        $this->assertSame(1, $result_insert->affectedRows());
        $insert_id = $result_insert->insertId('i');
        TestHelper::logVariable('insert ID', $insert_id);
        $this->assertInternalType('integer', $insert_id);

        $args_select = [
            'id' => $insert_id,
        ];
        $query_select->prepare('i', $args_select);
        /** @var MariaDbResult $result_select */
        $result_select = $query_select->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result_select);
        $row_select = $result_select->fetchArray();
        TestHelper::logVariable('row select', $row_select);
        $this->assertInternalType('array', $row_select);
        $result_select->free();

        $args_insert['firstName'] = 'Praenomeno';
        $args_insert['birthday'] = '1970-01-02';
        $result_insert = $query_insert->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result_insert);
        $this->assertSame(1, $result_insert->affectedRows());
        $insert_id = $result_insert->insertId('i');
        $this->assertInternalType('integer', $insert_id);

        $query_select->close();
        /** @noinspection SqlResolve */
        /** @var MariaDbQuery $query_select */
        $query_select = $client->query(
            'SELECT * FROM parent',
            [
                'result_mode' => MariaDbQuery::CURSOR_STORE,
            ]
        );
        /** @var MariaDbResult $result_select */
        $args = [];
        $result_select = $query_select->execute();
        $num_rows = $result_select->numRows();
        TestHelper::logVariable('num_rows', $num_rows);
        $all_rows = $result_select->fetchAll();
        TestHelper::logVariable('count rows', count($all_rows));
    }

}

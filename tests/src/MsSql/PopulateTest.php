<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Tests\MsSql;

use PHPUnit\Framework\TestCase;

use SimpleComplex\Database\MsSqlClient;
use SimpleComplex\Database\MsSqlQuery;
use SimpleComplex\Database\MsSqlResult;

use SimpleComplex\Database\Tests\TestHelper;

/**
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MsSql/PopulateTest.php
 * @endcode
 *
 * @package SimpleComplex\Database\Tests
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
        /** @var MsSqlClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @noinspection SqlResolve */
        /** @var MsSqlQuery $query_insert */
        $query_insert = $client->query(
            'INSERT INTO parent (lastName, firstName, birthday) VALUES (?, ?, ?)',
            [
                'insert_id' => true,
            ]
        );

        /** @noinspection SqlResolve */
        /** @var MsSqlQuery $query_select */
        $query_select = $client->query(
            'SELECT * FROM parent WHERE id = ?'
        );

        $args_insert = [
            'lastName' => 'Cognomen',
            'firstName' => 'Praenomena',
            'birthday' => '1970-01-01',
        ];
        $query_insert->prepare('sss', $args_insert);
        /** @var MsSqlResult $result_insert */
        $result_insert = $query_insert->execute();
        $this->assertInstanceOf(MsSqlResult::class, $result_insert);
        TestHelper::logVariable('set type', $result_insert);
        $this->assertSame(1, $result_insert->affectedRows());
        $insert_id = $result_insert->insertId('i');
        TestHelper::logVariable('insert ID', $insert_id);
        $this->assertInternalType('integer', $insert_id);

        $args_select = [
            'id' => $insert_id,
        ];
        $query_select->prepare('i', $args_select);
        /** @var MsSqlResult $result_select */
        $result_select = $query_select->execute();
        $this->assertInstanceOf(MsSqlResult::class, $result_select);
        $row_select = $result_select->fetchArray();
        TestHelper::logVariable('row select', $row_select);
        $this->assertInternalType('array', $row_select);
        $result_select->free();

        $args_insert['firstName'] = 'Praenomeno';
        $args_insert['birthday'] = '1970-01-02';
        $result_insert = $query_insert->execute();
        $this->assertInstanceOf(MsSqlResult::class, $result_insert);
        $this->assertSame(1, $result_insert->affectedRows());
        $insert_id = $result_insert->insertId('i');
        $this->assertInternalType('integer', $insert_id);

        $query_select->close();
        /** @noinspection SqlResolve */
        /** @var MsSqlQuery $query_select */
        $query_select = $client->query(
            'SELECT * FROM parent',
            [
                'num_rows' => true,
            ]
        );
        /** @var MsSqlResult $result_select */
        $args = [];
        $result_select = $query_select->execute();
        $num_rows = $result_select->numRows();
        TestHelper::logVariable('num_rows', $num_rows);
        $all_rows = $result_select->fetchAll();
        TestHelper::logVariable('count rows', count($all_rows));
    }

}

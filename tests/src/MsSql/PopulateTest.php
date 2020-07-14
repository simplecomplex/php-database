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

use SimpleComplex\Database\MsSqlClient;
use SimpleComplex\Database\MsSqlQuery;
use SimpleComplex\Database\MsSqlResult;

/**
 * @code
 * // CLI, in document root:
 * backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MsSql/PopulateTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class PopulateTest extends TestCase
{
    /**
     * Throw DbQueryException: can't truncate due to foreign key constraint.
     *
     * @see ResetTest::testResetStructure()
     * @see ClientTest::testInstantiation
     */
    public function testInsert()
    {
        (new ResetTest())->testResetStructure();

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
        //\SimpleComplex\Inspect\Inspect::getInstance()->variable($query_insert)->log();
        $args_insert = [
            'lastName' => 'Cognomen',
            'firstName' => 'Praenomena',
            'birthday' => '1970-01-01',
        ];
        $query_insert->prepare('sss', $args_insert);
        /** @var MsSqlResult $result_insert */
        $result_insert = $query_insert->execute();
        static::assertInstanceOf(MsSqlResult::class, $result_insert);
        static::assertSame(1, $result_insert->affectedRows());
        $insert_id = $result_insert->insertId('i');
        static::assertIsInt($insert_id);

        /** @noinspection SqlResolve */
        /** @var MsSqlQuery $query_select */
        $query_select = $client->query(
            'SELECT * FROM parent WHERE id = ?'
        );
        $args_select = [
            'id' => $insert_id,
        ];
        $query_select->prepare('i', $args_select);
        /** @var MsSqlResult $result_select */
        $result_select = $query_select->execute();
        static::assertInstanceOf(MsSqlResult::class, $result_select);
        $row_select = $result_select->fetchArray();
        static::assertIsArray($row_select);
        $result_select->free();

        $args_insert['firstName'] = 'Praenomeno';
        $args_insert['birthday'] = '1970-01-02';
        $result_insert = $query_insert->execute();
        static::assertInstanceOf(MsSqlResult::class, $result_insert);
        static::assertSame(1, $result_insert->affectedRows());
        $insert_id = $result_insert->insertId('i');
        static::assertIsInt($insert_id);

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
        $all_rows = $result_select->fetchArrayAll();
    }

}

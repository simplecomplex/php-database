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

use SimpleComplex\Database\MariaDbClient;
use SimpleComplex\Database\MariaDbQuery;
use SimpleComplex\Database\DbResult;
use SimpleComplex\Database\MariaDbResult;

/**
 * @code
 * // CLI, in document root:
 * vendor/bin/phpunit vendor/simplecomplex/database/tests/src/MariaDb/ResetResultTest.php
 * @endcode
 *
 * @package SimpleComplex\Tests\Database
 */
class RubbishTest extends TestCase
{
    public function testRubbish()
    {
        /** @var MariaDbClient $client */
        $client = (new ClientTest())->testInstantiation();

        /** @var MariaDbQuery $query */
        $query = $client->query(
            'TRUNCATE TABLE rubbish; TRUNCATE TABLE trash'
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

        $num_rows = count($result->fetchAllArrays(DbResult::FETCH_NUMERIC));

        $num_rows = 0;
        while (($result->nextRow())) {
            ++$num_rows;
        }

        while (($row = $result->fetchArray())) {
            if (!$num_rows) {
                // Fetch expensive resources required to process rows.
            }
            ++$num_rows;
            // Process row.
        }
        if (!$num_rows) {
            // Workaround.
        }




        $i = -1;
        /*while(($success = $result->nextSet()) !== null) {
            $this->assertSame(
                true,
                $success,
                'Result set[' . (++$i) . '] was type[' . gettype($success) . '] ~bool[' . !!$success . '].'
            );
        }*/
    }
}

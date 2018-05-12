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
        $query = $client->multiQuery(
            'TRUNCATE TABLE rubbish; TRUNCATE TABLE trash'
        );

        /** @var MariaDbResult $result */
        $result = $query->execute();
        $this->assertInstanceOf(MariaDbResult::class, $result);

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

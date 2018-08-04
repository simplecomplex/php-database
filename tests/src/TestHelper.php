<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database;

use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbResultInterface;
use SimpleComplex\Database\DbQuery;

/**
 * phpunit test helper.
 *
 * @package SimpleComplex\Tests\Database
 */
class TestHelper extends \SimpleComplex\Tests\Utils\TestHelper
{
    /**
     * Expected path to tests' src dir, relative to the vendor dir.
     *
     * @var string
     */
    const PATH_TESTS = 'simplecomplex/database/tests/src';

    /**
     * @param DbQueryInterface|DbQuery $query
     *
     * @return DbResultInterface|null
     */
    public static function queryExecute(DbQuery $query)
    {
        try {
            return $query->execute();
        } catch (\Throwable $xcptn) {
            static::logTrace('query execute', $xcptn);
        }
    }
}

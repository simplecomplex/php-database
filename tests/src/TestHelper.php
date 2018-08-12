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
use SimpleComplex\Database\DbQuery;
use SimpleComplex\Database\DbResult;

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
     * Query prepare(), log on error.
     *
     * @see \SimpleComplex\Tests\Utils\TestHelper::logOnError()
     *
     * @param DbQueryInterface|DbQuery $query
     * @param string $types
     * @param array $arguments
     *
     * @throws \Throwable
     *      Re-throws query execution failure.
     */
    public static function queryPrepareLogOnError(DbQuery $query, string $types = '', array &$arguments = [])
    {
        try {
            $query->prepare($types, $arguments);
        } catch (\Throwable $xcptn) {
            static::logTrace('query prepare', $xcptn);
            throw $xcptn;
        }
    }

    /**
     * Query parameters(), log on error.
     *
     * @see \SimpleComplex\Tests\Utils\TestHelper::logOnError()
     *
     * @param DbQueryInterface|DbQuery $query
     * @param string $types
     * @param array $arguments
     *
     * @throws \Throwable
     *      Re-throws query execution failure.
     */
    public static function queryParametersLogOnError(DbQuery $query, string $types = '', array &$arguments = [])
    {
        try {
            $query->parameters($types, $arguments);
        } catch (\Throwable $xcptn) {
            static::logTrace('query parameters', $xcptn);
            throw $xcptn;
        }
    }
}

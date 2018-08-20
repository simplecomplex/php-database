<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Tests\Database;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;
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
     * Client query(), log on error.
     *
     * @see \SimpleComplex\Tests\Utils\TestHelper::logOnError()
     *
     * @param DbClientInterface $client
     * @param string $sql
     * @param array $options
     *
     * @return DbQueryInterface
     *
     * @throws \Throwable
     *      Re-throws query execution failure.
     */
    public static function clientQueryLogOnError(
        DbClientInterface $client, string $sql, array $options = []
    ) : DbQueryInterface {
        try {
            return $client->query($sql, $options);
        } catch (\Throwable $xcptn) {
            static::logTrace('client query', $xcptn);
            throw $xcptn;
        }
    }

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

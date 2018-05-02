<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbClientMultiInterface;
use SimpleComplex\Database\Interfaces\DbQueryMultiInterface;

/**
 * Database client supporting multi-query.
 *
 * For multi-query explanation, see:
 * @see DbClientMultiInterface
 *
 * @property-read string $type
 * @property-read string $name
 * @property-read string $host
 * @property-read int $port
 * @property-read string $database
 * @property-read string $user
 * @property-read array $options
 * @property-read array $optionsResolved
 * @property-read string $characterSet
 * @property-read bool $transactionStarted
 *
 * @package SimpleComplex\Database
 */
abstract class DatabaseClientMulti extends DatabaseClient implements DbClientMultiInterface
{
    /**
     * Create a multi-query.
     *
     * @see DatabaseClient::query()
     * @see MariaDbQuery::__construct()
     *
     * @param string $sql
     * @param array $options
     *
     * @return $this|DbQueryMultiInterface
     */
    public function multiQuery(string $sql, array $options = []) : DbQueryMultiInterface
    {
        // Pass is_multi_query option.
        $opts =& $options;
        $opts['is_multi_query'] = true;

        $class_query = static::CLASS_QUERY;
        /** @var DbQueryMultiInterface|MariaDbQuery */
        return new $class_query(
            $this,
            $sql,
            $options
        );
    }
}

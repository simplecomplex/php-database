<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

/**
 * Database client interface specifying (MariaDb-, PostgreSQL-like)
 * multi-query methods.
 *
 * Multi-query
 * -----------
 * A multi-query is an SQL string containing more queries
 * delimited by semicolon. Every query may be a SELECT (or likewise)
 * producing a result set.
 * NB: MySQL multi-queries aren't executed until getting result sets,
 * not when execute()/MySQLi::multi_query().
 *
 * Other database engines (like MS SQL) may in some cases support multiple
 * queries, but never more than SELECT (or likewise) query.
 * MS SQL example - it's 'get insert insert ID'-routine:
 * @code
 * INSERT INTO some_table (Whatever) VALUES (?); SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME
 * @endcode
 *
 * @package SimpleComplex\Database
 */
interface DbClientMultiInterface extends DbClientInterface
{
    /**
     * Create a multi-query.
     *
     * @see DbQueryMultiInterface
     *
     * @param string $sql
     * @param array $options
     *
     * @return DbQueryMultiInterface
     */
    public function multiQuery(string $sql, array $options = []) : DbQueryMultiInterface;
}

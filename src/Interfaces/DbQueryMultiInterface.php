<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

/**
 * Database query interface specifying (MariaDb-, PostgreSQL-like)
 * multi-query methods.
 *
 * For multi-query explanation, see:
 * @see DbClientMultiInterface
 *
 * @package SimpleComplex\Database
 */
interface DbQueryMultiInterface extends DbQueryInterface
{
    /**
     * Repeat base sql, and substitute it's parameter markers by arguments.
     *
     * Non-prepared statement only.
     *
     * Turns the full query into multi-query.
     *
     * Chainable.
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $types
     *      Empty: uses string for all.
     * @param array $arguments
     *      Values to substitute sql parameter markers with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryMultiInterface
     *      Throws throwable on error.
     */
    public function repeat(string $types, array $arguments) : DbQueryMultiInterface;

    /**
     * Append sql to previously defined sql.
     *
     * Non-prepared statement only.
     *
     * Turns the full query into multi-query.
     *
     * Chainable.
     *
     * @param string $sql
     * @param string $types
     *      Empty: uses string for all.
     * @param array $arguments
     *      Values to substitute sql parameter markers with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryMultiInterface
     *      Throws throwable on error.
     */
    public function append(string $sql, string $types, array $arguments) : DbQueryMultiInterface;
}

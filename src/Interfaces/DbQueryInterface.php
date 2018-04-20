<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database\Interfaces;

use SimpleComplex\Database\Interfaces\DbClientInterface;

/**
 * Database query interface.
 *
 * @package SimpleComplex\Database
 */
interface DbQueryInterface
{
    /**
     * @param \SimpleComplex\Database\Interfaces\DbClientInterface $client
     *      Reference to parent client.
     * @param string $query
     *
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     */
    public function __construct(DbClientInterface $client, string $query);

    /**
     * Pass parameters to simple (non-prepared statement) query.
     *
     * @param string $types
     *      i: integer.
     *      d: float (double).
     *      s: string.
     *      b: blob.
     * @param array $arguments
     *
     * @return $this|DbQueryInterface
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface;

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * @param string $types
     *      i: integer.
     *      d: float (double).
     *      s: string.
     *      b: blob.
     * @param array &$arguments
     *      By reference.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    public function prepareStatement(string $types, array &$arguments) : DbQueryInterface;

    /**
     * @return void
     */
    public function closePreparedStatement();
}

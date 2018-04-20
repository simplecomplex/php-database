<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Exception\DbLogicalException;
use SimpleComplex\Database\Exception\DbRuntimeException;

/**
 * MS SQL query.
 *
 * @package SimpleComplex\Database
 */
class MsSqlQuery extends AbstractDbQuery
{
    /**
     * Class name of \SimpleComplex\Database\MsSqlResult or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_RESULT = \Package\Library\CustomMsSqlResult::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MsSqlResult
     *
     * @var string
     */
    const CLASS_RESULT = MsSqlResult::class;

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var MsSqlClient
     */
    public $client;

    /**
     * @var resource
     */
    protected $preparedStatement;

    /**
     * @param MsSqlClient|DbClientInterface $client
     *      Reference to parent client.
     * @param string $query
     *
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     */
    public function __construct(DbClientInterface $client, string $query)
    {
        $this->client = $client;
        if (!$query) {
            throw new \InvalidArgumentException('Arg $query cannot be empty');
        }
        // Remove trailing semicolon.
        $this->query = rtrim($query, ';');
    }

    /**
     * Not supported by this type of database client.
     *
     * @param string $types
     * @param array $arguments
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      MS SQL (at least Sqlsrv extension) doesn't support multi-query.
     */
    public function multiQueryParameters(string $types, array $arguments) : DbQueryInterface
    {
        throw new DbLogicalException('Database type ' . $this->client->type . ' doesn\'t support multi-query.');
    }

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * @param string $types
     *      Ignored; Sqlsrv parameter binding too weird.
     * @param array $arguments
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws DbRuntimeException
     */
    public function prepare(string $types, array $arguments) : DbQueryInterface
    {
        $connection = $this->client->getConnection();

        /** @var resource $statement */
        $statement = sqlsrv_prepare($connection, $this->query, $arguments);
        if (!$statement) {
            throw new DbRuntimeException(
                'Database query failed to prepare statement and bind parameters, with error: '
                . $this->client->getNativeError() . '.'
            );
        }
        $this->preparedStatement = $statement;
        $this->isPreparedStatement = true;

        return $this;
    }
}

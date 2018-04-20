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
use SimpleComplex\Database\Exception\DbRuntimeException;

/**
 * Maria DB query.
 *
 * @package SimpleComplex\Database
 */
class MariaDbQuery extends AbstractDbQuery
{
    /**
     * Class name of \SimpleComplex\Database\MariaDbResult or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_RESULT = \Package\Library\CustomMariaDbResult::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MariaDbResult
     *
     * @var string
     */
    const CLASS_RESULT = MariaDbResult::class;

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var MariaDbClient
     */
    public $client;

    /**
     * @var \mysqli_stmt
     */
    protected $preparedStatement;

    /**
     * @param MariaDbClient|DbClientInterface $client
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
        // Remove trailing semicolon; for multi-query.
        $this->query = rtrim($query, ';');
    }

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * @param string $types
     *      i: integer.
     *      d: float (double).
     *      s: string.
     *      b: blob.
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
        $mysqli = $this->client->getConnection();

        /** @var \mysqli_stmt $mysqli_stmt */
        $mysqli_stmt = @$mysqli->prepare($this->query);
        if (!$mysqli_stmt) {
            throw new DbRuntimeException(
                'Database query failed to prepare statement, with error: ' . $this->client->getNativeError() . '.'
            );
        }
        if (!$mysqli_stmt->bind_param($types, ...$arguments)) {
            throw new DbRuntimeException(
                'Database query failed to bind parameters prepare statement, with error: '
                . $this->client->getNativeError() . '.'
            );
        }
        $this->preparedStatement = $mysqli_stmt;
        $this->isPreparedStatement = true;

        return $this;
    }

    /**
     * Parameter value escaper.
     *
     * Escapes %_ unless instance var hasLikeClause.
     *
     * Replaces semicolon with comma if multi-query.
     *
     * @param string $str
     *
     * @return string
     */
    public function escapeString(string $str) : string
    {
        $s = $str;
        if ($this->isMultiQuery) {
            $s = str_replace(';', ',', $s);
        }

        $s = $this->client->getConnection()->real_escape_string($s);

        return $this->hasLikeClause ? $s : addcslashes($s, '%_');
    }
}

<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbQueryInterface;

/**
 * Maria DB result.
 *
 * @package SimpleComplex\Database
 */
class MariaDbResult extends DatabaseResult
{
    /**
     * @var MariaDbQuery
     */
    protected $query;

    /**
     * @var \mysqli_stmt
     */
    protected $statement;

    /**
     * @var bool
     */
    protected $isMultiQuery;

    /**
     * @var bool
     */
    protected $isPreparedStatement;

    /**
     * @param DbQueryInterface|MariaDbQuery $query
     * @param \mysqli_stmt|null $statement
     *      \mysqli_stmt: If prepared statement.
     *
     */
    public function __construct(DbQueryInterface $query, $statement)
    {
        $this->query = $query;

        $this->statement = $statement;

        // Don't
        $this->isMultiQuery = $this->query->isMultiQuery;
        $this->isPreparedStatement = $this->query->isPreparedStatement;
    }

    public function nextRow()
    {
        // @todo
    }
}

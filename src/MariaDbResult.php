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
     * @var \mysqli_result
     */
    protected $result;

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

    protected function setResult()
    {
        if (!$this->result) {
            if ($this->isPreparedStatement) {
                $result = @$this->statement->get_result();
            }
            elseif ($this->isMultiQuery) {

            }

            $this->result = $this->isPreparedStatement ? $this->statement->get_result() :
        }
    }

    public function nextRow()
    {
        // @todo: there's no direct MySQLi equivalent, use fetch_row().


        $next = @sqlsrv_fetch($this->statement);
        if ($next) {
            ++$this->rowIndex;
            return $next;
        }
        if ($next === null) {
            null;
        }
        // Unset prepared statement arguments reference.
        $this->query->closeStatement();
        $this->logQuery(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed going to next row, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }
}

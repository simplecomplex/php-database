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

use SimpleComplex\Database\Exception\DbLogicalException;
use SimpleComplex\Database\Exception\DbResultException;

/**
 * Maria DB result.
 *
 * MySQLi's default stored procedure result handling is not used,
 * because binding result vars is useless; instead the result gets stored.
 * @see \mysqli_stmt::get_result()
 * When stored procedure, query class cursorMode is ignored.
 * @see MariaDbQuery::$cursorMode
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
     * Object representing the connection.
     *
     * @var \MySQLi
     */
    protected $mySqlI;

    /**
     * Only if prepared statement.
     *
     * @var \mysqli_stmt|null
     */
    protected $statement;

    /**
     * @var \mysqli_result|null
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
     * @param \MySQLi $mySqlI
     * @param \mysqli_stmt|null $statement
     *      \mysqli_stmt: If prepared statement.
     *
     */
    public function __construct(DbQueryInterface $query, $mySqlI, $statement)
    {
        $this->query = $query;
        $this->mySqlI = $mySqlI;
        if ($statement) {
            $this->statement = $statement;
        }

        // Don't
        $this->isMultiQuery = $this->query->isMultiQuery;
        $this->isPreparedStatement = $this->query->isPreparedStatement;
    }

    /**
     * Number of rows affected by a CRUD statement.
     *
     * @return int
     *
     * @throws DbLogicalException
     *      The query failed.
     * @throws DbResultException
     */
    public function affectedRows() : int
    {
        if ($this->isPreparedStatement) {
            $count = @$this->statement->affected_rows;
        } else {
            $count = @$this->mySqlI->affected_rows;
        }
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->free();
        $this->query->log(__FUNCTION__);
        if ($count === -1) {
            throw new DbLogicalException(
                $this->query->errorMessagePrefix()
                . ' - rejected counting affected rows (returned -1), query failed.'
            );
        }
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed counting affected rows, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * Auto ID set by last insert or update statement.
     *
     * @param string|null $getAsType
     *      String: i|d|s|b.
     *      Null: Use driver default; probably string.
     *
     * @return mixed|null
     *      Null: The query didn't trigger setting an ID.
     *
     * @throws \InvalidArgumentException
     *      Invalid arg $getAsType value.
     * @throws \TypeError
     *      Arg $getAsType not string|null.
     * @throws DbResultException
     */
    public function insertId($getAsType = null)
    {
        if ($this->isPreparedStatement) {
            $id = @$this->statement->insert_id;
        } else {
            $id = @$this->mySqlI->insert_id;
        }
        if ($id) {
            if ($getAsType) {
                if (is_string($getAsType)) {
                    switch ($getAsType) {
                        case 'i':
                            return (int) $id;
                        case 'd':
                            return (float) $id;
                        case 's':
                        case 'b':
                            return '' . $id;
                        default:
                            // Unset prepared statement arguments reference.
                            $this->query->close();
                            $this->free();
                            $this->query->log(__FUNCTION__);
                            throw new \InvalidArgumentException(
                                $this->query->errorMessagePrefix()
                                . ' - arg $getAsType as string isn\'t i|d|s|b.'
                            );
                    }
                }
                else {
                    // Unset prepared statement arguments reference.
                    $this->query->close();
                    $this->free();
                    $this->query->log(__FUNCTION__);
                    throw new \TypeError(
                        $this->query->errorMessagePrefix()
                        . ' - arg $getAsType type[' . gettype($getAsType) . '] isn\'t string|null.'
                    );
                }
            }
            return $id;
        }
        elseif ($id === 0) {
            // Query didn't trigger setting an ID.
            return null;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->free();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting insert ID, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * Number of rows in a result set.
     *
     * NB: Query class cursor mode must be 'store'.
     * @see MsSqlQuery::__constructor()
     *
     * @return int
     *
     * @throws DbLogicalException
     *      Statement cursor mode not 'store'.
     * @throws DbResultException
     *      Propagated; failure to get/store/use result set.
     * @throws DbResultException
     */
    public function numRows() : int
    {
        if ($this->query->cursorMode != 'use') {
            throw new DbLogicalException(
                $this->query->client->errorMessagePrefix() . ' - cursor mode[' . $this->query->cursorMode
                . '] forbids getting number of rows.'
            );
        }
        if (!$this->result) {
            $this->loadResult();
        }
        $count = @$this->result->num_rows;
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->free();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting number of rows, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * Number of columns in a result row.
     *
     * @return int
     *
     * @throws DbResultException
     */
    public function numColumns() : int
    {
        if ($this->isPreparedStatement) {
            $count = @$this->statement->field_count;
        } else {
            $count = @$this->mySqlI->field_count;
        }
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->free();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting number of columns, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * Associative (column-keyed) or numerically indexed array.
     *
     * @param int $as
     *      Default: column-keyed.
     *
     * @return array|null
     *      Null: No more rows.
     *
     * @throws DbResultException
     *      Propagated; failure to get/store/use result set.
     * @throws DbResultException
     */
    public function fetchArray(int $as = Database::FETCH_ASSOC)
    {
        if (!$this->result) {
            $this->loadResult();
        }
        $row = $as == Database::FETCH_ASSOC ? @$this->result->fetch_assoc() : @$this->result->fetch_array(MYSQLI_NUM);
        ++$this->rowIndex;
        if ($row || $row === null) {
            return $row;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->free();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed fetching row as '
            . ($as == Database::FETCH_ASSOC ? 'assoc' : 'numeric') . ' array, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * Column-keyed object.
     *
     * @param string $class
     *      Optional class name.
     * @param array $args
     *      Optional constructor args.
     *
     * @return object|null
     *      Null: No more rows.
     *
     * @throws DbResultException
     *      Propagated; failure to get/store/use result set.
     * @throws DbResultException
     */
    public function fetchObject(string $class = '', array $args = [])
    {
        if (!$this->result) {
            $this->loadResult();
        }
        $row = @$this->result->fetch_object($class, $args);
        ++$this->rowIndex;
        if ($row || $row === null) {
            return $row;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->free();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed fetching row as object, with error: ' . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * Fetch all rows into a list.
     *
     * Option 'list_by_column' is not supported when fetching as numerically
     * indexed arrays.
     *
     * @param int $as
     *      Default: column-keyed.
     * @param array $options {
     *      @var string $list_by_column  Key list by that column's values.
     *      @var string $class  Object class name.
     *      @var array $args  Object constructor args.
     * }
     *
     * @return array
     *
     * @throws DbResultException
     *      Propagated; failure to get/store/use result set.
     * @throws DbLogicalException
     *      Providing 'list_by_column' option when fetching as numeric array.
     * @throws \InvalidArgumentException
     *      Providing 'list_by_column' option and no such column in result row.
     * @throws DbResultException
     */
    public function fetchAll(int $as = Database::FETCH_ASSOC, array $options = []) : array
    {
        if (!$this->result) {
            $this->loadResult();
        }
        $column_keyed = !empty($options['list_by_column']);
        switch ($as) {
            case Database::FETCH_NUMERIC:
                if ($column_keyed) {
                    // Unset prepared statement arguments reference.
                    $this->query->close();
                    $this->free();
                    $this->query->log(__FUNCTION__);
                    throw new DbLogicalException(
                        $this->query->client->errorMessagePrefix()
                        . ' - arg $options \'list_by_column\' is not supported when fetching as numeric arrays.'
                    );
                }
                $list = @$this->result->fetch_all(MYSQLI_NUM);
                if (!is_array($list)) {
                    // Unset prepared statement arguments reference.
                    $this->query->close();
                    $this->query->log(__FUNCTION__);
                    throw new DbResultException(
                        $this->query->errorMessagePrefix()
                        . ' - failed fetching all rows as numeric array, with error: '
                        . $this->query->client->nativeError() . '.'
                    );
                }
                return $list;
            case Database::FETCH_OBJECT:
                $key_column = !$column_keyed ? null : $options['list_by_column'];
                $list = [];
                $first = true;
                while (
                    ($row = @$this->result->fetch_object($options['class'] ?? '', $options['args'] ?? []))
                ) {
                    if (!$column_keyed) {
                        $list[] = $row;
                    }
                    else {
                        if ($first) {
                            $first = false;
                            if (!property_exists($row, $key_column)) {
                                // Unset prepared statement arguments reference.
                                $this->query->close();
                                $this->free();
                                $this->query->log(__FUNCTION__);
                                throw new \InvalidArgumentException(
                                    $this->query->errorMessagePrefix()
                                    . ' - failed fetching all rows as objects keyed by column[' . $key_column
                                    . '], non-existent column.'
                                );
                            }
                        }
                        $list[$row->{$key_column}] = $row;
                    }
                }
                break;
            default:
                if (!$column_keyed) {
                    $list = @$this->result->fetch_all(MYSQLI_ASSOC);
                    if (!is_array($list)) {
                        // Unset prepared statement arguments reference.
                        $this->query->close();
                        $this->free();
                        $this->query->log(__FUNCTION__);
                        throw new DbResultException(
                            $this->query->errorMessagePrefix()
                            . ' - failed fetching all rows as assoc array, with error: '
                            . $this->query->client->nativeError() . '.'
                        );
                    }
                    return $list;
                }
                $key_column = !$column_keyed ? null : $options['list_by_column'];
                $list = [];
                $first = true;
                while (($row = @$this->result->fetch_assoc())) {
                    if ($first) {
                        $first = false;
                        if (!array_key_exists($key_column, $row)) {
                            // Unset prepared statement arguments reference.
                            $this->query->close();
                            $this->free();
                            $this->query->log(__FUNCTION__);
                            throw new \InvalidArgumentException(
                                $this->query->errorMessagePrefix()
                                . ' - failed fetching all rows as assoc arrays keyed by column[' . $key_column
                                . '], non-existent column.'
                            );
                        }
                    }
                    $list[$row[$key_column]] = $row;
                }
        }
        // Last fetched row must be null; no more rows.
        if ($row !== null) {
            // Unset prepared statement arguments reference.
            $this->query->close();
            $this->free();
            $this->query->log(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix()
                . ' - failed fetching all rows as ' . ($as == Database::FETCH_OBJECT ? 'object' : 'assoc array')
                . ', with error: ' . $this->query->client->nativeError() . '.'
            );
        }
        return $list;
    }

    /**
     * Move cursor to next result set.
     *
     * @return bool|null
     *      Null: No next result set.
     *      Throws throwable on failure.
     */
    public function nextSet()
    {
        if ($this->isPreparedStatement) {
            $next = @$this->statement->next_result();
        } else {
            $next = @$this->mySqlI->next_result();
        }
        if ($next) {
            $this->result = null;
            ++$this->setIndex;
            $this->rowIndex = -1;
            return $next;
        }
        // @todo: neither next_result() seem to return null.
        if ($next === null) {
            return null;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->free();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed going to next set, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * Go to next row in the result set.
     *
     * @return bool|null
     *      Null: No next row.
     *      Throws throwable on failure.
     */
    public function nextRow()
    {
        if (!$this->result) {
            $this->loadResult();
        }
        // There's no MySQLi direct equivalent; use lightest alternative.
        $row = $this->result->fetch_array(MYSQL_NUM);
        if ($row || is_array($row)) {
            ++$this->rowIndex;
            return true;
        }
        if ($row === null) {
            null;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->free();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed going to next row, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * @return void
     */
    public function free() /*:void*/
    {
        if ($this->result) {
            @$this->result->free();
        }
    }

    // Helpers.-----------------------------------------------------------------

    /**
     * @return void
     *
     * @throws DbResultException
     */
    protected function loadResult() /*:void*/
    {
        if (!$this->result) {
            if ($this->isPreparedStatement) {
                // Apparantly equivalent of \MySQLi::store_result().
                $result = @$this->statement->get_result();
            }
            else {
                if ($this->query->cursorMode == 'store') {
                    $result = @$this->mySqlI->store_result();
                } else {
                    $result = @$this->mySqlI->use_result();
                }
            }
            if (!$result) {
                // Unset prepared statement arguments reference.
                $this->query->close();
                $this->query->log(__FUNCTION__);
                throw new DbResultException(
                    $this->query->errorMessagePrefix() . ' - failed getting result, with error: '
                    . $this->query->client->nativeError() . '.'
                );
            }
            $this->result = $result;
        }
    }
}

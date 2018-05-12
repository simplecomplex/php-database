<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbQueryInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbResultException;

/**
 * MS SQL result.
 *
 * Both setIndex and rowIndex deliberately go out-of-bounds when first/next
 * set/row is called for and there aren't any more sets/rows.
 *
 * Properties inherited from DatabaseResult:
 * @property-read int $setIndex
 * @property-read int $rowIndex
 *
 * @package SimpleComplex\Database
 */
class MsSqlResult extends DatabaseResult
{
    /**
     * @var MsSqlQuery
     */
    protected $query;

    /**
     * @var resource
     */
    protected $statement;

    /**
     * @see MsSqlQuery::execute()
     *
     * @param DbQueryInterface|MsSqlQuery $query
     * @param null $connection
     *      Ignored.
     * @param resource $statement
     *
     * @throws DbRuntimeException
     *      Arg statement not (no longer?) resource.
     */
    public function __construct(DbQueryInterface $query, $connection, $statement)
    {
        $this->query = $query;
        if (!$statement) {
            $error = $this->query->client->getErrors(Database::ERRORS_STRING);
            $this->closeAndLog(__FUNCTION__);
            throw new DbRuntimeException(
                $this->query->client->errorMessagePrefix()
                . ' - can\'t initialize result because arg $statement is not (no longer?) a resource, error:
                ' . $error . '.'
            );
        }
        $this->statement = $statement;
    }

    /**
     * Number of rows affected by a CRUD statement.
     *
     * NB: Query class cursor mode must be SQLSRV_CURSOR_FORWARD ('forward').
     * @see MsSqlQuery::__constructor()
     *
     * @return int
     *
     * @throws DbRuntimeException
     *      No count, (probably) not a CRUD query.
     * @throws \LogicException
     *      Bad query class cursor mode.
     * @throws DbResultException
     */
    public function affectedRows() : int
    {
        $count = @sqlsrv_rows_affected(
            $this->statement
        );
        // @todo: does sqlsrv_rows_affected() move to first result set, since requiring cursor mode 'forward'?
        // @todo: ++$this->setIndex;

        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        if ($count === -1) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbRuntimeException(
                $this->query->errorMessagePrefix()
                . ' - rejected counting affected rows (native returned -1), probably not a CRUD query.'
            );
        }
        // Cursor mode must be SQLSRV_CURSOR_FORWARD ('forward').
        if ($this->query->cursorMode != SQLSRV_CURSOR_FORWARD) {
            $this->closeAndLog(__FUNCTION__);
            throw new \LogicException(
                $this->query->client->errorMessagePrefix() . ' - cursor mode[' . $this->query->cursorMode
                . '] forbids getting affected rows, use SQLSRV_CURSOR_FORWARD (\'forward\') instead.'
            );
        }
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed counting affected rows, error: ' . $error . '.'
        );
    }

    /**
     * Auto ID set by last insert or update statement.
     *
     * NB: Requires that the sql contains a secondary ID selecting statement
     * ; SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME
     * Use query class option 'insert_id'.
     * @see MsSqlQuery::__constructor()
     * @see https://blogs.msdn.microsoft.com/nickhodge/2008/09/22/sql-server-driver-for-php-last-inserted-row-id/
     * @see https://docs.microsoft.com/en-us/sql/t-sql/functions/scope-identity-transact-sql
     *
     * @param int|string|null $getAsType
     *      String: i|d|s|b.
     *      Integer: An SQLSRV_PHPTYPE_* constant.
     *      Null: Use driver default; probably string.
     *
     * @return mixed|null
     *      Null: The query didn't trigger setting an ID.
     *
     * @throws \LogicException
     *      Sql misses secondary ID select statement.
     * @throws \InvalidArgumentException
     *      Invalid arg $getAsType value.
     * @throws \TypeError
     *      Arg $getAsType not int|string|null.
     * @throws DbResultException
     *      Next result.
     *      Next row.
     *      Other failure.
     */
    public function insertId($getAsType = null)
    {
        /**
         * Have to load first result set and row before sqlsrv_get_field()
         * when looking for insert ID.
         */
        if ($this->setIndex < 0) {
            $next = $this->nextSet(true);
            if (!$next) {
                $this->checkFailingInsertId();
                if ($next === null) {
                    // No result at all.
                    $this->closeAndLog(__FUNCTION__);
                    throw new DbResultException(
                        $this->query->errorMessagePrefix()
                        . ' - failed going to next set to get insert ID, no result at all.'
                    );
                }
                $error = $this->query->client->getErrors(Database::ERRORS_STRING);
                $this->closeAndLog(__FUNCTION__);
                throw new DbResultException(
                    $this->query->errorMessagePrefix() . ' - failed going to next set to get insert ID, error: '
                    . $error . '.'
                );
            }
        }
        if ($this->rowIndex < 0) {
            $next = $this->nextRow(true);
            if (!$next) {
                $this->checkFailingInsertId();
                if ($next === null) {
                    // No row at all because rowIndex was -1.
                    $this->closeAndLog(__FUNCTION__);
                    throw new DbResultException(
                        $this->query->errorMessagePrefix()
                        . ' - failed going to next set to get insert ID, no result row at all.'
                    );
                }
                $error = $this->query->client->getErrors(Database::ERRORS_STRING);
                $this->closeAndLog(__FUNCTION__);
                throw new DbResultException(
                    $this->query->errorMessagePrefix() . ' - failed going to next row to get insert ID, error: '
                    . $error . '.'
                );
            }
        }

        ++$this->rowIndex;

        if ($getAsType) {
            if (is_int($getAsType)) {
                $type = $getAsType;
            }
            elseif (is_string($getAsType)) {
                switch ($getAsType) {
                    case 'i':
                        $type = SQLSRV_PHPTYPE_INT;
                        break;
                    case 'd':
                        $type = SQLSRV_PHPTYPE_FLOAT;
                        break;
                    case 's':
                    case 'b':
                        $type = SQLSRV_PHPTYPE_STRING($this->query->client->characterSet);
                        break;
                    default:
                        $this->closeAndLog(__FUNCTION__);
                        throw new \InvalidArgumentException(
                            $this->query->errorMessagePrefix() . ' - arg $getAsType as string isn\'t i|d|s|b.'
                        );
                }
            }
            else {
                $this->closeAndLog(__FUNCTION__);
                throw new \TypeError(
                    $this->query->errorMessagePrefix()
                    . ' - arg $getAsType type[' . gettype($getAsType) . '] isn\'t integer|string|null.'
                );
            }
            $id = @sqlsrv_get_field($this->statement, 0, $type);
        } else {
            $id = @sqlsrv_get_field($this->statement, 0);
        }
        if ($id || $id === null) {
            /**
             * Null: query didn't trigger setting an ID;
             * despite Sqlsrv documentation doesn't mention null return.
             * @see sqlsrv_get_field()
             */
            return $id;
        }
        $this->checkFailingInsertId();
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting insert ID, error: ' . $error . '.'
        );
    }

    /**
     * Number of rows in a result set.
     *
     * NB: Query class cursor mode must be SQLSRV_CURSOR_STATIC ('static')
     * or SQLSRV_CURSOR_KEYSET ('keyset').
     * @see MsSqlQuery::__constructor()
     *
     * @return int
     *
     * @throws \LogicException
     *      Statement cursor mode not 'static' or 'keyset'.
     * @throws DbResultException
     */
    public function numRows() : int
    {
        $count = @sqlsrv_num_rows(
            $this->statement
        );
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        switch ($this->query->cursorMode) {
            case SQLSRV_CURSOR_STATIC:
            case SQLSRV_CURSOR_KEYSET:
                break;
            default:
                $this->closeAndLog(__FUNCTION__);
                throw new \LogicException(
                    $this->query->client->errorMessagePrefix() . ' - cursor mode[' . $this->query->cursorMode
                    . '] forbids getting number of rows'
                    . ', use SQLSRV_CURSOR_STATIC (\'static\') or SQLSRV_CURSOR_KEYSET (\'static\') instead.'
                );
        }
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting number of rows, error: ' . $error . '.'
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
        $count = @sqlsrv_num_fields(
            $this->statement
        );
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting number of columns, error: ' . $error . '.'
        );
    }

    /**
     * Get value of a single column in a row.
     *
     * Nb: Don't call this more times for a single row using arg $column;
     * will move cursor to next row.
     *
     * @param int $index
     * @param string $column
     *
     * @return mixed|null
     *      Null: No more rows.
     *
     * @throws \InvalidArgumentException
     *      Arg $index negative.
     * @throws \OutOfRangeException
     *      Result row has no such $column.
     * @throws DbResultException
     */
    public function fetchField(int $index = 0, string $column = '')
    {
        if (!$column) {
            if ($index < 0) {
                $this->closeAndLog(__FUNCTION__);
                throw new \InvalidArgumentException(
                    $this->query->errorMessagePrefix() . ' - failed fetching field, arg $index['
                    . $index . '] cannot be negative.'
                );
            }
            // @todo: do we have to load first row before sqlsrv_get_field() - this is not insertId().
            if ($this->rowIndex < 0) {
                $next = $this->nextRow(true);
                if (!$next) {
                    if ($next === null) {
                        // No row at all because rowIndex was -1.
                        throw new DbResultException(
                            $this->query->errorMessagePrefix() . ' - failed getting field by '
                            . (!$column ? ('$index[' . $index . ']') : ('$column[' . $column . ']'))
                            . ', no result row at all.'
                        );
                    }
                    $error = $this->query->client->getErrors(Database::ERRORS_STRING);
                    $this->closeAndLog(__FUNCTION__);
                    throw new DbResultException(
                        $this->query->errorMessagePrefix() . ' - failed going to next row to get field by '
                        . (!$column ? ('$index[' . $index . ']') : ('$column[' . $column . ']')) . ', error: '
                        . $error . '.'
                    );
                }
            }
            $value = @sqlsrv_get_field($this->statement, $index);
            /**
             * Null: Sqlsrv documentation doesn't mention null return,
             * but have observed such.
             * @see sqlsrv_get_field()
             * @see MsSqlResult::insertId()
             */
            if ($value || ($value !== false && $value !== null)) {
                return $value;
            }
            // Try to detect out-of-range;
            // falsy sqlsrv_get_field() and no native error
            if (!$this->query->client->getErrors(Database::ERRORS_STRING_EMPTY_NONE)) {
                $this->closeAndLog(__FUNCTION__);
                throw new \OutOfRangeException(
                    $this->query->errorMessagePrefix() . ' - failed fetching field by $index[' . $index
                    . '], presumably row has no such index.'
                );
            }
            // Otherwise continue to exception at and of method.
        }
        else {
            $row = @sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_ASSOC);
            // sqlsrv_fetch_array() implicitly moves to first set.
            if ($this->setIndex < 0) {
                ++$this->setIndex;
            }
            ++$this->rowIndex;
            if ($row) {
                if (array_key_exists($column, $row)) {
                    return $row[$column];
                }
                $this->closeAndLog(__FUNCTION__);
                throw new \OutOfRangeException(
                    $this->query->errorMessagePrefix()
                    . ' - failed fetching field, row has no $column[' . $column . '].'
                );
            }
            elseif ($row === null) {
                // No more rows.
                return null;
            }
        }
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed fetching field by '
            . (!$column ? ('$index[' . $index . ']') : ('$column[' . $column . ']')) . ', error: ' . $error . '.'
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
     */
    public function fetchArray(int $as = Database::FETCH_ASSOC)
    {
        $row = @sqlsrv_fetch_array(
            $this->statement,
            $as == Database::FETCH_ASSOC ? SQLSRV_FETCH_ASSOC : SQLSRV_FETCH_NUMERIC
        );
        // sqlsrv_fetch_array() implicitly moves to first set.
        if ($this->setIndex < 0) {
            ++$this->setIndex;
        }
        ++$this->rowIndex;
        if ($row || $row === null) {
            return $row;
        }
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed fetching row as '
            . ($as == Database::FETCH_ASSOC ? 'assoc' : 'numeric') . ' array, error: ' . $error . '.'
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
     */
    public function fetchObject(string $class = '', array $args = [])
    {
        $row = @sqlsrv_fetch_object($this->statement, $class, $args);
        // sqlsrv_fetch_object() implicitly moves to first set.
        if ($this->setIndex < 0) {
            ++$this->setIndex;
        }
        ++$this->rowIndex;
        if ($row || $row === null) {
            return $row;
        }
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed fetching row as object, error: ' . $error . '.'
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
     * @throws \LogicException
     *      Providing 'list_by_column' option when fetching as numeric array.
     * @throws \InvalidArgumentException
     *      Providing 'list_by_column' option and no such column in result row.
     * @throws DbResultException
     */
    public function fetchAll(int $as = Database::FETCH_ASSOC, array $options = []) : array
    {
        $column_keyed = !empty($options['list_by_column']);
        $list = [];
        switch ($as) {
            case Database::FETCH_NUMERIC:
                if ($column_keyed) {
                    $this->closeAndLog(__FUNCTION__);
                    throw new \LogicException(
                        $this->query->client->errorMessagePrefix()
                        . ' - arg $options \'list_by_column\' is not supported when fetching as numeric arrays.'
                    );
                }
                while (($row = @sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_NUMERIC))) {
                    // sqlsrv_fetch_array() implicitly moves to first set.
                    if ($this->setIndex < 0) {
                        ++$this->setIndex;
                    }
                    ++$this->rowIndex;
                    $list[] = $row;
                }
                if ($this->setIndex < 0) {
                    ++$this->setIndex;
                }
                ++$this->rowIndex;
                break;
            case Database::FETCH_OBJECT:
                $key_column = !$column_keyed ? null : $options['list_by_column'];
                $first = true;
                while (
                    ($row = @sqlsrv_fetch_object($this->statement, $options['class'] ?? '', $options['args'] ?? []))
                ) {
                    // sqlsrv_fetch_object() implicitly moves to first set.
                    if ($this->setIndex < 0) {
                        ++$this->setIndex;
                    }
                    ++$this->rowIndex;
                    if (!$column_keyed) {
                        $list[] = $row;
                    }
                    else {
                        if ($first) {
                            $first = false;
                            if (!property_exists($row, $key_column)) {
                                $this->closeAndLog(__FUNCTION__);
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
                if ($this->setIndex < 0) {
                    ++$this->setIndex;
                }
                ++$this->rowIndex;
                break;
            default:
                $key_column = !$column_keyed ? null : $options['list_by_column'];
                $first = true;
                while (($row = @sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_ASSOC))) {
                    // sqlsrv_fetch_array() implicitly moves to first set.
                    if ($this->setIndex < 0) {
                        ++$this->setIndex;
                    }
                    ++$this->rowIndex;
                    if (!$column_keyed) {
                        $list[] = $row;
                    }
                    else {
                        if ($first) {
                            $first = false;
                            if (!array_key_exists($key_column, $row)) {
                                $this->closeAndLog(__FUNCTION__);
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
                if ($this->setIndex < 0) {
                    ++$this->setIndex;
                }
                ++$this->rowIndex;
        }
        // Last fetched row must be null; no more rows.
        if ($row !== null) {
            switch ($as) {
                case Database::FETCH_NUMERIC:
                    $em = 'numeric array';
                    break;
                case Database::FETCH_OBJECT:
                    $em = 'object';
                    break;
                default:
                    $em = 'assoc array';
            }
            $error = $this->query->client->getErrors(Database::ERRORS_STRING);
            $this->closeAndLog(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix()
                . ' - failed fetching all rows as ' . $em . ', error: ' . $error . '.'
            );
        }
        return $list;
    }

    /**
     * Move cursor to next result set.
     *
     * @param bool $noException
     *      True: return false on failure, don't throw exception.
     *
     * @return bool|null
     *      Null: No next result set.
     *
     * @throws DbResultException
     */
    public function nextSet(bool $noException = false)
    {
        $this->free();
        $next = @sqlsrv_next_result($this->statement);
        ++$this->setIndex;
        $this->rowIndex = -1;
        if ($next) {
            return true;
        }
        if ($next === null) {
            return null;
        }
        if ($noException) {
            return false;
        }
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed going to next set, error: ' . $error . '.'
        );
    }

    /**
     * Go to next row in the result set.
     *
     * @param bool $noException
     *      True: return false on failure, don't throw exception.
     *
     * @return bool|null
     *      Null: No next row.
     *
     * @throws DbResultException
     */
    public function nextRow(bool $noException = false)
    {
        $next = @sqlsrv_fetch($this->statement);
        ++$this->rowIndex;
        if ($next) {
            return $next;
        }
        if ($next === null) {
            null;
        }
        if ($noException) {
            return false;
        }
        $error = $this->query->client->getErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed going to next row, error: ' . $error . '.'
        );
    }

    /**
     * Please call despite currently doing nothing; may do something later.
     *
     * Does nothing because freeing result would also close the statement,
     * since they share the same resource.
     * @see MsSqlQuery::close()
     *
     * @return void
     */
    public function free() /*: void*/
    {
    }


    // Helpers.-----------------------------------------------------------------

    /**
     * @see MsSqlResult::insertId()
     *
     * @throws \LogicException
     */
    protected function checkFailingInsertId()
    {
        if (
            !$this->query->getInsertId
            && strpos(
                $this->query->sqlTampered ?? $this->query->sql,
                'SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME'
            ) === false
        ) {
            $this->closeAndLog(__FUNCTION__);
            throw new \LogicException(
                $this->query->errorMessagePrefix() . ' - failed getting insert ID'
                . ', sql misses secondary ID select statement, use query option \'insert_id\''
            );
        }
    }
}

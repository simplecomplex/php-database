<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
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
class MsSqlResult extends DbResult
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
                $this->query->client->messagePrefix()
                . ' - can\'t initialize result because arg $statement is not (no longer?) a resource, error:
                ' . $error . '.'
            );
        }
        $this->statement = $statement;
    }

    /**
     * Number of rows affected by a CRUD statement.
     *
     * NB: Query class result mode must be SQLSRV_CURSOR_FORWARD ('forward').
     * @see MsSqlQuery::__constructor()
     *
     * @return int
     *
     * @throws \LogicException
     *      Bad query class result mode.
     * @throws DbRuntimeException
     */
    public function affectedRows() : int
    {
        $count = @sqlsrv_rows_affected(
            $this->statement
        );
        // @todo: does sqlsrv_rows_affected() move to first result set, since requiring result mode 'forward'?
        // @todo: ++$this->setIndex;
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        if ($count === -1) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbRuntimeException(
                $this->query->messagePrefix()
                . ' - rejected counting affected rows (native returned -1), probably not a CRUD query.'
            );
        }
        // Result mode must be SQLSRV_CURSOR_FORWARD ('forward').
        if ($this->query->resultMode != SQLSRV_CURSOR_FORWARD) {
            $this->closeAndLog(__FUNCTION__);
            throw new \LogicException(
                $this->query->client->messagePrefix() . ' - result mode[' . $this->query->resultMode
                . '] forbids getting affected rows, use SQLSRV_CURSOR_FORWARD (\'forward\') instead.'
            );
        }
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed counting affected rows, error: '
            . $this->query->client->errorsToString($errors) . '.'
        );
    }

    /**
     * Auto ID set by last insert or update statement.
     *
     * NB: Goes to next result set, expecting first/current to be a CRUD
     * statement and next to be SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME.
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
     * @throws DbRuntimeException
     *      Other failure.
     */
    public function insertId($getAsType = null)
    {
        /**
         * Have to load first result set and row before sqlsrv_get_field()
         * when looking for insert ID.
         */
        if ($this->setIndex < 0) {
            if (!$this->nextSet()) {
                $this->checkFailingInsertId();
                // No result at all.
                $this->closeAndLog(__FUNCTION__);
                throw new DbResultException(
                    $this->query->messagePrefix()
                    . ' - failed going to next set to get insert ID, no result at all.'
                );
            }
        }
        if ($this->rowIndex < 0) {
            if (!$this->nextRow()) {
                $this->checkFailingInsertId();
                // No row at all.
                $this->closeAndLog(__FUNCTION__);
                throw new DbResultException(
                    $this->query->messagePrefix()
                    . ' - failed going to next set to get insert ID, no result row at all.'
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
                            $this->query->messagePrefix() . ' - arg $getAsType as string isn\'t i|d|s|b.'
                        );
                }
            }
            else {
                $this->closeAndLog(__FUNCTION__);
                throw new \TypeError(
                    $this->query->messagePrefix()
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
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed getting insert ID, error: '
            . $this->query->client->errorsToString($errors) . '.'
        );
    }

    /**
     * Number of rows in a result set.
     *
     * NB: Query class result mode must be SQLSRV_CURSOR_STATIC ('static')
     * or SQLSRV_CURSOR_KEYSET ('keyset').
     * @see MsSqlQuery::__constructor()
     *
     * Go for design patterns that don't require numRows().
     * @code
     * // Alternatives - only needing row count:
     * $num_rows = count($result->fetchAll(Database::FETCH_NUMERIC));
     * // Alternatives - only needing row count, and giant amounts of data:
     * $num_rows = 0;
     * while (($result->nextRow())) {
     *     ++$num_rows;
     * }
     * // Alternatives - do-if:
     * $num_rows = 0;
     * while (($row = $result->fetchArray())) {
     *     if (!$num_rows) {
     *         // Fetch expensive resources required to process rows.
     *     }
     *     ++$num_rows;
     *     // Process row.
     * }
     * if (!$num_rows) {
     *     // Workaround.
     * }
     * @endcode
     *
     * @return int
     *
     * @throws \LogicException
     *      Statement result mode not 'static' or 'keyset'.
     * @throws DbRuntimeException
     */
    public function numRows() : int
    {
        $count = @sqlsrv_num_rows(
            $this->statement
        );
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        switch ($this->query->resultMode) {
            case SQLSRV_CURSOR_STATIC:
            case SQLSRV_CURSOR_KEYSET:
                break;
            default:
                $this->closeAndLog(__FUNCTION__);
                throw new \LogicException(
                    $this->query->client->messagePrefix() . ' - result mode[' . $this->query->resultMode
                    . '] forbids getting number of rows'
                    . ', use SQLSRV_CURSOR_STATIC (\'static\') or SQLSRV_CURSOR_KEYSET (\'static\') instead.'
                );
        }
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed getting number of rows, error: '
            . $this->query->client->errorsToString($errors) . '.'
        );
    }

    /**
     * Number of columns in a result row.
     *
     * @return int
     *
     * @throws DbRuntimeException
     */
    public function numColumns() : int
    {
        $count = @sqlsrv_num_fields(
            $this->statement
        );
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed getting number of columns, error: '
            . $this->query->client->errorsToString($errors) . '.'
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
     * @throws DbRuntimeException
     */
    public function fetchField(int $index = 0, string $column = '')
    {
        if (!$column) {
            if ($index < 0) {
                $this->closeAndLog(__FUNCTION__);
                throw new \InvalidArgumentException(
                    $this->query->messagePrefix() . ' - failed fetching field, arg $index['
                    . $index . '] cannot be negative.'
                );
            }
            if ($this->rowIndex < 0 && !$this->nextRow()) {
                // No row at all.
                $this->closeAndLog(__FUNCTION__);
                throw new DbResultException(
                    $this->query->messagePrefix() . ' - failed getting field by '
                    . (!$column ? ('$index[' . $index . ']') : ('$column[' . $column . ']'))
                    . ', no result row at all.'
                );
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
                    $this->query->messagePrefix() . ' - failed fetching field by $index[' . $index
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
                    $this->query->messagePrefix()
                    . ' - failed fetching field, row has no $column[' . $column . '].'
                );
            }
            elseif ($row === null) {
                // No more rows.
                return null;
            }
        }
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching field by '
            . (!$column ? ('$index[' . $index . ']') : ('$column[' . $column . ']')) . ', error: '
            . $this->query->client->errorsToString($errors) . '.'
        );
    }

    /**
     * Associative (column-keyed) or numerically indexed array.
     *
     * @param int $as
     *      Default: ~associative.
     *      Database::FETCH_ASSOC|Database::FETCH_NUMERIC
     *
     * @return array|null
     *      Null: No more rows.
     *
     * @throws DbRuntimeException
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
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching row as '
            . ($as == Database::FETCH_ASSOC ? 'assoc' : 'numeric') . ' array, error: '
            . $this->query->client->errorsToString($errors) . '.'
        );
    }

    /**
     * Column-keyed object.
     *
     * @param string $class
     *      Optional class name; effective default stdClass.
     * @param array $args
     *      Optional constructor args.
     *
     * @return object|null
     *      Null: No more rows.
     *
     * @throws DbRuntimeException
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
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching row as object, error: '
            . $this->query->client->errorsToString($errors) . '.'
        );
    }

    /**
     * Fetch all rows into a list.
     *
     * Option 'list_by_column' is not supported when fetching as numerically
     * indexed arrays.
     *
     * @param int $as
     *      Default: ~associative.
     *      Database::FETCH_ASSOC|Database::FETCH_NUMERIC|Database::FETCH_OBJECT
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
     * @throws DbRuntimeException
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
                        $this->query->client->messagePrefix()
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
                                    $this->query->messagePrefix()
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
                                    $this->query->messagePrefix()
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
            $errors = $this->query->client->getErrors();
            $this->closeAndLog(__FUNCTION__);
            $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed fetching all rows as ' . $em . ', error: '
                . $this->query->client->errorsToString($errors) . '.'
            );
        }
        return $list;
    }

    /**
     * Move cursor to next result set.
     *
     * @return bool
     *      False: No next result set.
     *      Throws exception on failure.
     *
     * @throws DbRuntimeException
     */
    public function nextSet() : bool
    {
        $this->free();
        $next = @sqlsrv_next_result($this->statement);
        ++$this->setIndex;
        $this->rowIndex = -1;
        if ($next) {
            return true;
        }
        if ($next === null) {
            return false;
        }
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed going to set[' . $this->setIndex . '], error: '
            . $this->query->client->errorsToString($errors) . '.'
        );
    }

    /**
     * Go to (first or) next row in the result set.
     *
     * @return bool
     *      False: No next row.
     *      Throws exception on failure.
     *
     * @throws DbRuntimeException
     */
    public function nextRow() : bool
    {
        $next = @sqlsrv_fetch($this->statement);
        ++$this->rowIndex;
        if ($next) {
            return true;
        }
        if ($next === null) {
            return false;
        }
        $errors = $this->query->client->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed going to next row, error: '
            . $this->query->client->errorsToString($errors) . '.'
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
            && stripos(
                $this->query->sqlTampered ?? $this->query->sql,
                MsSqlQuery::SQL_INSERT_ID
            ) === false
        ) {
            $this->closeAndLog(__FUNCTION__);
            throw new \LogicException(
                $this->query->messagePrefix() . ' - failed getting insert ID'
                . ', sql misses secondary ID select statement, use query option \'insert_id\''
            );
        }
    }
}

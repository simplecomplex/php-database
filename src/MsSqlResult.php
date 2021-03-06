<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018-2019 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Utils\Utils;
use SimpleComplex\Time\Time;

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
            $error = $this->query->client->getErrors(DbError::AS_STRING);
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
     * @see MsSqlQuery::__construct()
     *
     * Goes to first result set (initially), but doesn't move to next set.
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
        // sqlsrv_rows_affected() moves to first result set.
        // However doesn't move to next on later call.
        if ($this->setIndex < 0) {
            ++$this->setIndex;
        }
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
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Auto ID set by last insert or update statement.
     *
     * NB: Goes to next result set, expecting first/current to be a CRUD
     * statement and next to be SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME.
     * Use query class option 'insert_id'.
     * @see MsSqlQuery::__construct()
     * @see https://blogs.msdn.microsoft.com/nickhodge/2008/09/22/sql-server-driver-for-php-last-inserted-row-id/
     * @see https://docs.microsoft.com/en-us/sql/t-sql/functions/scope-identity-transact-sql
     *
     *@param string|null $getAsType
     *      Values: i|int|integer|d|float|s|string.
     *
     * @return string|int|float|null
     *      Null: The query didn't trigger setting an ID.
     *
     * @throws \LogicException
     *      Sql misses secondary ID select statement.
     * @throws \InvalidArgumentException
     *      Invalid arg $getAsType value.
     * @throws DbResultException
     *      Next result.
     *      Next row.
     * @throws DbRuntimeException
     *      Other failure.
     */
    public function insertId(string $getAsType = null)
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
            switch ($getAsType) {
                case 'i':
                case 'int':
                case 'integer':
                    $id = @sqlsrv_get_field($this->statement, 0, SQLSRV_PHPTYPE_INT);
                    break;
                case 'd':
                case 'float':
                    $id = @sqlsrv_get_field($this->statement, 0, SQLSRV_PHPTYPE_FLOAT);
                    break;
                case 's':
                case 'string':
                    $id = @sqlsrv_get_field(
                        $this->statement,
                        0,
                        $this->query->client->characterSet == 'UTF-8' ? SQLSRV_PHPTYPE_STRING('UTF-8') :
                            SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR)
                    );
                    break;
                default:
                    $id = @sqlsrv_get_field($this->statement, 0);
            }
        }
        else {
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
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Number of rows in a result set.
     *
     * NB: Query class result mode must be SQLSRV_CURSOR_STATIC ('static')
     * or SQLSRV_CURSOR_KEYSET ('keyset').
     * @see MsSqlQuery::__construct()
     *
     * Go for design patterns that don't require numRows().
     * @code
     * // Alternatives - only needing row count:
     * $num_rows = count($result->fetchAll(DbResult::FETCH_NUMERIC));
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
        if (!@sqlsrv_has_rows($this->statement)) {
            $has_rows = false;
        }
        else {
            $has_rows = true;
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
        }
        $errors = $this->query->client->getErrors();
        if (!$has_rows && !$errors) {
            return 0;
        }
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed getting number of rows, error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
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
        if (!@sqlsrv_has_rows($this->statement)) {
            $has_rows = false;
        }
        else {
            $has_rows = true;
            $count = @sqlsrv_num_fields(
                $this->statement
            );
            if (($count && $count > 0) || $count === 0) {
                return $count;
            }
        }
        $errors = $this->query->client->getErrors();
        if (!$has_rows && !$errors) {
            return 0;
        }
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed getting number of columns, error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Fetch value of a single column in a single row.
     *
     * Nb: Don't call this more times for a single row using arg $name;
     * will move cursor to next row.
     *
     * @param int $index
     * @param string $name
     *      Non-empty: fetch column by that name, ignore arg $index.
     *
     * @return mixed|null
     *      Null: No more rows.
     *
     * @throws \InvalidArgumentException
     *      Arg $index negative.
     * @throws \OutOfRangeException
     *      Result row has no such $index|$name.
     * @throws DbRuntimeException
     * @throws \Exception
     *      Propagated; Time.
     */
    public function fetchField(int $index = 0, string $name = null)
    {
        if (!@sqlsrv_has_rows($this->statement)) {
            $has_rows = false;
        }
        else {
            $has_rows = true;
            // Column name cannot be '0' (sql illegal) so loose check suffices.
            if (!$name) {
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
                        . (!$name ? ('$index[' . $index . ']') : ('$name[' . $name . ']'))
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
                if ($value) {
                    if ($this->query->resultDateTimeToTime && $value instanceof \DateTime) {
                        return Time::createFromDateTime($value);
                    }
                    return $value;
                }
                if ($value !== false && $value !== null) {
                    return $value;
                }
                // Assume that the value actually is null, unless native error.
                if ($value === null && !$this->query->client->getErrors(DbError::AS_STRING_EMPTY_ON_NONE)) {
                    return null;
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
                    if (array_key_exists($name, $row)) {
                        if ($row[$name] && $this->query->resultDateTimeToTime && $row[$name] instanceof \DateTime) {
                            return Time::createFromDateTime($row[$name]);
                        }
                        return $row[$name];
                    }
                    $this->closeAndLog(__FUNCTION__);
                    throw new \OutOfRangeException(
                        $this->query->messagePrefix()
                        . ' - failed fetching field, row has no $name[' . $name . '].'
                    );
                }
                elseif ($row === null) {
                    // No more rows.
                    return null;
                }
            }
        }
        $errors = $this->query->client->getErrors();
        if (!$has_rows && !$errors) {
            return null;
        }
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching field by '
            . (!$name ? ('$index[' . $index . ']') : ('$name[' . $name . ']')) . ', error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Fetches value of a single column of all rows, by index of column.
     *
     * Algo separated from fetchFieldAll because fetching via sqlsrv_get_field()
     * is more efficient than working via this lib's fetchArrayAll().
     *
     * @param int $index
     *
     * @return array
     *      Empty on no rows.
     *      Throws throwable on failure.
     *
     * @throws \InvalidArgumentException
     *      Arg $index negative.
     * @throws \OutOfRangeException
     *      Result row has no such $index|$name.
     * @throws DbRuntimeException
     * @throws \Exception
     *      Propagated; Time.
     */
    protected function fetchFieldAllByIndex(int $index = 0) : array
    {
        if ($index < 0) {
            $this->closeAndLog(__FUNCTION__);
            throw new \InvalidArgumentException(
                $this->query->messagePrefix() . ' - failed fetching all fields by index, arg $index['
                . $index . '] cannot be negative.'
            );
        }
        if (!@sqlsrv_has_rows($this->statement)) {
            $has_rows = false;
        }
        else {
            $has_rows = true;
            if ($this->rowIndex < 0 && !$this->nextRow()) {
                // No row at all.
                $this->closeAndLog(__FUNCTION__);
                throw new DbResultException(
                    $this->query->messagePrefix() . ' - failed getting all fields by '
                    . '$index[' . $index . '], no result row at all.'
                );
            }
            $err = false;
            $a = [];
            $to_time = $this->query->resultDateTimeToTime;
            do {
                $value = @sqlsrv_get_field($this->statement, $index);
                if ($value) {
                    if ($to_time && $value instanceof \DateTime) {
                        $a[] = Time::createFromDateTime($value);
                    } else {
                        $a[] = $value;
                    }
                }
                elseif ($value !== false) {
                    $a[] = $value;
                } else {
                    $err = true;
                    break;
                }
            } while($this->nextRow());
            if (!$err) {
                return $a;
            }
        }
        $errors = $this->query->client->getErrors();
        if (!$has_rows && !$errors) {
            return [];
        }
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching all fields by '
            . '$index[' . $index . '], error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Fetch row as associative (column-keyed) or numerically indexed array.
     *
     * @param int $as
     *      Default: ~associative.
     *      DbResult::FETCH_ASSOC|DbResult::FETCH_NUMERIC
     *
     * @return array|null
     *      Null: No more rows.
     *
     * @throws DbRuntimeException
     * @throws \Exception
     *      Propagated; Time.
     */
    public function fetchArray(int $as = DbResult::FETCH_ASSOC) /*: ?array*/
    {
        if (!@sqlsrv_has_rows($this->statement)) {
            $has_rows = false;
        }
        else {
            $has_rows = true;
            $row = @sqlsrv_fetch_array(
                $this->statement,
                $as == DbResult::FETCH_ASSOC ? SQLSRV_FETCH_ASSOC : SQLSRV_FETCH_NUMERIC
            );
            // sqlsrv_fetch_array() implicitly moves to first set.
            if ($this->setIndex < 0) {
                ++$this->setIndex;
            }
            ++$this->rowIndex;
            if ($row) {
                if ($this->query->resultDateTimeToTime) {
                    foreach ($row as &$val) {
                        if ($val instanceof \DateTime) {
                            $val = Time::createFromDateTime($val);
                        }
                    }
                    unset($val);
                }
                return $row;
            }
            if ($row === null) {
                return null;
            }
        }
        $errors = $this->query->client->getErrors();
        if (!$has_rows && !$errors) {
            return null;
        }
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching row as '
            . ($as == DbResult::FETCH_ASSOC ? 'assoc' : 'numeric') . ' array, error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Fetch row as column-keyed object.
     *
     * If non-empty arg $class: Custom (non-stdClass) object gets constructed
     * and populated 'manually', because native Sqlsrv method cannot handle it.
     * @see sqlsrv_fetch_object()
     * @see https://github.com/Microsoft/msphpsql/issues/119
     *
     * @param string $class
     *      Optional class name; effective default stdClass.
     * @param array $args
     *      Optional constructor args.
     *
     * @return object|null
     *      Null: No more rows.
     *
     * @throws \InvalidArgumentException
     *      Non-empty arg $class when such class doesn't exist.
     * @throws DbRuntimeException
     * @throws \Exception
     *      Propagated; Time.
     */
    public function fetchObject(string $class = null, array $args = null) /*: ?object*/
    {
        if ($class && !class_exists($class)) {
            $this->closeAndLog(__FUNCTION__);
            throw new \InvalidArgumentException(
                $this->query->messagePrefix() . ' - can\'t fetch row as object into non-existent class[' . $class . '].'
            );
        }
        if (!@sqlsrv_has_rows($this->statement)) {
            $has_rows = false;
        }
        else {
            $has_rows = true;
            /**
             * Custom (non-stdClass) object gets constructed and populated 'manually',
             * because native Sqlsrv method cannot handle that.
             * Passing class name arg to sqlsrv_fetch_object() produces segmentation
             * fault for namespaced class (because namespace\class gets lowercased).
             * @see sqlsrv_fetch_object()
             * @see https://github.com/Microsoft/msphpsql/issues/119
             */
            //$row = @sqlsrv_fetch_object($this->statement, $class, $args);
            $row = @sqlsrv_fetch_object($this->statement);

            // sqlsrv_fetch_object() implicitly moves to first set.
            if ($this->setIndex < 0) {
                ++$this->setIndex;
            }
            ++$this->rowIndex;
            if ($row) {
                $to_time = $this->query->resultDateTimeToTime;
                // Custom (non-stdClass) object routine.
                if ($class && $class != \stdClass::class) {
                    $o = !$args ? new $class() :
                        new $class(...$args);
                    foreach ($row as $column => $value) {
                        $o->{$column} = $to_time && $value instanceof \DateTime ?
                            Time::createFromDateTime($value) : $value;
                    }
                    return $o;
                }
                elseif ($to_time) {
                    foreach ($row as &$val) {
                        if ($val instanceof \DateTime) {
                            $val = Time::createFromDateTime($val);
                        }
                    }
                    unset($val);
                }
                return $row;
            }
            if ($row === null) {
                return null;
            }
        }
        $errors = $this->query->client->getErrors();
        if (!$has_rows && !$errors) {
            return null;
        }
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching row as object, error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Fetch all rows into a list of associative (column-keyed) or numerically
     * indexed arrays.
     *
     * @param int $as
     *      Default: ~associative.
     *      DbResult::FETCH_ASSOC|DbResult::FETCH_NUMERIC
     * @param string $list_by_column
     *      Key list by that column's values; illegal when $as:FETCH_NUMERIC.
     *      Empty: numerically indexed list.
     *
     * @return array
     *      Empty on no rows.
     *
     * @throws \InvalidArgumentException
     *      Non-empty arg $list_by_column when arg $as is FETCH_NUMERIC.
     *      Non-empty arg $list_by_column when no such column exist.
     * @throws DbRuntimeException
     * @throws \Exception
     *      Propagated; Time.
     */
    public function fetchArrayAll(int $as = DbResult::FETCH_ASSOC, string $list_by_column = null) : array
    {
        $list = [];
        if (!@sqlsrv_has_rows($this->statement)) {
            $has_rows = $row = false;
        }
        else {
            $has_rows = true;
            $to_time = $this->query->resultDateTimeToTime;
            if ($as == DbResult::FETCH_NUMERIC) {
                if ($list_by_column) {
                    $this->closeAndLog(__FUNCTION__);
                    throw new \InvalidArgumentException(
                        $this->query->client->messagePrefix() . ' - arg $list_by_column type['
                        . Utils::getType($list_by_column) . '] must be empty when fetching all rows as numeric arrays.'
                    );
                }
                while (($row = @sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_NUMERIC))) {
                    // sqlsrv_fetch_array() implicitly moves to first set.
                    if ($this->setIndex < 0) {
                        ++$this->setIndex;
                    }
                    ++$this->rowIndex;
                    if ($row && $to_time) {
                        foreach ($row as &$val) {
                            if ($val instanceof \DateTime) {
                                $val = Time::createFromDateTime($val);
                            }
                        }
                        unset($val);
                    }
                    $list[] = $row;
                }
                if ($this->setIndex < 0) {
                    ++$this->setIndex;
                }
                ++$this->rowIndex;
            }
            else {
                $first = true;
                while (($row = @sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_ASSOC))) {
                    // sqlsrv_fetch_array() implicitly moves to first set.
                    if ($this->setIndex < 0) {
                        ++$this->setIndex;
                    }
                    ++$this->rowIndex;
                    if ($row && $to_time) {
                        foreach ($row as &$val) {
                            if ($val instanceof \DateTime) {
                                $val = Time::createFromDateTime($val);
                            }
                        }
                        unset($val);
                    }
                    if (!$list_by_column) {
                        $list[] = $row;
                    }
                    else {
                        if ($first) {
                            $first = false;
                            if (!array_key_exists($list_by_column, $row)) {
                                $this->closeAndLog(__FUNCTION__);
                                throw new \InvalidArgumentException(
                                    $this->query->messagePrefix()
                                    . ' - failed fetching all rows as associative arrays listed by column['
                                    . $list_by_column . '], non-existent column.'
                                );
                            }
                        }
                        // Fails if non-stringable object.
                        $key = '' . $row[$list_by_column];
                        $list[$key] = $row;
                    }
                }
                if ($this->setIndex < 0) {
                    ++$this->setIndex;
                }
                ++$this->rowIndex;
            }
        }
        // Last fetched row must be null; no more rows.
        if ($row !== null) {
            $errors = $this->query->client->getErrors();
            if (!$has_rows && !$errors) {
                return [];
            }
            $this->closeAndLog(__FUNCTION__);
            $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed fetching complete list of all rows as '
                . ($as = DbResult::FETCH_ASSOC ? 'associative' : 'numeric') . ' arrays, error: '
                . $this->query->client->errorsToString($errors) . '.',
                $errors && reset($errors) ? key($errors) : 0
            );
        }
        return $list;
    }

    /**
     * Fetch all rows into a list of column-keyed objects.
     *
     * If non-empty arg $class: Custom (non-stdClass) object gets constructed
     * and populated 'manually', because native Sqlsrv method cannot handle it.
     * @see sqlsrv_fetch_object()
     * @see https://github.com/Microsoft/msphpsql/issues/119
     *
     * @param string $class
     *      Optional class name; effective default stdClass.
     * @param string $list_by_column
     *      Key list by that column's values; illegal when $as:FETCH_NUMERIC.
     *      Empty: numerically indexed list.
     * @param array $args
     *      Optional constructor args.
     *
     * @return object[]
     *      Empty on no rows.
     *
     * @throws \InvalidArgumentException
     *      Non-empty arg $class when such class doesn't exist.
     *      Non-empty arg $list_by_column when no such column exist.
     * @throws DbRuntimeException
     * @throws \Exception
     *      Propagated; Time.
     */
    public function fetchObjectAll(string $class = null, string $list_by_column = null, array $args = null) : array
    {
        if ($class && !class_exists($class)) {
            $this->closeAndLog(__FUNCTION__);
            throw new \InvalidArgumentException(
                $this->query->messagePrefix()
                . ' - can\'t fetch all rows as objects into non-existent class[' . $class . '].'
            );
        }
        $list = [];
        if (!@sqlsrv_has_rows($this->statement)) {
            $has_rows = $row = false;
        }
        else {
            $has_rows = true;
            $first = true;
            /**
             * Custom (non-stdClass) object gets constructed and populated 'manually',
             * because native Sqlsrv method cannot handle that.
             * Passing class name arg to sqlsrv_fetch_object() produces segmentation
             * fault for namespaced class (because namespace\class gets lowercased).
             * @see sqlsrv_fetch_object()
             * @see https://github.com/Microsoft/msphpsql/issues/119
             */
            //while (($row = @sqlsrv_fetch_object($this->statement, $class, $args))) {
            $custom_class = $class && $class != \stdClass::class;
            $to_time = $this->query->resultDateTimeToTime;
            while (($row = @sqlsrv_fetch_object($this->statement))) {
                // sqlsrv_fetch_object() implicitly moves to first set.
                if ($this->setIndex < 0) {
                    ++$this->setIndex;
                }
                ++$this->rowIndex;
                if (!$list_by_column) {
                    if ($custom_class) {
                        $o = !$args ? new $class() :
                            new $class(...$args);
                        foreach ($row as $column => $value) {
                            $o->{$column} = $to_time && $value instanceof \DateTime ?
                                Time::createFromDateTime($value) : $value;
                        }
                        $list[] = $o;
                    }
                    else {
                        if ($row && $to_time) {
                            foreach ($row as &$val) {
                                if ($val instanceof \DateTime) {
                                    $val = Time::createFromDateTime($val);
                                }
                            }
                            unset($val);
                        }
                        $list[] = $row;
                    }
                }
                else {
                    if ($first) {
                        $first = false;
                        if (!property_exists($row, $list_by_column)) {
                            $this->closeAndLog(__FUNCTION__);
                            throw new \InvalidArgumentException(
                                $this->query->messagePrefix()
                                . ' - failed fetching all rows as objects listed by column[' . $list_by_column
                                . '], non-existent column.'
                            );
                        }
                    }
                    if ($custom_class) {
                        $o = !$args ? new $class() :
                            new $class(...$args);
                        foreach ($row as $column => $value) {
                            $o->{$column} = $to_time && $value instanceof \DateTime ?
                                Time::createFromDateTime($value) : $value;
                        }
                        // Fails if non-stringable object.
                        $key = '' . $row->{$list_by_column};
                        $list[$key] = $o;
                    }
                    else {
                        if ($row && $to_time) {
                            foreach ($row as &$val) {
                                if ($val instanceof \DateTime) {
                                    $val = Time::createFromDateTime($val);
                                }
                            }
                            unset($val);
                        }
                        // Fails if non-stringable object.
                        $key = '' . $row->{$list_by_column};
                        $list[$key] = $row;
                    }
                }
            }
            if ($this->setIndex < 0) {
                ++$this->setIndex;
            }
            ++$this->rowIndex;
        }
        // Last fetched row must be null; no more rows.
        if ($row !== null) {
            $errors = $this->query->client->getErrors();
            if (!$has_rows && !$errors) {
                return [];
            }
            $this->closeAndLog(__FUNCTION__);
            $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed fetching complete list of all rows as objects, error: '
                . $this->query->client->errorsToString($errors) . '.',
                $errors && reset($errors) ? key($errors) : 0
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
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
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
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
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

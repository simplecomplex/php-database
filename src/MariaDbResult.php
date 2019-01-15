<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Utils\Utils;

use SimpleComplex\Database\Interfaces\DbQueryInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbQueryException;
use SimpleComplex\Database\Exception\DbResultException;

/**
 * MariaDB result.
 *
 * Both setIndex and rowIndex deliberately go out-of-bounds when first/next
 * set/row is called for and there aren't any more sets/rows.
 *
 * MySQLi's default prepared statement result handling is not used,
 * because binding result vars sucks IMHO.
 * @see \mysqli_stmt::get_result()
 *
 * Properties inherited from DatabaseResult:
 * @property-read int $setIndex
 * @property-read int $rowIndex
 *
 * @package SimpleComplex\Database
 */
class MariaDbResult extends DbResult
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
    protected $isPreparedStatement;

    /**
     * @param DbQueryInterface|MariaDbQuery $query
     * @param \MySQLi $mySqlI
     * @param \mysqli_stmt|null $statement
     *      \mysqli_stmt: If prepared statement.
     */
    public function __construct(DbQueryInterface $query, $mySqlI, $statement)
    {
        $this->query = $query;
        $this->mySqlI = $mySqlI;
        if ($statement) {
            $this->statement = $statement;
        }

        $this->isPreparedStatement = $this->query->isPreparedStatement;
    }

    /**
     * Number of rows affected by a CRUD statement.
     *
     * @return int
     *
     * @throws DbRuntimeException
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
        if ($count === -1) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbQueryException(
                $this->query->messagePrefix()
                . ' - rejected counting affected rows (returned -1), the query failed.'
            );
        }
        $errors = $this->query->getErrors();
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
     * @param string|null $getAsType
     *      Values: i|int|integer|d|float|s|string.
     *
     * @return string|int|float|null
     *      Null: The query didn't trigger setting an ID.
     *
     * @throws DbRuntimeException
     */
    public function insertId(string $getAsType = null)
    {
        if ($this->isPreparedStatement) {
            $id = @$this->statement->insert_id;
        } else {
            $id = @$this->mySqlI->insert_id;
        }
        if ($id) {
            if ($getAsType) {
                switch ($getAsType) {
                    case 'i':
                    case 'int':
                    case 'integer':
                        return (int) $id;
                    case 'd':
                    case 'float':
                        return (float) $id;
                    case 's':
                    case 'string':
                        return '' . $id;
                }
            }
            return $id;
        }
        /**
         * mysqli::$insert_id:
         * Returns zero if there was no previous query on the connection
         * or if the query did not update an AUTO_INCREMENT value.
         * @see http://php.net/manual/en/mysqli.insert-id.php
         *
         * mysqli_stmt::$insert_id
         * Has no documenation at all.
         * @see http://php.net/manual/en/mysqli-stmt.insert-id.php
         */
        elseif ($id === 0) {
            // Query didn't trigger setting an ID.
            return null;
        }
        $errors = $this->query->getErrors();
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
     * NB: Query class result mode must be 'store'.
     * @see MsSqlQuery::__construct()
     *
     * Effectively not available for prepared statement, because
     * prepared statement cannot be 'store' in this implementation.
     *
     * Go for design patterns that don't require numRows().
     * @code
     * // Alternatives - only needing row count:
     * $num_rows = count($result->fetchAll(DbResult::FETCH_NUMERIC));
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
     *      Statement result mode not 'store'.
     * @throws DbRuntimeException
     */
    public function numRows() : int
    {
        if ($this->query->resultMode != MariaDbQuery::CURSOR_STORE) {
            throw new \LogicException(
                $this->query->client->messagePrefix() . ' - result mode[' . $this->query->resultMode
                . '] forbids getting number of rows, because unreliable.'
            );
        }
        if (!$this->result && !($load = $this->loadResult())) {
            if ($load === null) {
                $errors = null;
                $cls_xcptn = DbResultException::class;
                $msg = '';
            } else {
                $errors = $this->query->getErrors();
                $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
                $msg = ', error: ' . $this->query->client->errorsToString($errors);
            }
            $this->closeAndLog(__FUNCTION__);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed getting number of rows, no result set' . $msg . '.',
                $errors && reset($errors) ? key($errors) : 0
            );
        }
        // Prepared statement is unlikely because stored mode isn't supported
        // in this implementation; but anyway.
        $count = !$this->isPreparedStatement ? @$this->result->num_rows :
            @$this->statement->num_rows;
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        $errors = $this->query->getErrors();
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
        if ($this->isPreparedStatement) {
            $count = @$this->statement->field_count;
        } else {
            $count = @$this->mySqlI->field_count;
        }
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        $errors = $this->query->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed getting number of columns, error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Get value of a single column in a single row.
     *
     * Nb: Don't call this more times for a single row;
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
     *      Result row has no such $index or $name.
     * @throws DbRuntimeException
     */
    public function fetchColumn(int $index = 0, string $name = null)
    {
        // Result set routine; don't change or move.
        ++$this->rowIndex;
        if (!$this->result && !($load = $this->loadResult())) {
            if ($load === null) {
                $errors = null;
                $cls_xcptn = DbResultException::class;
                $msg = '';
            } else {
                $errors = $this->query->getErrors();
                $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
                $msg = ', error: ' . $this->query->client->errorsToString($errors);
            }
            $this->closeAndLog(__FUNCTION__);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed fetching column by '
                . (!$name ? ('$index[' . $index . ']') : ('$name[' . $name . ']'))
                . ', no result set' . $msg . '.',
                $errors && reset($errors) ? key($errors) : 0
            );
        }

        // Column name cannot be '0' (sql illegal) so loose check suffices.
        if (!$name) {
            if ($index < 0) {
                $this->closeAndLog(__FUNCTION__);
                throw new \InvalidArgumentException(
                    $this->query->messagePrefix() . ' - failed fetching column, arg $index['
                    . $index . '] is negative.'
                );
            }
            $row = @$this->result->fetch_array(MYSQLI_NUM);
        }
        else {
            $row = @$this->result->fetch_assoc();
        }
        if ($row) {
            $length = null;
            if (!$name) {
                $length = count($row);
                if ($index < $length) {
                    return $row[$index];
                }
            } elseif (array_key_exists($name, $row)) {
                return $row[$name];
            }
            $this->closeAndLog(__FUNCTION__);
            throw new \OutOfRangeException(
                $this->query->messagePrefix() . ' - failed fetching column, row '
                . (!$name ? (' length[' .  $length . '] has no column $index[' . $index . '].') :
                    ('has no column $name[' . $name . '].')
                )
            );
        }
        elseif ($row === null) {
            return null;
        }
        $errors = $this->query->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching column by '
            . (!$name ? ('$index[' . $index . ']') : ('$name[' . $name . ']')) . ', error: '
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
     */
    public function fetchArray(int $as = DbResult::FETCH_ASSOC) /*: ?array*/
    {
        // Result set routine; don't change or move.
        ++$this->rowIndex;
        if (!$this->result && !($load = $this->loadResult())) {
            if ($load === null) {
                $errors = null;
                $cls_xcptn = DbResultException::class;
                $msg = '';
            } else {
                $errors = $this->query->getErrors();
                $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
                $msg = ', error: ' . $this->query->client->errorsToString($errors);
            }
            $this->closeAndLog(__FUNCTION__);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed fetching row as array, no result set' . $msg . '.',
                $errors && reset($errors) ? key($errors) : 0
            );
        }

        $row = $as == DbResult::FETCH_ASSOC ? @$this->result->fetch_assoc() : @$this->result->fetch_array(MYSQLI_NUM);
        if ($row || $row === null) {
            return $row;
        }
        $errors = $this->query->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix() . ' - failed fetching row as array, error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * Fetch row as column-keyed object.
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
     */
    public function fetchObject(string $class = null, array $args = null) /*: ?object*/
    {
        // Result set routine; don't change or move.
        ++$this->rowIndex;
        if (!$this->result && !($load = $this->loadResult())) {
            if ($load === null) {
                $errors = null;
                $cls_xcptn = DbResultException::class;
                $msg = '';
            } else {
                $errors = $this->query->getErrors();
                $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
                $msg = ', error: ' . $this->query->client->errorsToString($errors);
            }
            $this->closeAndLog(__FUNCTION__);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed fetching row as object, no result set' . $msg . '.',
                $errors && reset($errors) ? key($errors) : 0
            );
        }

        if ($class && !class_exists($class)) {
            $this->closeAndLog(__FUNCTION__);
            throw new \InvalidArgumentException(
                $this->query->messagePrefix() . ' - can\'t fetch row as object into non-existent class[' . $class . '].'
            );
        }
        $row = !$class || $class == \stdClass::class ? @$this->result->fetch_object() :
            @$this->result->fetch_object($class, $args ?? []);
        if ($row || $row === null) {
            return $row;
        }
        $errors = $this->query->getErrors();
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
     */
    public function fetchAllArrays(int $as = DbResult::FETCH_ASSOC, string $list_by_column = null) : array
    {
        // Result set routine; don't change or move.
        if (!$this->result && !($load = $this->loadResult())) {
            if ($load === null) {
                $errors = null;
                $cls_xcptn = DbResultException::class;
                $msg = '';
            } else {
                $errors = $this->query->getErrors();
                $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
                $msg = ', error: ' . $this->query->client->errorsToString($errors);
            }
            $this->closeAndLog(__FUNCTION__);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed fetching all rows as arrays, no result set' . $msg . '.',
                $errors && reset($errors) ? key($errors) : 0
            );
        }

        if ($as == DbResult::FETCH_NUMERIC) {
            if ($list_by_column) {
                $this->closeAndLog(__FUNCTION__);
                throw new \InvalidArgumentException(
                    $this->query->client->messagePrefix() . ' - arg $list_by_column type['
                    . Utils::getType($list_by_column) . '] must be empty when fetching all rows as numeric arrays.'
                );
            }
            $list = @$this->result->fetch_all(MYSQLI_NUM);
            if (!is_array($list)) {
                $errors = $this->query->getErrors();
                $this->closeAndLog(__FUNCTION__);
                $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
                throw new $cls_xcptn(
                    $this->query->messagePrefix() . ' - failed fetching all rows as numeric arrays, error: '
                    . $this->query->client->errorsToString($errors) . '.',
                    $errors && reset($errors) ? key($errors) : 0
                );
            }
            $this->rowIndex += count($list);
            return $list;
        }

        if (!$list_by_column) {
            $list = @$this->result->fetch_all(MYSQLI_ASSOC);
            if (!is_array($list)) {
                $errors = $this->query->getErrors();
                $this->closeAndLog(__FUNCTION__);
                $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
                throw new $cls_xcptn(
                    $this->query->messagePrefix() . ' - failed fetching all rows as associative array, error: '
                    . $this->query->client->errorsToString($errors) . '.',
                    $errors && reset($errors) ? key($errors) : 0
                );
            }
            $this->rowIndex += count($list);
            return $list;
        }
        // Array list by column.
        $list = [];
        $first = true;
        while (($row = @$this->result->fetch_assoc())) {
            ++$this->rowIndex;
            if ($first) {
                $first = false;
                if (!array_key_exists($list_by_column, $row)) {
                    $this->closeAndLog(__FUNCTION__);
                    throw new \InvalidArgumentException(
                        $this->query->messagePrefix()
                        . ' - failed fetching all rows as associative arrays listed by column[' . $list_by_column
                        . '], non-existent column.'
                    );
                }
            }
            $list[$row[$list_by_column]] = $row;
        }
        ++$this->rowIndex;
        // Last fetched row must be null; no more rows.
        if ($row !== null) {
            $errors = $this->query->getErrors();
            $this->closeAndLog(__FUNCTION__);
            $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
            throw new $cls_xcptn(
                $this->query->messagePrefix()
                . ' - failed fetching complete list of all rows as associative arrays, error: '
                . $this->query->client->errorsToString($errors) . '.',
                $errors && reset($errors) ? key($errors) : 0
            );
        }
        return $list;
    }

    /**
     * Fetch all rows into a list of column-keyed objects.
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
     */
    public function fetchAllObjects(string $class = null, string $list_by_column = null, array $args = null) : array
    {
        // Result set routine; don't change or move.
        if (!$this->result && !($load = $this->loadResult())) {
            if ($load === null) {
                $errors = null;
                $cls_xcptn = DbResultException::class;
                $msg = '';
            } else {
                $errors = $this->query->getErrors();
                $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
                $msg = ', error: ' . $this->query->client->errorsToString($errors);
            }
            $this->closeAndLog(__FUNCTION__);
            throw new $cls_xcptn(
                $this->query->messagePrefix() . ' - failed fetching all rows as objects, no result set' . $msg . '.',
                $errors && reset($errors) ? key($errors) : 0
            );
        }

        if ($class && !class_exists($class)) {
            $this->closeAndLog(__FUNCTION__);
            throw new \InvalidArgumentException(
                $this->query->messagePrefix()
                . ' - can\'t fetch all rows as objects into non-existent class[' . $class . '].'
            );
        }
        $list = [];
        $first = true;
        $custom_class = $class && $class != \stdClass::class;
        while (($row = !$custom_class ? @$this->result->fetch_object() :
            @$this->result->fetch_object($class, $args ?? [])
        )) {
            ++$this->rowIndex;
            if (!$list_by_column) {
                $list[] = $row;
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
                $list[$row->{$list_by_column}] = $row;
            }
        }
        ++$this->rowIndex;
        // Last fetched row must be null; no more rows.
        if ($row !== null) {
            $errors = $this->query->getErrors();
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
        ++$this->setIndex;
        $this->rowIndex = -1;
        if ($this->isPreparedStatement) {
            if (!@$this->statement->more_results()) {
                return false;
            }
            $cursor_moved = @$this->statement->next_result();
        } else {
            if (!@$this->mySqlI->more_results()) {
                return false;
            }
            $cursor_moved = @$this->mySqlI->next_result();
        }
        if ($cursor_moved) {
            return true;
        }
        $errors = $this->query->getErrors();
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
     * NB: effectively skips a row; consumes it.
     * MySQLi has no real means of going to next row.
     *
     * @return bool
     *      False: No next row.
     *      Throws exception on failure.
     *
     * @throws DbRuntimeException
     */
    public function nextRow() : bool
    {
        if (!$this->result && !($load = $this->loadResult())) {
            $msg = $load === null ? '' : (', error: ' . $this->query->getErrors(DbError::AS_STRING));
            $this->closeAndLog(__FUNCTION__);
            throw new DbResultException(
                $this->query->messagePrefix() . ' - failed going to next row, no result set' . $msg . '.'
            );
        }
        // There's no MySQLi direct equivalent; use lightest alternative.
        $row = $this->result->fetch_array(MYSQLI_NUM);
        if ($row || is_array($row)) {
            return true;
        }
        if ($row === null) {
            return false;
        }
        $errors = $this->query->getErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->messagePrefix()
            . ' - failed going to set[' . $this->setIndex . '] row[' . $this->rowIndex . '], error: '
            . $this->query->client->errorsToString($errors) . '.',
            $errors && reset($errors) ? key($errors) : 0
        );
    }

    /**
     * @return void
     */
    public function free() /*: void*/
    {
        if ($this->result) {
            @$this->result->free();
        }
        $this->result = null;
    }

    // Helpers.-----------------------------------------------------------------

    /**
     * NB: Caller must throw exception on return false.
     *
     * @return bool|null
     *      Null: No result set.
     *      False: Failed.
     */
    protected function loadResult()
    {
        if ($this->setIndex == -1) {
            ++$this->setIndex;
        }
        $this->rowIndex = -1;
        if ($this->isPreparedStatement) {
            /**
             * Result mode 'store' is not supported for prepared statements
             * by this implementation, because useless IMHO.
             * @see MariaDbQuery
             * @see \mysqli_stmt::store_result()
             */
            $set = @$this->statement->get_result();
            // mysqli_stmt::get_result() returns false on successful CRUD;
            // see error check further down.
        }
        else {
            if ($this->query->resultMode == MariaDbQuery::CURSOR_STORE) {
                // MySQLi::store_result() returns false on successful CRUD;
                // see error check further down.
                $set = @$this->mySqlI->store_result();
            } else {
                $set = @$this->mySqlI->use_result();
            }
        }
        if (!$set) {
            // MySQLi::store_result() returns false on successful CRUD.
            if (!$this->query->getErrors()) {
                // $this->result remains null.
                return null;
            }
            return false;
        }

        $this->result = $set;

        return true;
    }
}

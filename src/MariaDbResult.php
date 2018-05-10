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
use SimpleComplex\Database\Exception\DbQueryException;
use SimpleComplex\Database\Exception\DbResultException;

/**
 * MariaDB result.
 *
 * Both setIndex and rowIndex deliberately go out-of-bounds when first/next
 * set/row is called for and there aren't any more sets/rows.
 *
 * MySQLi's default stored procedure result handling is not used,
 * because binding result vars is useless; instead the result gets stored.
 * @see \mysqli_stmt::get_result()
 * When stored procedure, query class cursorMode is ignored.
 * @see MariaDbQuery::$cursorMode
 *
 * Properties inherited from DatabaseResult:
 * @property-read bool $setIndex
 * @property-read bool $rowIndex
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
     * @var \mysqli_result|bool|null
     *      True if successful CRUD statement.
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
     * @throws DbQueryException
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
        if ($count === -1) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbQueryException(
                $this->query->errorMessagePrefix()
                . ' - rejected counting affected rows (returned -1), the query failed.'
            );
        }
        $error = $this->query->nativeErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed counting affected rows, with error: '. $error . '.'
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
                            $this->closeAndLog(__FUNCTION__);
                            throw new \InvalidArgumentException(
                                $this->query->errorMessagePrefix()
                                . ' - arg $getAsType as string isn\'t i|d|s|b.'
                            );
                    }
                }
                else {
                    $this->closeAndLog(__FUNCTION__);
                    throw new \TypeError(
                        $this->query->errorMessagePrefix()
                        . ' - arg $getAsType type[' . gettype($getAsType) . '] isn\'t string|null.'
                    );
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
        $error = $this->query->nativeErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting insert ID, with error: ' . $error . '.'
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
     * @throws \LogicException
     *      Statement cursor mode not 'store'.
     * @throws DbResultException
     *      Propagated; failure to get/store/use result set.
     * @throws DbResultException
     */
    public function numRows() : int
    {
        if ($this->query->cursorMode != 'use') {
            throw new \LogicException(
                $this->query->client->errorMessagePrefix() . ' - cursor mode[' . $this->query->cursorMode
                . '] forbids getting number of rows.'
            );
        }
        if (!$this->result && !$this->loadResult()) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix() . ' - failed getting number of rows, no result set.'
            );
        }
        $count = @$this->result->num_rows;
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        $error = $this->query->nativeErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting number of rows, with error: ' . $error . '.'
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
        $error = $this->query->nativeErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed getting number of columns, with error: ' . $error . '.'
        );
    }

    /**
     * Get value of a single column in a row.
     *
     * Nb: Don't call this more times for a single row;
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
     *      Result row has no such $index or $column.
     * @throws DbResultException
     */
    public function fetchField(int $index = 0, string $column = '')
    {
        ++$this->rowIndex;
        if (!$this->result && !$this->loadResult()) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix() . ' - failed fetching field by '
                . (!$column ? ('$index[' . $index . ']') : ('$column[' . $column . ']')) . ', no result set.'
            );
        }
        if (!$column) {
            if ($index < 0) {
                $this->closeAndLog(__FUNCTION__);
                throw new \InvalidArgumentException(
                    $this->query->errorMessagePrefix() . ' - failed fetching field, arg $index['
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
            if (!$column) {
                $length = count($row);
                if ($index < $length) {
                    return $row[$index];
                }
            } elseif (array_key_exists($column, $row)) {
                return $row[$column];
            }
            $this->closeAndLog(__FUNCTION__);
            throw new \OutOfRangeException(
                $this->query->errorMessagePrefix() . ' - failed fetching field, row '
                . (!$column ? (' length[' .  $length . '] has no $index[' . $index . '].') :
                    ('has no $column[' . $column . '].')
                )
            );
        }
        elseif ($row === null) {
            return null;
        }
        $error = $this->query->nativeErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed fetching field by '
            . (!$column ? ('$index[' . $index . ']') : ('$column[' . $column . ']')) . ', with error: ' . $error . '.'
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
        ++$this->rowIndex;
        if (!$this->result && !$this->loadResult()) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix() . ' - failed fetching row as array, no result set.'
            );
        }
        $row = $as == Database::FETCH_ASSOC ? @$this->result->fetch_assoc() : @$this->result->fetch_array(MYSQLI_NUM);
        if ($row || $row === null) {
            return $row;
        }
        $error = $this->query->nativeErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix() . ' - failed fetching row as array, with error: ' . $error . '.'
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
        ++$this->rowIndex;
        if (!$this->result && !$this->loadResult()) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix() . ' - failed fetching row as object, no result set.'
            );
        }
        $row = @$this->result->fetch_object($class, $args);
        if ($row || $row === null) {
            return $row;
        }
        $error = $this->query->nativeErrors(Database::ERRORS_STRING);
        $this->closeAndLog(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed fetching row as object, with error: ' . $error . '.'
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
     * @throws \LogicException
     *      Providing 'list_by_column' option when fetching as numeric array.
     * @throws \InvalidArgumentException
     *      Providing 'list_by_column' option and no such column in result row.
     * @throws DbResultException
     */
    public function fetchAll(int $as = Database::FETCH_ASSOC, array $options = []) : array
    {
        if (!$this->result && !$this->loadResult()) {
            $this->closeAndLog(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix() . ' - failed fetching all rows, no result set.'
            );
        }
        $column_keyed = !empty($options['list_by_column']);
        switch ($as) {
            case Database::FETCH_NUMERIC:
                if ($column_keyed) {
                    $this->closeAndLog(__FUNCTION__);
                    throw new \LogicException(
                        $this->query->client->errorMessagePrefix()
                        . ' - arg $options \'list_by_column\' is not supported when fetching as numeric arrays.'
                    );
                }
                $list = @$this->result->fetch_all(MYSQLI_NUM);
                if (!is_array($list)) {
                    $error = $this->query->nativeErrors(Database::ERRORS_STRING);
                    $this->closeAndLog(__FUNCTION__);
                    throw new DbResultException(
                        $this->query->errorMessagePrefix()
                        . ' - failed fetching all rows as numeric array, with error: ' . $error . '.'
                    );
                }
                $this->rowIndex += count($list);
                return $list;
            case Database::FETCH_OBJECT:
                $key_column = !$column_keyed ? null : $options['list_by_column'];
                $list = [];
                $first = true;
                while (
                    ($row = @$this->result->fetch_object($options['class'] ?? '', $options['args'] ?? []))
                ) {
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
                ++$this->rowIndex;
                break;
            default:
                if (!$column_keyed) {
                    $list = @$this->result->fetch_all(MYSQLI_ASSOC);
                    if (!is_array($list)) {
                        $error = $this->query->nativeErrors(Database::ERRORS_STRING);
                        $this->closeAndLog(__FUNCTION__);
                        throw new DbResultException(
                            $this->query->errorMessagePrefix()
                            . ' - failed fetching all rows as assoc array, with error: ' . $error . '.'
                        );
                    }
                    $this->rowIndex += count($list);
                    return $list;
                }
                $key_column = !$column_keyed ? null : $options['list_by_column'];
                $list = [];
                $first = true;
                while (($row = @$this->result->fetch_assoc())) {
                    ++$this->rowIndex;
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
                ++$this->rowIndex;
        }
        // Last fetched row must be null; no more rows.
        if ($row !== null) {
            $error = $this->query->nativeErrors(Database::ERRORS_STRING);
            $this->closeAndLog(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix()
                . ' - failed fetching all rows as ' . ($as == Database::FETCH_OBJECT ? 'object' : 'assoc array')
                . ', with error: ' . $error . '.'
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
     * @throws DbRuntimeException
     */
    public function nextSet(bool $noException = false)
    {
        $this->result = null;
        ++$this->setIndex;
        $this->rowIndex = -1;
        if ($this->isPreparedStatement) {
            if (!@$this->statement->more_results()) {
                return null;
            }
            $next = @$this->statement->next_result();
        } else {
            if (!@$this->mySqlI->more_results()) {
                return null;
            }
            $next = @$this->mySqlI->next_result();
        }
        if ($next) {
            $this->free();
            $this->result = $next;
            return $next;
        }
        if ($noException) {
            return false;
        }
        $errors = $this->query->nativeErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->errorMessagePrefix()
            . ' - failed going to set[' . $this->setIndex . '], with error: '
            . $this->query->client->nativeErrorsToString($errors) . '.'
        );
    }

    /**
     * Go to next row in the result set.
     *
     * NB: effectively skips a row;
     * MySQLi has no real means of going to next row.
     *
     * @param bool $noException
     *      True: return false on failure, don't throw exception.
     *
     * @return bool|null
     *      Null: No next result set.
     */
    public function nextRow(bool $noException = false)
    {
        if (!$this->result) {
            $this->loadResult();
        }
        // There's no MySQLi direct equivalent; use lightest alternative.
        $this->rowIndex = -1;
        $row = $this->result->fetch_array(MYSQLI_NUM);
        if ($row || is_array($row)) {
            return true;
        }
        if ($row === null) {
            null;
        }
        if ($noException) {
            return false;
        }
        $errors = $this->query->nativeErrors();
        $this->closeAndLog(__FUNCTION__);
        $cls_xcptn = $this->query->client->errorsToException($errors, DbResultException::class);
        throw new $cls_xcptn(
            $this->query->errorMessagePrefix()
            . ' - failed going to set[' . $this->setIndex . '] row[' . $this->rowIndex . '], with error: '
            . $this->query->client->nativeErrorsToString($errors) . '.'
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
    }

    // Helpers.-----------------------------------------------------------------

    /**
     * @return bool
     *      False: no result set and no native error recorded.
     *
     * @throws DbResultException
     */
    protected function loadResult() : bool
    {
        if (!$this->result) {
            ++$this->setIndex;
            $this->rowIndex = -1;
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
                $errors = $this->query->nativeErrors();
                if (!$errors) {
                    // $this->result stays null.
                    return false;
                }
                $this->closeAndLog(__FUNCTION__);
                throw new DbResultException(
                    $this->query->errorMessagePrefix() . ' - failed getting result, with error: '
                    . $this->query->client->nativeErrorsToString($errors) . '.'
                );
            }
            $this->result = $result;
        }
        return true;
    }
}

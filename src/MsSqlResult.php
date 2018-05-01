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
use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbResultException;

/**
 * MS SQL result.
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
            // Unset prepared statement arguments reference.
            $this->query->unsetReferences();
            throw new DbRuntimeException(
                $this->query->client->errorMessagePrefix()
                . ' - can\'t initialize result because arg $statement is not (no longer?) a resource.'
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
     * @throws DbLogicalException
     *      No count, (probably) not a CRUD query.
     *      Bad query class cursor mode.
     * @throws DbResultException
     */
    public function affectedRows() : int
    {
        $count = @sqlsrv_rows_affected(
            $this->statement
        );
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->query->log(__FUNCTION__);
        if ($count === -1) {
            throw new DbLogicalException(
                $this->query->errorMessagePrefix()
                . ' - rejected counting affected rows (returned -1), probably not a CRUD query.'
            );
        }
        // Cursor mode must be SQLSRV_CURSOR_FORWARD ('forward').
        if ($this->query->cursorMode != SQLSRV_CURSOR_FORWARD) {
            throw new DbLogicalException(
                $this->query->client->errorMessagePrefix() . ' - cursor mode[' . $this->query->cursorMode
                . '] forbids getting affected rows.'
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
     * NB: Requires that the sql contains a secondary ID selecting statement
     * ; SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME
     * Use query class option 'get_insert_id'.
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
     * @throws DbLogicalException
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
        // Fetch first row, unless already done.
        if ($this->rowIndex < 0) {
            $next = @sqlsrv_next_result($this->statement);
            if (!$next) {
                $this->query->close();
                $this->query->log(__FUNCTION__);
                if ($next === null) {
                    // No result at all.
                    throw new DbResultException(
                        $this->query->errorMessagePrefix()
                        . ' - failed going to next set to get insert ID, no result at all.'
                    );
                }
                throw new DbResultException(
                    $this->query->errorMessagePrefix() . ' - failed going to next set to get insert ID, with error: '
                    . $this->query->client->nativeError() . '.'
                );
            }
            else {
                $next = @sqlsrv_fetch($this->statement);
                if (!$next) {
                    if ($next === null) {
                        // No row at all because rowIndex was -1.
                        throw new DbResultException(
                            $this->query->errorMessagePrefix()
                            . ' - failed going to next set to get insert ID, no result row at all.'
                        );
                    }
                    $this->query->close();
                    $this->query->log(__FUNCTION__);
                    throw new DbResultException(
                        $this->query->errorMessagePrefix() . ' - failed going to next row to get insert ID, with error: '
                        . $this->query->client->nativeError() . '.'
                    );
                }
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
                        // Unset prepared statement arguments reference.
                        $this->query->close();
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
                $this->query->log(__FUNCTION__);
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
            // Null: query didn't trigger setting an ID.
            return $id;
        }
        // $id == false.
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->query->log(__FUNCTION__);
        if (
            !$this->query->getInsertId
            && strpos(
                $this->query->sqlTampered ?? $this->query->sql,
                'SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME'
            ) === false
        ) {
            throw new DbLogicalException(
                $this->query->errorMessagePrefix() . ' - failed getting insert ID'
                . ', sql misses secondary ID select statement, see query option \'get_insert_id\''
            );
        }
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed getting insert ID, with error: ' . $this->query->client->nativeError() . '.'
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
     * @throws DbLogicalException
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
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->query->log(__FUNCTION__);
        switch ($this->query->cursorMode) {
            case SQLSRV_CURSOR_STATIC:
            case SQLSRV_CURSOR_KEYSET:
                break;
            default:
                throw new DbLogicalException(
                    $this->query->client->errorMessagePrefix() . ' - cursor mode[' . $this->query->cursorMode
                    . '] forbids getting number of rows.'
                );
        }
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
        $count = @sqlsrv_num_fields(
            $this->statement
        );
        if (($count && $count > 0) || $count === 0) {
            return $count;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
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
     */
    public function fetchArray(int $as = Database::FETCH_ASSOC)
    {
        $row = @sqlsrv_fetch_array(
            $this->statement,
            $as == Database::FETCH_ASSOC ? SQLSRV_FETCH_ASSOC : SQLSRV_FETCH_NUMERIC
        );
        ++$this->rowIndex;
        if ($row || $row === null) {
            return $row;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
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
     */
    public function fetchObject(string $class = '', array $args = [])
    {
        $row = @sqlsrv_fetch_object($this->statement, $class, $args);
        if ($row || $row === null) {
            return $row;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
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
     * @throws DbLogicalException
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
                    // Unset prepared statement arguments reference.
                    $this->query->close();
                    $this->query->log(__FUNCTION__);
                    throw new DbLogicalException(
                        $this->query->client->errorMessagePrefix()
                        . ' - arg $options \'list_by_column\' is not supported when fetching as numeric arrays.'
                    );
                }
                while (($row = @sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_NUMERIC))) {
                    $list[] = $row;
                }
                break;
            case Database::FETCH_OBJECT:
                $key_column = !$column_keyed ? null : $options['list_by_column'];
                $first = true;
                while (
                    ($row = @sqlsrv_fetch_object($this->statement, $options['class'] ?? '', $options['args'] ?? []))
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
                $key_column = !$column_keyed ? null : $options['list_by_column'];
                $first = true;
                while (($row = @sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_ASSOC))) {
                    if (!$column_keyed) {
                        $list[] = $row;
                    }
                    else {
                        if ($first) {
                            $first = false;
                            if (!array_key_exists($key_column, $row)) {
                                // Unset prepared statement arguments reference.
                                $this->query->close();
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
            // Unset prepared statement arguments reference.
            $this->query->close();
            $this->query->log(__FUNCTION__);
            throw new DbResultException(
                $this->query->errorMessagePrefix()
                . ' - failed fetching all rows as ' . $em . ', with error: '
                . $this->query->client->nativeError() . '.'
            );
        }
        return $list;
    }

    /**
     * @return bool|null
     *      Null: No next result set.
     *      Throws throwable on failure.
     */
    public function nextSet()
    {
        $next = @sqlsrv_next_result($this->statement);
        if ($next) {
            ++$this->setIndex;
            $this->rowIndex = -1;
            return $next;
        }
        if ($next === null) {
            return null;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed going to next set, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * @return bool|null
     *      Null: No next row.
     *      Throws throwable on failure.
     */
    public function nextRow()
    {
        $next = @sqlsrv_fetch($this->statement);
        if ($next) {
            ++$this->rowIndex;
            return $next;
        }
        if ($next === null) {
            null;
        }
        // Unset prepared statement arguments reference.
        $this->query->close();
        $this->query->log(__FUNCTION__);
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed going to next row, with error: '
            . $this->query->client->nativeError() . '.'
        );
    }

    /**
     * Does nothing, because freeing result would also close the statement
     * since they share the same resource.
     * @see MsSqlQuery::close()
     *
     * @return void
     */
    public function free() /*:void*/
    {
    }
}

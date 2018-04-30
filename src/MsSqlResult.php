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
     * @param resource $statement
     *
     * @throws DbRuntimeException
     *      Arg statement not (no longer?) resource.
     */
    public function __construct(DbQueryInterface $query, $statement)
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
     * @return int
     *
     * @throws DbResultException
     */
    public function affectedRows() : int
    {
        $count = @sqlsrv_rows_affected(
            $this->statement
        );
        if (!$count) {
            if ($count === 0) {
                return 0;
            }
            // Unset prepared statement arguments reference.
            $this->query->closeStatement();

            $error = $this->query->client->nativeError();
            // @todo: cursor mode must be 'forward';

            $this->query->client->log(
                $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
                substr($this->query->queryTampered ?? $this->query->query, 0,
                    constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
            );
            throw new DbResultException(
                $this->query->errorMessagePrefix() . ' - failed counting affected rows, with error: '
                . $this->query->client->nativeError() . '.'
            );
        }
        if ($count > -1) {
            return $count;
        }
        $error = $this->query->client->nativeError(true);
        // Unset prepared statement arguments reference.
        $this->query->closeStatement();
        $this->query->client->log(
            $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
            substr($this->query->queryTampered ?? $this->query->query, 0,
                constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
        );
        throw new DbLogicalException(
            $this->query->errorMessagePrefix()
            . ' - rejected counting affected rows, probably not a CRUD query'
            . (!$error ? '' : (', with error: ' . $error)) . '.'
        );
    }

    /**
     * @param int|string|null $getAsType
     *
     * @return mixed|null
     *      Null: no result at all.
     */
    public function insertId($getAsType = null)
    {


        /**
         * @todo: insertId()
         * ; SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME
         * @see https://blogs.msdn.microsoft.com/nickhodge/2008/09/22/sql-server-driver-for-php-last-inserted-row-id/
         * @see https://docs.microsoft.com/en-us/sql/t-sql/functions/scope-identity-transact-sql?view=sql-server-2017
         */

        // @todo?
        //sqlsrv_next_result()

        // Fetch first row, unless already done.
        if ($this->rowIndex < 0) {
            @sqlsrv_next_result($this->statement);
            $next = @sqlsrv_fetch($this->statement);
            /*if (!$next) {
                if ($next === null) {
                    // No row at all because rowIndex was -1.
                    return null;
                }
                // Unset prepared statement arguments reference.
                $this->query->closeStatement();
                $this->query->client->log(
                    $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
                    substr($this->query->queryTampered ?? $this->query->query, 0,
                        constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
                );
                throw new DbLogicalException(
                    $this->query->errorMessagePrefix() . ' - failed getting insert ID, with error: '
                    . $this->query->client->nativeError() . '.'
                );
            }*/
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
                    case 'Datetime':
                        $type = SQLSRV_PHPTYPE_DATETIME;
                        break;
                    default:
                        throw new \InvalidArgumentException(
                            $this->query->errorMessagePrefix()
                            . ' - arg $getAsType type[' . gettype($getAsType) . '] isn\'t ?.'
                        );
                }
            }
            else {
                throw new \InvalidArgumentException(
                    $this->query->errorMessagePrefix()
                    . ' - arg $getAsType type[' . gettype($getAsType) . '] isn\'t integer|string|null.'
                );
            }
            $id = @sqlsrv_get_field($this->statement, 0, $type);
        } else {
            $id = @sqlsrv_get_field($this->statement, 0);
        }
        if ($id === false) {
            // Unset prepared statement arguments reference.
            $this->query->closeStatement();
            $this->query->client->log(
                $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
                substr($this->query->queryTampered ?? $this->query->query, 0,
                    constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
            );
            $error = $this->query->client->nativeError(true);
            if (
                !$error
                && !$this->query->getInsertId
                && strpos(
                    $this->query->queryTampered ?? $this->query->query,
                    'SELECT SCOPE_IDENTITY() AS IDENTITY_COLUMN_NAME'
                ) === false
            ) {
                $error = 'statement misses secondary ID select query, see query option \'get_insert_id\'';
            } elseif (!$error) {
                $error = $this->query->client->nativeError();
            }
            throw new DbLogicalException(
                $this->query->errorMessagePrefix() . ' - failed getting insert ID, with error: ' . $error . '.'
            );
        }
        return $id;
    }

    /**
     * Number of rows in a result set.
     *
     * @return int
     *
     * @throws DbLogicalException
     *      Statement cursor mode not 'static' or 'keyset'.
     * @throws DbResultException
     */
    public function numRows() : int
    {
        switch ($this->query->cursorMode) {
            case SQLSRV_CURSOR_STATIC:
            case SQLSRV_CURSOR_KEYSET:
                break;
            default:
                // Unset prepared statement arguments reference.
                $this->query->closeStatement();
                throw new DbLogicalException(
                    $this->query->client->errorMessagePrefix() . ' - cursor mode[' . $this->query->cursorMode
                    . '] forbids getting number of rows.'
                );
        }
        $count = @sqlsrv_num_rows(
            $this->statement
        );
        if (!$count && $count !== 0) {
            // Unset prepared statement arguments reference.
            $this->query->closeStatement();
            $this->query->client->log(
                $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
                substr($this->query->queryTampered ?? $this->query->query, 0,
                    constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
            );
            throw new DbResultException(
                $this->query->errorMessagePrefix() . ' - failed getting number of rows, with error: '
                . $this->query->client->nativeError() . '.'
            );

        }
        return $count;
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
        if (!$count && $count !== 0) {
            // Unset prepared statement arguments reference.
            $this->query->closeStatement();
            $this->query->client->log(
                $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
                substr($this->query->queryTampered ?? $this->query->query, 0,
                    constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
            );
            throw new DbResultException(
                $this->query->errorMessagePrefix() . ' - failed getting number of columns, with error: '
                . $this->query->client->nativeError() . '.'
            );

        }
        return $count;
    }

    /**
     * Associative (column-keyed) or numerically indexed array.
     *
     * @param int $as
     *      Default: column-keyed.
     *
     * @return array|null
     *      No more rows.
     */
    public function fetchArray(int $as = Database::FETCH_ASSOC)
    {
        $row = @sqlsrv_fetch_array(
            $this->statement,
            $as == Database::FETCH_ASSOC ? SQLSRV_FETCH_ASSOC : SQLSRV_FETCH_NUMERIC
        );
        if ($row) {
            return $row;
        }
        if ($row === null) {
            return null;
        }
        // Unset prepared statement arguments reference.
        $this->query->closeStatement();
        $this->query->client->log(
            $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
            substr($this->query->queryTampered ?? $this->query->query, 0,
                constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
        );
        throw new DbResultException(
            $this->query->errorMessagePrefix()
            . ' - failed fetching row as ' . (Database::FETCH_NUMERIC ? 'numeric' : 'assoc') . ' array, with error: '
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
     *      No more rows.
     */
    public function fetchObject(string $class = '', array $args = [])
    {
        $row = @sqlsrv_fetch_object($this->statement, $class, $args);
        if ($row) {
            return $row;
        }
        if ($row === null) {
            return null;
        }
        // Unset prepared statement arguments reference.
        $this->query->closeStatement();
        $this->query->client->log(
            $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
            substr($this->query->queryTampered ?? $this->query->query, 0,
                constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
        );
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
        $key_column = !$column_keyed ? null : $options['list_by_column'];
        $list = [];
        $first = true;
        switch ($as) {
            case Database::FETCH_NUMERIC:
                if ($column_keyed) {
                    // Unset prepared statement arguments reference.
                    $this->query->closeStatement();
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
                                $this->query->closeStatement();
                                $this->query->client->log(
                                    $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
                                    substr($this->query->queryTampered ?? $this->query->query, 0,
                                        constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
                                );
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
                while (($row = @sqlsrv_fetch_array($this->statement, SQLSRV_FETCH_ASSOC))) {
                    if (!$column_keyed) {
                        $list[] = $row;
                    }
                    else {
                        if ($first) {
                            $first = false;
                            if (!array_key_exists($key_column, $row)) {
                                // Unset prepared statement arguments reference.
                                $this->query->closeStatement();
                                $this->query->client->log(
                                    $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
                                    substr($this->query->queryTampered ?? $this->query->query, 0,
                                        constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
                                );
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
            $this->query->closeStatement();
            $this->query->client->log(
                $this->query->errorMessagePrefix() . ' - ' . __FUNCTION__ . '(), query',
                substr($this->query->queryTampered ?? $this->query->query, 0,
                    constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE'))
            );
            throw new DbResultException(
                $this->query->errorMessagePrefix()
                . ' - failed fetching all rows as ' . $em . ', with error: '
                . $this->query->client->nativeError() . '.'
            );
        }
        return $list;
    }
}

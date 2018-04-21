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
use SimpleComplex\Database\Interfaces\DbResultInterface;

use SimpleComplex\Database\Exception\DbLogicalException;
use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbInterruptionException;
use SimpleComplex\Database\Exception\DbQueryException;

/**
 * MS SQL query.
 *
 * @property-read string $query
 * @property-read bool $isPreparedStatement
 * @property-read bool $isMultiQuery
 * @property-read bool $isRepeatStatement
 * @property-read bool $queryAppended
 * @property-read bool $hasLikeClause
 *
 * @package SimpleComplex\Database
 */
class MsSqlQuery extends AbstractDbQuery
{
    /**
     * MS SQL (Sqlsrv) does not support multi-query.
     *
     * @var bool
     */
    const MULTI_QUERY_SUPPORT = false;

    /**
     * Class name of \SimpleComplex\Database\MsSqlResult or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_RESULT = \Package\Library\CustomMsSqlResult::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MsSqlResult
     *
     * @var string
     */
    const CLASS_RESULT = MsSqlResult::class;

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var MsSqlClient
     */
    public $client;

    /**
     * @var resource
     */
    protected $simpleStatement;

    /**
     * @var resource
     */
    protected $preparedStatement;

    /**
     * @var string
     */
    protected $preparedStatementTypes;

    public function __destruct()
    {
        if ($this->preparedStatement) {
            @sqlsrv_free_stmt($this->preparedStatement);
        }
    }

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * Preferable all $arguments are type qualifying arrays.
     * Secures safer behaviour and far quicker execution.
     *
     * Otherwise - literal argument value - the only types are
     * integer, float, string (and binary, if non-empty arg $types).
     *
     * Type qualifying argument
     * ------------------------
     * Must have numerical and consecutive keys, starting with zero.
     * Qualifies:
     * - 'in' and/or 'out' nature of the argument
     * - SQLSRV_SQLTYPE_* if 'in' parameter
     * Array structure:
     * - 0: (mixed) value
     * - 1: (int|null) SQLSRV_PARAM_IN|SQLSRV_PARAM_INOUT|null; null ~ SQLSRV_PARAM_IN
     * - 2: (int|null) SQLSRV_PHPTYPE_*; out type
     * - 3: (int|null) SQLSRV_SQLTYPE_*; in type
     *
     * @see http://php.net/manual/en/function.sqlsrv-prepare.php
     *
     * @param string $types
     *      Empty: uses string for all.
     *      Ignored if all $arguments are type qualifying arrays.
     * @param array &$arguments
     *      By reference.
     * @param array $options {
     *      @var int $QueryTimeout
     *      @var bool $SendStreamParamsAtExec
     *      @var bool $Scrollable  May break this library's modus of operation.
     * }
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws DbLogicalException
     *      Method called more than once for this query.
     * @throws DbRuntimeException
     *      Failure to bind $arguments to native layer.
     */
    public function prepareStatement(string $types, array &$arguments, array $options = []) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' - query cannot prepare statement more than once.'
            );
        }

        $fragments = explode('?', $this->query);
        $n_params = count($fragments) - 1;
        unset($fragments);
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' - arg $arguments length[' . $n_args
                . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
            );
        }

        if (!$n_params) {
            $this->preparedStatementArgs = [];
        }
        else {
            // Use arg $arguments directly if all args have type flags.
            // Otherwise build new arguments list, securing type flags.

            $args_typed = true;
            /**
             * Type qualifying array argument:
             * - 0: (mixed) value
             * - 1: (int|null) SQLSRV_PARAM_IN|SQLSRV_PARAM_INOUT|null; null ~ SQLSRV_PARAM_IN
             * - 2: (int|null) SQLSRV_PHPTYPE_*; out type
             * - 3: (int|null) SQLSRV_SQLTYPE_*; in type
             * @see http://php.net/manual/en/function.sqlsrv-prepare.php
             */
            $i = -1;
            foreach ($arguments as $arg) {
                ++$i;
                if (!is_array($arg)) {
                    // Argumment is arg value only.
                    $args_typed = false;
                    break;
                }
                $count = count($arg);
                if (!$count) {
                    throw new \InvalidArgumentException(
                        $this->client->errorMessagePreamble() . ' - arg $arguments bucket ' . $i . ' is empty array.'
                    );
                }
                // An 'in' parameter must have 4th bucket,
                // containing SQLSRV_SQLTYPE_* constant.
                if (
                    $count == 1
                    || ($arg[1] != SQLSRV_PARAM_OUT && ($count < 4 || !$arg[3]))
                ) {
                    $args_typed = false;
                    break;
                }
            }

            if ($args_typed) {
                $this->preparedStatementArgs =& $arguments;
            }
            else {
                // Use arg $types to establish types.
                $tps = $types;
                if ($tps === '') {
                    // Be friendly, all strings.
                    $tps = str_repeat('s', $n_params);
                }
                elseif (strlen($types) != $n_params) {
                    throw new \InvalidArgumentException(
                        $this->client->errorMessagePreamble() . ' - arg $types length[' . strlen($types)
                        . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
                    );
                }
                elseif (($type_illegals = $this->parameterTypesCheck($types))) {
                    throw new \InvalidArgumentException(
                        $this->client->errorMessagePreamble()
                        . ' - arg $types contains illegal char(s) ' . $type_illegals . '.'
                    );
                }

                $this->preparedStatementArgs = [];
                $i = -1;
                foreach ($arguments as $arg) {
                    ++$i;
                    if (!is_array($arg)) {
                        // Argumment is arg value only.
                        $this->preparedStatementArgs[] = [
                            &$arg,
                            SQLSRV_PARAM_IN,
                            null,
                            $this->nativeType($arg, $tps[$i])
                        ];
                    }
                    else {
                        // Expect numerical and consecutive keys,
                        // starting with zero.
                        // And don't check, too costly performance-wise.
                        $count = count($arg);
                        if ($count > 3) {
                            $this->preparedStatementArgs[] = [
                                &$arg[0],
                                $arg[1],
                                $arg[2],
                                $arg[3] ?? ($arg[1] == SQLSRV_PARAM_OUT ? null : $this->nativeType($arg, $tps[$i]))
                            ];
                        }
                        else {
                            $this->preparedStatementArgs[] = [
                                &$arg[0],
                                $count > 1 ? $arg[1] : null,
                                $count > 2 ? $arg[2] : null,
                                $count > 1 && $arg[1] == SQLSRV_PARAM_OUT ? null : $this->nativeType($arg, $tps[$i])
                            ];
                        }
                    }
                }
            }
        }

        // Allow re-connection.
        $connection = $this->client->getConnection(true);

        /** @var resource $statement */
        $statement = @sqlsrv_prepare($connection, $this->query, $this->preparedStatementArgs, $options);
        if (!$statement) {
            unset($this->preparedStatementArgs);
            throw new DbRuntimeException(
                $this->client->errorMessagePreamble()
                . ' - query failed to prepare statement and bind parameters, with error: '
                . $this->client->nativeError() . '.'
            );
        }
        $this->preparedStatement = $statement;
        $this->isPreparedStatement = true;

        return $this;
    }

    /**
     * Any query must be executed, even non-prepared statement.
     *
     * @return DbResultInterface|MsSqlResult
     *
     * @throws DbLogicalException
     *      Method called without previous call to prepareStatement().
     * @throws DbQueryException
     */
    public function execute(): DbResultInterface
    {
        if ($this->isPreparedStatement) {
            // Require unbroken connection.
            if (!$this->client->isConnected()) {
                unset($this->preparedStatementArgs);
                throw new DbInterruptionException(
                    $this->client->errorMessagePreamble()
                    . ' - query can\'t execute prepared statement when connection lost.'
                );
            }
            // bool.
            if (!@sqlsrv_execute($this->preparedStatement)) {
                unset($this->preparedStatementArgs);
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' - failed executing prepared statement, query',
                    $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' - failed executing prepared statement, with error: ' . $this->client->nativeError() . '.'
                );
            }
        }
        else {
            // Allow re-connection.
            /** @var \MySQLi $mysqli */
            $connection = $this->client->getConnection(true);
            /** @var resource|bool $simple_statement */
            $simple_statement = @sqlsrv_query($connection, $this->queryWithArguments ?? $this->query);
            if (!$simple_statement) {
                $this->client->log(
                    'warning',
                    $this->client->errorMessagePreamble() . ' - failed executing simple query, query',
                    $this->queryWithArguments ?? $this->query
                );
                throw new DbQueryException(
                    $this->client->errorMessagePreamble()
                    . ' - failed executing simple query, with error: ' . $this->client->nativeError() . '.'
                );
            }
            $this->simpleStatement = $simple_statement;
        }

        $class_result = static::CLASS_RESULT;
        /** @var DbResultInterface|MsSqlResult */
        return new $class_result();
    }

    /**
     * @return void
     */
    public function closePreparedStatement()
    {
        unset($this->preparedStatementArgs);
        if (!$this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' - query isn\'t a prepared statement.'
            );
        }
        if ($this->client->isConnected() && $this->preparedStatement) {
            @sqlsrv_free_stmt($this->preparedStatement);
            unset($this->preparedStatement);
        }
    }


    //  Helpers.----------------------------------------------------------------

    /**
     * Get native SQLSRV_SQLTYPE_* constant equivalent of arg $value type.
     *
     * Checks that non-empty $typeChar matches $value type.
     *
     * Cannot detect binary unless non-empty $typeChar (b).
     *
     * SQLTYPE Constants:
     * @see https://docs.microsoft.com/en-us/sql/connect/php/constants-microsoft-drivers-for-php-for-sql-server
     *
     * Integers:
     * @see https://docs.microsoft.com/en-us/sql/t-sql/data-types/int-bigint-smallint-and-tinyint-transact-sql
     *
     * @param integer|float|string $value
     * @param string $typeChar
     *      Values: i, d, s, b.
     *
     * @return int
     *      SQLSRV_SQLTYPE_* constant.
     *
     * @throws \InvalidArgumentException
     *      Non-empty arg $typeChar isn't one of i, d, s, b.
     * @throws \RuntimeException
     *      Non-empty arg $typeChar doesn't arg $value type.
     */
    public function nativeType($value, string $typeChar = '')
    {
        if ($typeChar) {
            switch ($typeChar) {
                case 'i':
                    if (!is_int($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    // Integer; continue to end of method body.
                    break;
                case 'd':
                    if (!is_float($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    return SQLSRV_SQLTYPE_FLOAT;
                case 's':
                    if (!is_string($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    return SQLSRV_SQLTYPE_VARCHAR(strlen($value));
                case 'b':
                    if (!is_string($value)) {
                        throw new \RuntimeException(
                            'Arg $typeChar value[' . $typeChar
                            . '] doesn\'t match arg $value type[' . gettype($value) . '].'
                        );
                    }
                    return SQLSRV_SQLTYPE_VARBINARY(strlen($value));
                default:
                    throw new \InvalidArgumentException(
                        'Arg $typeChar value[' . $typeChar . '] is not one of i, d, s, b.'
                    );
            }
        }
        else {
            if ($value === '') {
                return SQLSRV_SQLTYPE_VARCHAR(0);
            }

            $type = gettype($value);
            switch ($type) {
                case 'string':
                    // Cannot discern binary from string.
                    return SQLSRV_SQLTYPE_VARCHAR(strlen($value));
                case 'integer':
                    // Integer; continue to end of method body.
                    break;
                case 'double':
                case 'float':
                    return SQLSRV_SQLTYPE_FLOAT;
                default:
                    throw new \InvalidArgumentException(
                        'Arg $value type[' . $type . '] is not integer|float|string.'
                    );
            }
        }

        if ($value >= 0 && $value <= 255) {
            return SQLSRV_SQLTYPE_TINYINT;
        }
        if ($value >= -32768 && $value <= 32767) {
            return SQLSRV_SQLTYPE_SMALLINT;
        }
        if ($value >= -2147483648 && $value <= 2147483647) {
            return SQLSRV_SQLTYPE_INT;
        }
        return SQLSRV_SQLTYPE_BIGINT;
    }
}

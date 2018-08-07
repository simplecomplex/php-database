<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Utils\Explorable;
use SimpleComplex\Utils\Dependency;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;
use SimpleComplex\Database\Exception\DbConnectionException;
use SimpleComplex\Database\Exception\DbQueryException;
use SimpleComplex\Database\Exception\DbResultException;

/**
 * Database client.
 *
 *
 * Calls to PHP native methods/properties must be supressed with @ across
 * client, query, result implementations to prevent dupe messages in logs.
 *
 *
 * @property-read string $type
 * @property-read string $name
 * @property-read string $host
 * @property-read int $port
 * @property-read string $database
 * @property-read string $user
 * @property-read array $options
 * @property-read array $optionsResolved
 * @property-read string $characterSet
 * @property-read bool $transactionStarted
 * @property-read int $timesConnected
 * @property-read bool $reConnect
 *
 * @package SimpleComplex\Database
 */
abstract class DbClient extends Explorable implements DbClientInterface
{
    /**
     * Class name of DbQueryInterface class.
     *
     * @see MariaDbclient::CLASS_QUERY
     *
     * @var string
     */
    const CLASS_QUERY = '';

    /**
     * Class name of DatabaseErrorCodes class.
     *
     * @see DbClient::errorsToException()
     * @see MsSqlError
     * @see MsSqlError
     *
     * @var string
     */
    const CLASS_ERROR_CODES = DbError::class;

    /**
     * @var int|string
     */
    const ERROR_CODE_CONNECT = 1;

    /**
     * PSR-4 LogLevel.
     *
     * @var string
     */
    const LOG_LEVEL = 'warning';

    /**
     * Default database server port.
     *
     * @var int
     */
    const SERVER_PORT = 0;

    /**
     * Default connection character set.
     *
     * @var string
     */
    const CHARACTER_SET = 'UTF-8';

    /**
     * Database info buckets supported.
     *
     * Value true means required.
     *
     * Buckets:
     * - (str) host: 'domain.tld'
     * - (int|str) port: optional, defaults to SERVER_PORT
     * - (str) database: database name
     * - (str) user: 'xyz123'
     * - (str) pass: '∙∙∙∙∙∙∙∙∙∙∙∙'
     *
     * @see DbClient::__construct()
     *
     * @var string[]
     */
    const DATABASE_INFO = [
        // string.
        'host' => true,
        // int|string.
        'port' => false,
        // string.
        'database' => true,
        // string.
        'user' => true,
        // string.
        'pass' => true,
        // array.
        'options' => false,
    ];

    /**
     * Default connection timeout.
     *
     * @var int
     */
    const CONNECT_TIMEOUT = 5;

    /**
     * Shorthand name to native option name.
     *
     * @var string[]
     */
    const OPTION_SHORTHANDS = [];

    /**
     * Native errors string delimiter between instances.
     *
     * @see MariaDbClient::getErrors()
     * @see MariaDbQuery:getErrors()
     * @see MsSqlClient::getErrors()
     */
    const NATIVE_ERRORS_DELIM = ' | ';

    /**
     * Like mariadb|mssql.
     *
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var string
     */
    protected $database;

    /**
     * @var string
     */
    protected $user;

    /**
     * @var string
     */
    protected $pass;

    /**
     * Constructor arg $options.
     *
     * @var array
     */
    protected $options;

    /**
     * Final connection options, based on constructor arg $options.
     *
     * @var array
     */
    protected $optionsResolved;

    /**
     * Final character set, in driver native format (UTF-8/utf8).
     *
     * @see DbClient::characterSetResolve()
     *
     * @var string
     */
    protected $characterSet;

    /**
     * @var bool
     */
    protected $transactionStarted = false;

    /**
     * @var int
     */
    protected $timesConnected = 0;

    /**
     * @var bool
     */
    protected $reConnect = true;

    /**
     * @var array|null
     */
    protected $errorConnect;

    /**
     * Configures database client.
     *
     * Connection to the database server is created later, on demand.
     *
     * Options may be passed in root of arg databaseInfo
     * as well as in the options bucket.
     *
     * @see DbClient::OPTION_SHORTHANDS
     *
     * @see DbClient::characterSetResolve()
     *
     * @param string $name
     * @param array $databaseInfo {
     *      @var string $host
     *      @var string $port  Optional, defaults to class constant SERVER_PORT.
     *      @var string $database
     *      @var string $user
     *      @var string $pass
     *      @var array $options
     *          Database type specific options, see also OPTION_SHORTHANDS.
     * }
     */
    public function __construct(string $name, array $databaseInfo)
    {
        $this->name = $name;

        $info_items = static::DATABASE_INFO;
        foreach ($info_items as $key => $required) {
            if (isset($databaseInfo[$key])) {
                if ($required && !$databaseInfo[$key]) {
                    throw new \LogicException(
                        'Database arg databaseInfo key[' . $key
                        . '] type[' . gettype($databaseInfo[$key]) . '] is empty'
                        . '. Saw keys ' . (!$databaseInfo ? '- none -' : join(', ', array_keys($databaseInfo))) . '.'
                    );
                }
                $this->{$key} = $databaseInfo[$key];
            }
            elseif ($required) {
                throw new \LogicException(
                    'Database arg databaseInfo key[' . $key  . '] '
                    . (array_key_exists($key, $databaseInfo) ? 'is null' : 'is missing')
                    . '. Saw keys ' . (!$databaseInfo ? '- none -' : join(', ', array_keys($databaseInfo))) . '.'
                );
            }
        }
        // Safeguard against null options.
        if (!$this->options) {
            $this->options = [];
        }
        // Coerce port to integer or use fallback.
        $this->port = $this->port ? (int) $this->port : static::SERVER_PORT;

        // Pass options placed in root of databaseInfo to options.
        $this->options += array_diff_key($databaseInfo, $info_items);

        $this->characterSetResolve();
    }

    /**
     * Disable re-connection permanently.
     *
     * @return $this|DbClientInterface
     */
    public function reConnectDisable() : DbClientInterface
    {
        $this->reConnect = false;
        return $this;
    }

    /**
     * Create a query.
     *
     * Allowed options:
     * @see DbQuery::OPTIONS_GENERIC
     * @see MariaDbQuery::OPTIONS_SPECIFIC
     * @see MsSqlQuery::OPTIONS_SPECIFIC
     *
     * Actual use of options:
     * @see MariaDbQuery::__construct()
     * @see MsSqlQuery::__construct()
     *
     * @param string $sql
     * @param array $options
     *
     * @return DbQueryInterface
     */
    public function query(string $sql, array $options = []) : DbQueryInterface
    {
        $class_query = static::CLASS_QUERY;
        /** @var DbQueryInterface|MariaDbQuery|MsSqlQuery */
        return new $class_query(
            $this,
            $sql,
            $options
        );
    }


    // Helpers.-----------------------------------------------------------------

    /**
     * Resolve options.
     *
     * Chainable.
     *
     * Public to facilitate option debugging prior to attempt to connect.
     *
     * @see DbClientInterface::getConnection()
     * @see DbClient::OPTION_SHORTHANDS
     * @see DbClient::$optionsResolved
     *
     * @return $this|DbClient
     *      Throws exception on error.
     */
    abstract public function optionsResolve() : DbClient;

    /**
     * Output when error(s):
     * (code)[SQL state] Message. | (code)[SQL state] Message
     *
     * Output when no error: - no native error recorded -
     *
     * @param array $errors
     * @param bool $emptyOnNone
     *
     * @return string
     */
    public function errorsToString(array $errors, bool $emptyOnNone = false)
    {
        return $errors ? rtrim(join(DbClient::NATIVE_ERRORS_DELIM, $errors), '.') :
            ($emptyOnNone ? '' : '- no native error recorded -');
    }

    /**
     * Get database exception class matching a list of error codes.
     *
     * Checks if:
     * - any code is a connection error
     * - any code is a query error
     * - any code is result error
     * - first code is ERROR_CODE_CONNECT
     *
     * @see DbClient::getErrors()
     *
     * @param array $errors
     *      List of error codes, or list returned by getErrors().
     * @param string $default
     *
     * @return string
     */
    public function errorsToException(array $errors, string $default = DbRuntimeException::class) : string
    {
        if ($errors) {
            /**
             * Check whether given simple list of error codes
             * or list returned by getErrors()
             * @see DbClient::getErrors()
             */
            if (strpos('' . reset($errors), '(') === 0) {
                $list = array_keys($errors);
            } else {
                $list =& $errors;
            }
            $class = static::CLASS_ERROR_CODES;
            $connection = constant($class . '::CONNECTION');
            $query = constant($class . '::QUERY');
            $result = constant($class . '::RESULT');
            foreach ($list as $code) {
                if (in_array($code, $connection)) {
                    return DbConnectionException::class;
                }
                if (in_array($code, $query)) {
                    return DbQueryException::class;
                }
                if (in_array($code, $result)) {
                    return DbResultException::class;
                }
            }
            if (reset($list) == static::ERROR_CODE_CONNECT) {
                return DbConnectionException::class;
            }
        }
        return $default;
    }

    /**
     * Resolve character set, for constructor.
     *
     * Character set must be available even before any connection,
     * (at least) for external use.
     *
     * @return void
     *      Throws exception on error.
     */
    abstract protected function characterSetResolve() /*: void*/;

    /**
     * @see DbClient::disConnect()
     */
    public function __destruct()
    {
        $this->disConnect();
    }


    // Package protected.-------------------------------------------------------

    /**
     * Database[client name][rmdbs type][database name].
     *
     * @internal Package protected.
     *
     * @return string
     */
    public function messagePrefix() : string
    {
        return 'Database[' . $this->name . '][' . $this->type . '][' . $this->database . ']';
    }

    /**
     * Formats RMDBS native error list to generic format.
     *
     * Required structure of $arg nativeErrors:
     * [
     *      (associative array) {
     *          (int|string) code: code|'code',
     *          (string) sqlstate: '00000',
     *          (string) msg: 'Bla-bla.',
     *      }
     * ]
     *
     * Output associative array:
     * {
     *      (int|string) code|'code_dupe': '(code)[SQL state] Message.'
     * }
     *
     * Output as string:
     * @see DbClient::errorsToString)
     *
     * @see DbClient::NATIVE_ERRORS_DELIM
     *
     * @see MariaDbClient::getErrors()
     * @see MariaDbQuery:getErrors()
     * @see MsSqlClient::getErrors()
     *
     * @param array[] $nativeErrors
     * @param int $toString
     *      1: on no error return message indicating no errors.
     *      2: on no error return empty string.
     *
     * @return array|string
     *      Array: key is error code.
     */
    public function formatNativeErrors(array $nativeErrors, int $toString = 0)
    {
        $list = [];
        if ($nativeErrors) {
            $dupes = 0;
            foreach ($nativeErrors as $error) {
                $code = $error['code'];
                // Secure that dupes don't disappear due to bucket overwrite.
                $list[!isset($list[$code]) ? $code : ($code . str_repeat('_', ++$dupes))] =
                    '(' . $code . ')[' . $error['sqlstate'] . '] ' . $error['msg'];
            }
        }
        return !$toString ? $list : $this->errorsToString($list, $toString == DbError::AS_STRING_EMPTY_ON_NONE);
    }

    /**
     * @internal Package protected.
     *
     * @see \SimpleComplex\Inspect\Inspect::variable().
     *
     * @param string $message
     * @param null $variable
     *      Ignored if no argument or dependency injection container
     *      has no 'inspect'.
     * @param array $options
     *      For Inspect.
     *
     * @return void
     */
    public function log(string $message, $variable = null, array $options = []) /*: void*/
    {
        /** @var \Psr\Container\ContainerInterface $container */
        $container = Dependency::container();
        if (!$container->has('logger')) {
            throw new \LogicException('Dependency injection container contains no \'logger\'.');
        }
        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $container->get('logger');

        if (func_num_args() < 2 || !$container->has('inspect')) {
            $logger->log(static::LOG_LEVEL, $message, [
                'subType' => 'database',
            ]);
        }
        else {
            /** @var \SimpleComplex\Inspect\Inspect $inspect */
            $inspect = $container->get('inspect');
            $logger->log(
                static::LOG_LEVEL,
                $message . "\n" . $inspect->variable(
                    $variable,
                    [
                        'wrappers' => ($options['wrappers'] ?? 0) + 1,
                    ]
                ),
                [
                    'subType' => 'database',
                ]
            );
        }
    }


    // Explorable.--------------------------------------------------------------

    /**
     * List of names of members (private, protected or public which should be
     * exposed as accessibles in count()'ing and foreach'ing.
     *
     * Private/protected members are also be readable via 'magic' __get().
     *
     * @see DbClient::__get()
     *
     * @internal
     *
     * @var string[]
     */
    protected $explorableIndex = [
        // Protected; readable via 'magic' __get().
        'type',
        'name',
        'host',
        'port',
        'database',
        'user',
        'options',
        'optionsResolved',
        'characterSet',
        'transactionStarted',
        'timesConnected',
        'reConnect',
    ];

    /**
     * Get a read-only property.
     *
     * @param string $name
     *
     * @return mixed
     *
     * @throws \OutOfBoundsException
     *      If no such instance property.
     */
    public function __get(string $name)
    {
        if (in_array($name, $this->explorableIndex, true)) {
            return $this->{$name};
        }
        throw new \OutOfBoundsException(get_class($this) . ' instance exposes no property[' . $name . '].');
    }

    /**
     * @param string $name
     * @param mixed|null $value
     *
     * @return void
     *
     * @throws \OutOfBoundsException
     *      If no such instance property.
     * @throws \RuntimeException
     *      If that instance property is read-only.
     */
    public function __set(string $name, $value) /*: void*/
    {
        if (in_array($name, $this->explorableIndex, true)) {
            throw new \RuntimeException(get_class($this) . ' instance property[' . $name . '] is read-only.');
        }
        throw new \OutOfBoundsException(get_class($this) . ' instance exposes no property[' . $name . '].');
    }
}

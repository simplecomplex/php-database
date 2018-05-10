<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Utils\Explorable;
use SimpleComplex\Utils\Dependency;

use SimpleComplex\Database\Exception\DbConnectionException;
use SimpleComplex\Database\Exception\DbQueryException;
use SimpleComplex\Database\Exception\DbResultException;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;

/**
 * Database client.
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
 *
 * @package SimpleComplex\Database
 */
abstract class DatabaseClient extends Explorable implements DbClientInterface
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
     * @see DatabaseClient::errorsToException()
     * @see MsSqlErrorCodes
     * @see MsSqlErrorCodes
     *
     * @var string
     */
    const CLASS_ERROR_CODES = DatabaseErrorCodes::class;

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
     * All buckets are required.
     *
     * Buckets:
     * - (str) host: 'domain.tld'
     * - (str) database: database name
     * - (str) user: 'xyz123'
     * - (str) pass: '∙∙∙∙∙∙∙∙∙∙∙∙'
     *
     * @var string[]
     */
    const DATABASE_INFO_REQUIRED = [
        // string.
        'host',
        // string.
        'database',
        // string.
        'user',
        // string.
        'pass',
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
     * @see MariaDbClient::nativeErrors()
     * @see MariaDbQuery:nativeErrors()
     * @see MsSqlClient::nativeErrors()
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
     * @see DatabaseClient::characterSetResolve()
     *
     * @var string
     */
    protected $characterSet;

    /**
     * @var bool
     */
    protected $transactionStarted = false;

    /**
     * Configures database client.
     *
     * Connection to the database server is created later, on demand.
     *
     * @see DatabaseClient::OPTION_SHORTHANDS
     *
     * @see DatabaseClient::characterSetResolve()
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

        $requireds = static::DATABASE_INFO_REQUIRED;
        foreach ($requireds as $key) {
            if (empty($databaseInfo[$key])) {
                throw new \LogicException(
                    'Database arg databaseInfo key[' . $key  . '] '
                    . (array_key_exists($key, $databaseInfo) ?
                        ('type[' . gettype($databaseInfo[$key]) . '] is empty') :
                        'is missing')
                    . '. Required keys are ' . join(', ', $requireds) . '; saw keys '
                    . (!$databaseInfo ? '- none -' : join(', ', array_keys($databaseInfo))) . '.'
                );
            }
        }
        $this->host = $databaseInfo['host'];
        $this->port = !empty($databaseInfo['port']) ? (int) $databaseInfo['port'] : static::SERVER_PORT;
        $this->database = $databaseInfo['database'];
        $this->user = $databaseInfo['user'];
        $this->pass = $databaseInfo['pass'];
        $this->options = $databaseInfo['options'] ?? [];

        $this->characterSetResolve();
    }

    /**
     * Create a query.
     *
     * For options, see DbQueryInterface implementation's constructor.
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
     * @see DatabaseClient::OPTION_SHORTHANDS
     * @see DatabaseClient::$optionsResolved
     *
     * @return $this|DatabaseClient
     *      Throws exception on error.
     */
    abstract public function optionsResolve() : DatabaseClient;

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
    public function nativeErrorsToString(array $errors, bool $emptyOnNone = false)
    {
        return $errors ? rtrim(join(DatabaseClient::NATIVE_ERRORS_DELIM, $errors), '.') :
            ($emptyOnNone ? '' : '- no native error recorded -');
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
     * @see DatabaseClient::nativeErrorsToString)
     *
     * @see DatabaseClient::NATIVE_ERRORS_DELIM
     *
     * @see MariaDbClient::nativeErrors()
     * @see MariaDbQuery:nativeErrors()
     * @see MsSqlClient::nativeErrors()
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
        return !$toString ? $list : $this->nativeErrorsToString($list, $toString == Database::ERRORS_STRING_EMPTY_NONE);
    }

    /**
     * Get database exception class matching a list of error codes.
     *
     * Checks if:
     * - any code is a connection error
     * - any code is a query error
     * - first code is result error
     *
     * @see DatabaseClient::nativeErrors()
     *
     * @param array $errors
     *      List of error codes, or list returned by nativeErrors().
     * @param string $default
     *
     * @return string
     */
    public function errorsToException(array $errors, string $default = DbRuntimeException::class) : string
    {
        if ($errors) {
            /**
             * Check whether given simple list of error codes
             * or list returned by nativeErrors()
             * @see DatabaseClient::nativeErrors()
             */
            if (strpos('' . reset($errors), '(') === 0) {
                $list = array_keys($errors);
            } else {
                $list =& $errors;
            }
            $class = static::CLASS_ERROR_CODES;
            // Any IN.
            if (array_intersect($list, constant($class . '::CONNECTION'))) {
                return DbConnectionException::class;
            }
            // Any IN.
            if (array_intersect($list, constant($class . '::QUERY'))) {
                return DbQueryException::class;
            }
            // First IN.
            if (in_array(reset($list), constant($class . '::RESULT'))) {
                return DbResultException::class;
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
     * @see DatabaseClient::disConnect()
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
    public function errorMessagePrefix() : string
    {
        return 'Database[' . $this->name . '][' . $this->type . '][' . $this->database . ']';
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
     * @see DatabaseClient::__get()
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

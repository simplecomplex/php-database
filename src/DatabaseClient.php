<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Utils\Explorable;
use SimpleComplex\Utils\Dependency;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;

/**
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
                    . (array_key_exists($key, $databaseInfo) ? ('has empty value[' . $databaseInfo[$key] . ']') :
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
     * @param string $sql
     * @param array $options {
     *      @var bool $is_multi_query
     *          True: arg $sql contains multiple queries.
     * }
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
     * Public to facilitate option debugging prior to attempt to connect.
     *
     * @see DbClientInterface::getConnection()
     * @see DatabaseClient::OPTION_SHORTHANDS
     * @see DatabaseClient::$optionsResolved
     *
     * @return void
     *      Throws exception on error.
     */
    abstract public function optionsResolve() /*:void*/;

    /**
     * Resolve character set, for constructor.
     *
     * Character set must be available even before any connection,
     * (at least) for external use.
     *
     * @return void
     *      Throws exception on error.
     */
    abstract protected function characterSetResolve() /*:void*/;

    /**
     * @see DatabaseClient::disConnect()
     */
    public function __destruct()
    {
        $this->disConnect();
    }


    // Package protected.-------------------------------------------------------

    /**
     * @internal Package protected.
     *
     * @return string
     */
    public function errorMessagePrefix() : string
    {
        return 'Database[' . $this->type . '][' . $this->name . '][' . $this->database . ']';
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
    public function log(string $message, $variable = null, array $options = []) /*:void*/
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

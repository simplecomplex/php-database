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
use SimpleComplex\Database\Interfaces\DbClientInterface;

/**
 * Database abstraction decoupling Maria DB, MS SQL interaction.
 *
 * @dependency-injection-container database-broker
 *      Suggested ID of the DatabaseBroker instance.
 *
 * All registered database clients are accessible via 'magic' getters.
 * @property-read DbClientInterface *
 *
 * @package SimpleComplex\Database
 */
class DatabaseBroker extends Explorable
{
    /**
     * Class name of \SimpleComplex\Database\MariaDbQuery or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_CLIENT_MARIADB = \Package\Library\CustomMariaDbQuery::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MariaDbClient
     *
     * @var string
     */
    const CLASS_CLIENT_MARIADB = MariaDbClient::class;

    /**
     * Class name of \SimpleComplex\Database\MsSqlClient or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_CLIENT_MARIADB = \Package\Library\CustomMsSqlClient::class;
     * @endcode
     *
     * @see \SimpleComplex\Database\MsSqlClient
     *
     * @var string
     */
    const CLASS_CLIENT_MSSQL = MsSqlClient::class;

    /**
     * @var DbClientInterface[]
     */
    protected $clients = [];

    /**
     * Get or create database client.
     *
     * @param string $name
     * @param string $type
     *      Values: mariadb|mssql or type provided by extending class.
     * @param array $databaseInfo
     *
     * @return DbClientInterface
     *
     * @throws \InvalidArgumentException
     *      Arg $name empty.
     *      Arg $type value not supported.
     */
    public function getClient(string $name, string $type, array $databaseInfo) : DbClientInterface
    {
        if (!$name) {
            throw new \InvalidArgumentException(
                'Arg $name cannot be empty.'
            );
        }
        if (isset($this->clients[$name])) {
            return $this->clients[$name];
        }
        // CLASS_CLIENT_MARIADB|CLASS_CLIENT_MSSQL.
        $class = constant('static::CLASS_CLIENT_' . strtoupper($type));
        if (!$class) {
            throw new \InvalidArgumentException(
                'Arg $type[' . $type . '] is not supported, no equivalent DbClientInterface class available.'
            );
        }
        /** @var DbClientInterface $client */
        $this->clients[$name] = $client = new $class($name, $databaseInfo);
        $this->explorableIndex[] = $name;
        return $client;
    }

    /**
     * @param string $name
     *
     * @throws \InvalidArgumentException
     *      Arg $name empty.
     */
    public function destroyClient(string $name)
    {
        if (!$name) {
            throw new \InvalidArgumentException(
                'Arg $name cannot be empty.'
            );
        }
        if (isset($this->clients[$name])) {
            $index = array_search($name, $this->explorableIndex);
            if ($index !== false) {
                array_splice($this->explorableIndex, $index, 1);
            }
            $this->clients[$name]->disConnect();
            unset($this->clients[$name]);
        }
    }


    // Explorable.--------------------------------------------------------------.

    /**
     * List of names of registered database clients.
     *
     * @see DatabaseBroker::__get()
     *
     * @internal
     *
     * @var string[]
     */
    protected $explorableIndex = [];

    /**
     * Retrieves a database client.
     *
     * @param string $name
     *
     * @return DbClientInterface
     *
     * @throws \OutOfBoundsException
     *      If no such instance property.
     */
    public function __get(string $name)
    {
        if (isset($this->clients[$name])) {
            return $this->clients[$name];
        }
        throw new \OutOfBoundsException(get_class($this) . ' instance has no database client[' . $name . '].');
    }

    /**
     * All database client are read-only.
     *
     * @param string $name
     * @param mixed|null $value
     *
     * @return void
     *
     * @throws \OutOfBoundsException
     *      If no such instance property.
     * @throws \RuntimeException
     *      If such instance property declared.
     */
    public function __set(string $name, $value) /*: void*/
    {
        switch ($name) {
            case 'clients':
                throw new \RuntimeException(get_class($this) . ' instance database client[' . $name . '] is read-only.');
        }
        throw new \OutOfBoundsException(get_class($this) . ' instance has no database client[' . $name . '].');
    }
}

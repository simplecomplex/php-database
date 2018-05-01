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

use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbResultInterface;

/**
 * Database result.
 *
 * @property-read int $setIndex
 * @property-read int $rowIndex
 *
 * @package SimpleComplex\Database
 */
abstract class DatabaseResult extends Explorable implements DbResultInterface
{
    /**
     * @var MsSqlQuery
     */
    protected $query;

    /**
     * @var mixed
     */
    protected $statement;

    /**
     * Index of current result set.
     *
     * @var int
     */
    protected $setIndex = 0;

    /**
     * Index of current row in current result set.
     *
     * Value is minus one until any method has fetched a (first) row.
     *
     * @var int
     */
    protected $rowIndex = -1;

    // Helpers.-----------------------------------------------------------------

    /**
     * @param string $function
     */
    protected function logQuery(string $function)
    {
        $this->query->client->log(
            $this->query->errorMessagePrefix() . ' - ' . $function . '(), query',
            substr($this->query->queryTampered ?? $this->query->query, 0,
                constant(get_class($this->query) . '::LOG_QUERY_TRUNCATE')),
            [
                'wrappers' => 1,
            ]
        );
    }


    // Explorable.--------------------------------------------------------------

    /**
     * List of names of members (private, protected or public which should be
     * exposed as accessibles in count()'ing and foreach'ing.
     *
     * Private/protected members are also be readable via 'magic' __get().
     *
     * @see DatabaseResult::__get()
     *
     * @internal
     *
     * @var string[]
     */
    protected $explorableIndex = [
        // Protected; readable via 'magic' __get().
        'setIndex',
        'rowIndex',
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
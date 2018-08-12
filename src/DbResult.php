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

use SimpleComplex\Database\Interfaces\DbResultInterface;

/**
 * Database result.
 *
 * Both setIndex and rowIndex must go out-of-bounds when first/next set/row
 * is called for and there aren't any more sets/rows.
 *
 * @property-read int $setIndex
 * @property-read int $rowIndex
 *
 * @package SimpleComplex\Database
 */
abstract class DbResult extends Explorable implements DbResultInterface
{
    /**
     * @var int
     */
    const FETCH_ASSOC = 2;

    /**
     * @var int
     */
    const FETCH_NUMERIC = 3;

    /**
     * @var int
     */
    const FETCH_OBJECT = 5;

    /**
     * @var MsSqlQuery
     */
    protected $query;

    /**
     * Reference to query statement, if any.
     *
     * @see DbQuery::$statement
     *
     * @var mixed|null
     */
    protected $statement;

    /**
     * Index of current result set.
     *
     * Is minus one until nextRow() or any method has fetched a (first) row.
     *
     * @var int
     */
    protected $setIndex = -1;

    /**
     * Index of current row in current result set.
     *
     * Is minus one until any method has fetched a (first) row.
     *
     * @var int
     */
    protected $rowIndex = -1;

    /**
     * Traverse all remaining result sets.
     *
     * Errors of a query returning multiple result sets may not surface until
     * all sets have being accessed.
     *
     * MariaDb: A 'must' when using MariaDb multi-query.
     *
     * MsSql: Apparantly obsolete.
     *
     * @return void
     */
    public function depleteSets() /*: void*/
    {
        while ($this->nextSet()) {}
    }

    /**
     * Traverse all remaining rows in current result set.
     *
     * MariaDb may fail to release resources until all rows
     * have been traversed.
     * @see https://bugs.mysql.com/bug.php?id=42929
     *
     * @return void
     */
    public function depleteRows() /*: void*/
    {
        while ($this->nextRow()) {}
    }

    /**
     * Traverse all remaining result sets and rows.
     *
     * @return void
     */
    public function depleteAll() /*: void*/
    {
        while ($this->nextRow()) {}
        while ($this->nextSet()) {
            while ($this->nextRow()) {}
        }
    }


    // Helpers.-----------------------------------------------------------------

    /**
     * Free result set, close query statement, and log.
     *
     * @param string $method
     *
     * @return void
     */
    protected function closeAndLog(string $method) /*: void*/
    {
        $this->free();
        $this->query->close();
        $this->query->log('result[' . $this->setIndex . '][' . $this->rowIndex . ']->' . $method, false, 1);
    }


    // Explorable.--------------------------------------------------------------

    /**
     * List of names of members (private, protected or public which should be
     * exposed as accessibles in count()'ing and foreach'ing.
     *
     * Private/protected members are also be readable via 'magic' __get().
     *
     * @see DbResult::__get()
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

<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018-2019 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Utils\Explorable;
use SimpleComplex\Utils\Utils;

use SimpleComplex\Database\Interfaces\DbResultInterface;

use SimpleComplex\Database\Exception\DbRuntimeException;

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
     *
     * @throws \Throwable Propagated.
     */
    public function depleteRows() /*: void*/
    {
        while ($this->nextRow()) {}
    }

    /**
     * Traverse all remaining result sets and rows.
     *
     * @return void
     *
     * @throws \Throwable Propagated.
     */
    public function depleteAll() /*: void*/
    {
        while ($this->nextRow()) {}
        while ($this->nextSet()) {
            while ($this->nextRow()) {}
        }
    }

    /**
     * Elicits E_USER_DEPRECATED error and relays to fetchField.
     *
     * @deprecated Use fetchField().
     *
     * @param int $index
     * @param string $name
     *
     * @return mixed|null

     * @throws \Throwable Propagated.
     */
    public function fetchColumn(int $index = 0, string $name = null)
    {
        trigger_error(
            'Method deprecated ' . get_class($this) . '::fetchColumn() in favour of fetchField().',
            E_USER_DEPRECATED
        );

        return $this->fetchField($index, $name);
    }

    /**
     * Fetch value of a single column of all rows.
     *
     * To get value of a single column of a single row, see fetchField().
     * @see MariaDbResult::fetchField()
     * @see MsSqlResult::fetchField()
     *
     * @param int $index
     * @param string $name
     *      Non-empty: fetch column by that name, ignore arg $index.
     * @param string $list_by_column
     *      Key list by that column's values; ignored if falsy arg $name.
     *
     * @return array
     *      Empty on no rows.
     *      Throws throwable on failure.
     *
     * @throws \InvalidArgumentException
     *      Arg $index negative.
     * @throws \OutOfRangeException
     *      Result row has no such $index|$name.
     * @throws DbRuntimeException
     */
    public function fetchFieldAll(int $index = 0, string $name = null, string $list_by_column = null) : array
    {
        $length = null;
        // Column name cannot be '0' (sql illegal) so loose check suffices.
        if (!$name) {
            if ($list_by_column) {
                $this->closeAndLog(__FUNCTION__);
                throw new \InvalidArgumentException(
                    $this->query->client->messagePrefix() . ' - arg $list_by_column type['
                    . Utils::getType($list_by_column) . '] must be empty when fetching all fields by numeric index.'
                );
            }
            if ($index < 0) {
                $this->closeAndLog(__FUNCTION__);
                throw new \InvalidArgumentException(
                    $this->query->messagePrefix() . ' - failed fetching all fields, arg $index['
                    . $index . '] cannot be negative.'
                );
            }
            $rows = $this->fetchArrayAll(DbResult::FETCH_NUMERIC);
            if (!$rows) {
                return $rows;
            }
            $list = [];
            $row = reset($rows);
            $length = count($row);
            if ($index < $length) {
                foreach ($rows as $row) {
                    $list[] = $row[$index];
                }
                return $list;
            }
        }
        else {
            $rows = $this->fetchArrayAll(DbResult::FETCH_ASSOC);
            if (!$rows) {
                return $rows;
            }
            $list = [];
            $row = reset($rows);
            if (array_key_exists($name, $row)) {
                // Column name cannot be '0' (sql illegal) so loose check suffices.
                if ($list_by_column) {
                    if (!array_key_exists($list_by_column, $row)) {
                        $this->closeAndLog(__FUNCTION__);
                        throw new \InvalidArgumentException(
                            $this->query->messagePrefix()
                            . ' - failed fetching all fields listed by column['
                            . $list_by_column . '], non-existent column.'
                        );
                    }
                    foreach ($rows as $row) {
                        // Fails if non-stringable object.
                        $key = '' . $row[$list_by_column];
                        $list[$key] = $row[$name];
                    }
                    return $list;
                }
                foreach ($rows as $row) {
                    $list[] = $row[$name];
                }
                return $list;
            }
        }
        $this->closeAndLog(__FUNCTION__);
        throw new \OutOfRangeException(
            $this->query->messagePrefix() . ' - failed fetching all fields, rows '
            . (!$name ? (' length[' .  $length . '] have no column $index[' . $index . '].') :
                ('have no column $name[' . $name . '].')
            )
        );
    }

    /**
     * Elicits E_USER_DEPRECATED error and relays to fetchArrayAll.
     *
     * @deprecated Use fetchArrayAll().
     *
     * @param int $as
     * @param string $list_by_column
     *
     * @return array

     * @throws \Throwable Propagated.
     */
    public function fetchAllArrays(int $as = DbResult::FETCH_ASSOC, string $list_by_column = null) : array
    {
        trigger_error(
            'Method deprecated ' . get_class($this) . '::fetchAllArrays() in favour of fetchArrayAll().',
            E_USER_DEPRECATED
        );

        return $this->fetchArrayAll($as, $list_by_column);
    }

    /**
     * Elicits E_USER_DEPRECATED error and relays to fetchObjectAll.
     *
     * @deprecated Use fetchObjectAll().
     *
     * @param string $class
     * @param string $list_by_column
     * @param array $args
     *
     * @return object[]

     * @throws \Throwable Propagated.
     */
    public function fetchAllObjects(string $class = null, string $list_by_column = null, array $args = null) : array
    {
        trigger_error(
            'Method deprecated ' . get_class($this) . '::fetchAllObjects() in favour of fetchObjectAll().',
            E_USER_DEPRECATED
        );

        return $this->fetchObjectAll($class, $list_by_column, $args);
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

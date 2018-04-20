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
use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Exception\DbLogicalException;

/**
 * @property-read string $query
 * @property-read bool $isMultiQuery
 * @property-read bool $hasLikeClause
 * @property-read bool $isPreparedStatement
 *
 * @package SimpleComplex\Database
 */
abstract class AbstractDbQuery extends Explorable implements DbQueryInterface
{
    /**
     * @var string
     */
    protected $query;

    /**
     * @var string
     */
    protected $queryWithArguments;

    /**
     * @var bool
     */
    protected $isMultiQuery = false;

    /**
     * @var bool
     */
    protected $hasLikeClause = false;

    /**
     * @var bool
     */
    protected $isPreparedStatement = false;

    /**
     * @var int[]
     */
    protected $parameterPositions;

    /**
     * @param DbClientInterface $client
     *      Reference to parent client.
     * @param string $query
     *
     * @throws \InvalidArgumentException
     *      Arg query empty.
     */
    abstract public function __construct(DbClientInterface $client, string $query);

    /**
     * @return $this|DbQueryInterface
     */
    public function hasLikeClause()
    {
        $this->hasLikeClause = true;
        return $this;
    }

    protected function passParams($types, $arguments)
    {
        // Find parameter positions within the query.
        if ($this->parameterPositions === null) {
            $this->parameterPositions = [];
            $haystack = $this->query;
            $offset = 0;
            while (($pos = strpos($haystack, '?', $offset)) !== false) {
                $offset = $pos + 1;
                $this->parameterPositions[] = $pos;
            }
            if (!$this->parameterPositions) {
                if ($arguments) {
                    throw new \InvalidArgumentException(
                        'Database query has no arguments, contains no question mark(s), .'
                    );
                }
            }
            elseif (!$arguments) {

            }
        }
        // Number of parameters and arguments must match.
        $n_params = count($this->parameterPositions);
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                'Database query has ' . $n_params . ' parameters, saw ' . $n_args . ' arguments.'
            );
        }
    }

    /**
     * Pass parameters to simple (non-prepared statement) query.
     *
     * @param string $types
     *      i: integer.
     *      d: float (double).
     *      s: string.
     *      b: blob.
     * @param array $arguments
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Calling this method when prepare() called previously.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                'Database query won\'t pass parameters to prepared statement.'
            );
        }

        // Reset.
        $this->queryWithArguments = '';

        if ($this->parameterPositions === null) {
            $this->parameterPositions = [];
            $haystack = $this->query;
            $offset = 0;
            while (($pos = strpos($haystack, '?', $offset)) !== false) {
                $offset = $pos + 1;
                $this->parameterPositions[] = $pos;
            }
            if (!$this->parameterPositions) {
                if ($arguments) {
                    throw new \InvalidArgumentException(
                        'Database query has no arguments, contains no question mark(s), .'
                    );
                }
            }
            elseif (!$arguments) {

            }
        }
        $n_params = count($this->parameterPositions);
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                'Database query has ' . $n_params . ' parameters, saw ' . $n_args . ' arguments.'
            );
        }


        // @todo: see ZZmysqlSimple::_bindParam()

        return $this;
    }

    /**
     * Pass parameters to simple query for multi-query use.
     *
     * @param string $types
     *      i: integer.
     *      d: float (double).
     *      s: string.
     *      b: blob.
     * @param array $arguments
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Calling this method when prepare() called previously.
     */
    public function multiQueryParameters(string $types, array $arguments) : DbQueryInterface
    {
        $this->isMultiQuery = true;

        $previousQueries = $this->queryWithArguments;

        $this->parameters($types, $arguments);

        $this->queryWithArguments = (!$previousQueries ? '' : ($previousQueries . '; ')) . $this->queryWithArguments;

        return $this;
    }

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * @param string $types
     *      i: integer.
     *      d: float (double).
     *      s: string.
     *      b: blob.
     * @param array $arguments
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    abstract public function prepare(string $types, array $arguments) : DbQueryInterface;


    // Explorable.--------------------------------------------------------------

    /**
     * List of names of members (private, protected or public which should be
     * exposed as accessibles in count()'ing and foreach'ing.
     *
     * Private/protected members are also be readable via 'magic' __get().
     *
     * @see AbstractDbQuery::__get()
     *
     * @internal
     *
     * @var string[]
     */
    protected $explorableIndex = [
        // Protected; readable via 'magic' __get().
        'query',
        'isMultiQuery',
        'hasLikeClause',
        'isPreparedStatement',
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

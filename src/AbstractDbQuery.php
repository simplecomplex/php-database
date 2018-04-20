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
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var AbstractDbClient
     */
    public $client;

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
     * Query parameter ? positions.
     *
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

    /**
     * Pass parameters to simple (non-prepared statement) query.
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $types
     *      Empty: uses string for all.
     * @param array $arguments
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Calling this method when prepare() called previously.
     * @throws \InvalidArgumentException
     *      Arg types length (unless empty) doesn't match number of parameters.
     *      Arg arguments length doesn't match number of parameters.
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


        // @todo: use explode('?', $this->query) instead; quicker than strpos()... and substr_replace()...

        if ($this->parameterPositions === null) {
            $this->parameterPositions = [];
            $haystack = $this->query;
            $offset = 0;
            while (($pos = strpos($haystack, '?', $offset)) !== false) {
                $offset = $pos + 1;
                $this->parameterPositions[] = $pos;
            }
        }
        $n_params = count($this->parameterPositions);
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                'Database query has ' . $n_params . ' parameters, saw ' . $n_args . ' arguments.'
            );
        }

        $tps = $types;
        if (!$tps) {
            $tps = str_repeat('s', $n_params);
        }
        elseif (strlen($types) != $n_params) {
            throw new \InvalidArgumentException(
                'Database query has ' . $n_params . ' parameters, saw ' . strlen($types) . ' types.'
            );
        }

        // Mend that arguments array may not be numerically indexed,
        // nor correctly indexed.
        $args = array_values($arguments);

        $query_with_args = $this->query;

        // Work in reverse order to prevent ?-positions from moving.
        for ($i = $n_args - 1; $i >= 0; --$i) {
            $value = $args[$i];
            switch ($tps{$i}) {
                case 's':
                case 'b':
                    $value = "'" . $this->escapeString($value) . "'";
                    break;
                case 'i':
                case 'd':
                case 'f':
                    break;
                default:
                    throw new \InvalidArgumentException(
                        'Arg types[' . $types . '] index[' . $i . '] char[' . $tps{$i} . '] is not i|d|s|b.'
                    );
            }
            $query_with_args = substr_replace($query_with_args, $value, $this->parameterPositions[$i], 1);
        }

        $this->queryWithArguments = $query_with_args;

        return $this;
    }

    /**
     * Pass parameters to simple query for multi-query use.
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $types
     *      Empty: uses string for all.
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

    /**
     * @var string
     */
    protected $escapeStringPattern;

    /**
     * Generic parameter value escaper.
     *
     * Escapes %_ unless instance var hasLikeClause.
     *
     * Replaces semicolon with comma if multi-query.
     *
     * @param string $str
     *
     * @return string
     */
    public function escapeString(string $str) : string
    {
        $s = $str;
        if ($this->isMultiQuery) {
            $s = str_replace(';', ',', $s);
        }

        if (!$this->escapeStringPattern) {
            $pattern = '/[\x00\x0A\x0D\x1A\x22\x27\x5C';
            if (!$this->hasLikeClause) {
                $pattern .= '\x25\x5F';
            }
            $pattern .= ']/';
            if (strpos(strtolower(str_replace('-', '', $this->client->characterSet)), 'utf8') === 0) {
                $pattern .= 'u';
            }
            $this->escapeStringPattern = $pattern;
        }

        return '' . preg_replace($this->escapeStringPattern, '\\\$0', $s);
    }


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

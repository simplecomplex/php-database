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
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read int $nParameters
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
     * Must be null when empty (not used).
     *
     * @var string|null
     */
    protected $queryWithArguments;

    /**
     * @var bool
     */
    protected $isMultiQuery = false;

    /**
     * @var bool
     */
    protected $isPreparedStatement = false;

    /**
     * @var bool
     */
    protected $hasLikeClause = false;

    /**
     * Number of query parameter ? positions.
     *
     * @var int
     */
    protected $nParameters = 0;

    /**
     * @var array
     */
    protected $preparedStatementArgs;

    /**
     * @param DbClientInterface $client
     *      Reference to parent client.
     * @param string $query
     *
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     */
    abstract public function __construct(DbClientInterface $client, string $query);

    /**
     * Flag that the query contains LIKE clause(s).
     *
     * Affects parameter escaping: chars %_ won't be escaped.
     * @see AbstractDbQuery::escapeString()
     *
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
     * Secures that the query is reusable - resets internal query
     * with parameters substituted by arguments.
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
     *      Values to substitute query ?-parameters with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Calling this method when prepare() called previously.
     * @throws \InvalidArgumentException
     *      Arg $types length (unless empty) doesn't match number of parameters.
     *      Arg $arguments length doesn't match number of parameters.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble()
                . ' passing parameters to prepared statement is illegal except via (one) call to prepareStatement().'
            );
        }

        // Reset; make reusable.
        $this->queryWithArguments = null;

        $fragments = explode('?', $this->query);
        $n_params = count($fragments) - 1;
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' arg $arguments length[' . $n_args
                . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
            );
        }
        $this->nParameters = $n_params;

        if (!$n_params) {
            // No work to do.
            return $this;
        }

        $tps = $types;
        if ($tps === '') {
            // Be friendly, all strings.
            $tps = str_repeat('s', $n_params);
        }
        elseif (strlen($types) != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' arg $types length[' . strlen($types)
                . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
            );
        }

        // Mend that arguments array may not be numerically indexed,
        // nor correctly indexed.
        $args = array_values($arguments);

        $query_with_args = '';
        for ($i = 0; $i < $n_params; ++$i) {
            $value = $args[$i];
            switch ($tps{$i}) {
                case 's':
                case 'b':
                    $value = "'" . $this->escapeString($value) . "'";
                    break;
                case 'i':
                case 'd':
                    break;
                default:
                    throw new \InvalidArgumentException(
                        $this->client->errorMessagePreamble()
                        . ' arg $types[' . $types . '] index[' . $i . '] char[' . $tps{$i} . '] is not i|d|s|b.'
                    );
            }
            $query_with_args .= $fragments[$i] . $value;
        }
        $this->queryWithArguments = $query_with_args . $fragments[$i];

        return $this;
    }

    /**
     * Convert query to multi-query and pass parameters.
     *
     * Callable multiple times, passing parameters to new query instance.
     * However the base query (with ?-markers) will always be the same,
     * only parameter values may/will differ.
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
     *      Values to substitute query ?-parameters with.
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

        if ($this->nParameters) {
            $this->queryWithArguments = (!$previousQueries ? '' : ($previousQueries . '; '))
                . $this->queryWithArguments;
        }

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
     * @param array &$arguments
     *      By reference.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    abstract public function prepareStatement(string $types, array &$arguments) : DbQueryInterface;

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
        'isPreparedStatement',
        'hasLikeClause',
        'nParameters',
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

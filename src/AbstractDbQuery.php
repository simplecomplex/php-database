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
 * @property-read bool $isPreparedStatement
 * @property-read bool $isMultiQuery
 * @property-read bool $isRepeatStatement
 * @property-read bool $queryAppended
 * @property-read bool $hasLikeClause
 * @property-read string $query
 * @property-read string $queryWithArguments
 *
 * @package SimpleComplex\Database
 */
abstract class AbstractDbQuery extends Explorable implements DbQueryInterface
{
    /**
     * Whether the database type supports multi-query.
     *
     * @var bool
     */
    const MULTI_QUERY_SUPPORT = true;

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
    protected $isPreparedStatement = false;

    /**
     * @var bool
     */
    protected $isMultiQuery = false;

    /**
     * @var bool
     */
    protected $isRepeatStatement = false;

    /**
     * @var bool
     */
    protected $queryAppended = false;

    /**
     * @var bool
     */
    protected $hasLikeClause = false;

    /**
     * @var array
     */
    protected $preparedStatementArgs;

    /**
     * @param DbClientInterface|AbstractDbClient $client
     *      Reference to parent client.
     * @param string $baseQuery
     * @param bool $isMulti
     *      True: arg $baseQuery contains multiple queries.
     *
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     * @throws DbLogicalException
     *      True arg $isMulti and query class doesn't support multi-query.
     */
    public function __construct(DbClientInterface $client, string $baseQuery, bool $isMulti = false)
    {
        $this->client = $client;

        if (!$baseQuery) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' - arg $baseQuery cannot be empty.'
            );
        }
        // Remove trailing (and leading) semicolon; for multi-query.
        $this->query = trim($baseQuery, ' \t\n\r\0\x0B;');

        // The $isMulti parameter is needed because we cannot safely deduct
        // that a query is multi-query contains semicolon.
        // False positive if the query contains literal parameter value
        // and that value contains semicolon.

        if ($isMulti) {
            if (!static::MULTI_QUERY_SUPPORT) {
                throw new DbLogicalException(
                    $this->client->errorMessagePreamble() . ' doesn\'t support multi-query.'
                );
            }
            $this->isMultiQuery = true;
        }
    }

    /**
     * Turn query into prepared statement and bind parameters.
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $types
     *      Empty: uses string for all.
     * @param array &$arguments
     *      By reference.
     * @param array $options
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    abstract public function prepareStatement(string $types, array &$arguments, array $options = []) : DbQueryInterface;

    /**
     * Substitute base query ?-parameters by arguments.
     *
     * Makes the base query reusable.
     *
     * Non-prepared statement only.
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
     *      Base query has been repeated.
     *      Another query has been appended to base query.
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Arg $types contains illegal char(s).
     *      Arg $types length (unless empty) doesn't match number of parameters.
     *      Arg $arguments length doesn't match number of parameters.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        // Reset; secure base query reusability.
        $this->queryWithArguments = null;

        if ($this->isRepeatStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble()
                . ' - passing parameters to base query is illegal when base query has been repeated.'
            );
        }
        if ($this->queryAppended) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble()
                . ' - passing parameters to base query is illegal after another query has been appended.'
            );
        }
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble()
                . ' - passing parameters to prepared statement is illegal except via call to prepareStatement().'
            );
        }

        if ($types !== '' && ($type_illegals = $this->parameterTypesCheck($types))) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble()
                . ' - arg $types contains illegal char(s) ' . $type_illegals . '.'
            );
        }

        $this->queryWithArguments = $this->substituteParametersByArgs($this->query, $types, $arguments);

        return $this;
    }

    /**
     * Append query to previously defined query(ies).
     *
     * Non-prepared statement only.
     *
     * Turns the full query into multi-query.
     *
     * @param string $query
     * @param string $types
     *      Empty: uses string for all.
     * @param array $arguments
     *      Values to substitute query ?-parameters with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Query class doesn't support multi-query.
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     */
    public function appendQuery(string $query, string $types, array $arguments) : DbQueryInterface
    {
        if (!static::MULTI_QUERY_SUPPORT) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' doesn\'t support multi-query.'
            );
        }
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' - appending to prepared statement is illegal.'
            );
        }

        if (!$query) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' - arg $query cannot be empty.'
            );
        }

        $this->isMultiQuery = $this->queryAppended = true;

        if (!$this->queryWithArguments) {
            $this->queryWithArguments = $this->query;
        }
        $this->queryWithArguments .= '; ' . $this->substituteParametersByArgs($query, $types, $arguments);

        return $this;
    }

    /**
     * Repeat base query, and substitute it's ?-parameters by arguments.
     *
     * Turns the full query into multi-query, except at first call.
     *
     * Non-prepared statement only.
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
     *      Query class doesn't support multi-query.
     *      Another query has been appended to base query.
     *      Query is prepared statement.
     */
    public function repeatStatement(string $types, array $arguments) : DbQueryInterface
    {
        if (!static::MULTI_QUERY_SUPPORT) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' doesn\'t support multi-query.'
            );
        }
        if ($this->queryAppended) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble()
                . ' - repeating base query is illegal after another query has been appended.'
            );
        }
        if ($this->isPreparedStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePreamble() . ' - appending to prepared statement is illegal.'
            );
        }

        $repeated_query = $this->substituteParametersByArgs($this->query, $types, $arguments);

        if (!$this->queryWithArguments) {
            // Not necessarily multi-query yet.

            $this->queryWithArguments = $repeated_query;
        }
        else {
            $this->isMultiQuery = $this->isRepeatStatement = true;

            $this->queryWithArguments .= '; ' . $repeated_query;
        }

        return $this;
    }

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


    // Helpers.-----------------------------------------------------------------

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

    /**
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $types
     *
     * @return string
     *      Empty on no error.
     */
    public function parameterTypesCheck(string $types) : string
    {
        // Probably faster than regular expression check.
        $illegals = str_replace(
            [
                'i', 'd', 's', 'b',
            ],
            '',
            $types
        );

        if ($illegals !== '') {
            $illegals = [];
            $le = strlen($types);
            for ($i = 0; $i < $le; ++$i) {
                switch ($types{$i}) {
                    case 's':
                    case 'b':
                    case 'i':
                    case 'd':
                        break;
                    default:
                        $illegals[] = 'index[' . $i . '] char[' . $types{$i} . ']';
                }
            }
            return join(', ', $illegals);
        }

        return '';
    }

    /**
     * Substitute query ?-parameters by arguments.
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @param string $query
     * @param string $types
     * @param array $arguments
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     *      Arg $types length (unless empty) doesn't match number of parameters.
     *      Arg $arguments length doesn't match number of parameters.
     */
    protected function substituteParametersByArgs(string $query, string $types, array $arguments) : string
    {
        $fragments = explode('?', $query);
        $n_params = count($fragments) - 1;
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' - arg $arguments length[' . $n_args
                . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
            );
        }

        if (!$n_params) {
            // No work to do.
            return $query;
        }

        $tps = $types;
        if ($tps === '') {
            // Be friendly, all strings.
            $tps = str_repeat('s', $n_params);
        }
        elseif (strlen($types) != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePreamble() . ' - arg $types length[' . strlen($types)
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
                    // Unlikely when checked via parameterTypesCheck().
                    throw new \InvalidArgumentException(
                        $this->client->errorMessagePreamble()
                        . ' - arg $types[' . $types . '] index[' . $i . '] char[' . $tps{$i} . '] is not i|d|s|b.'
                    );
            }
            $query_with_args .= $fragments[$i] . $value;
        }

        return $query_with_args . $fragments[$i];
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
        'isPreparedStatement',
        'isMultiQuery',
        'isRepeatStatement',
        'queryAppended',
        'hasLikeClause',
        'query',
        'queryWithArguments',
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

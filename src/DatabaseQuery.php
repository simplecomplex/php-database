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
use SimpleComplex\Utils\Utils;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;

use SimpleComplex\Database\Exception\DbLogicalException;

/**
 *
 * Multi-query
 * -----------
 * A multi query (here) consists of multiple non-CRUD statements.
 * @todo: is this correct?
 * MySQL supports them, MS SQL doesn't.
 * A query containing 'CRUD; non-CRUD' (INSERT...; SELECT...) is not considered
 * a multi-query. And MS SQL supports such.
 *
 * Prepared statement vs. simple statement
 * ---------------------------------------
 * A prepared statement's arguments are referred and therefore changes will
 * reflect in later execution of the the statement.
 * A simple statement's arguments get consumed and one has to pass new arguments
 * for later execution of the statement.
 * Normally simple statements don't support automated (and safe) ?-parameter
 * substitution (argument value parsed into query string). However this library
 * does support that.
 * @see DatabaseQuery::parameters()
 *
 * CRUD vs non-CRUD statements
 * ---------------
 * CRUD: (at least) INSERT, UPDATE, REPLACE, DELETE.
 * Non-CRUD: (at least) SELECT, DESCRIBE, EXPLAIN, HELP, USE.
 *
 * @property-read string $id
 * @property-read bool $isPreparedStatement
 * @property-read bool $isMultiQuery
 * @property-read bool $isRepeatStatement
 * @property-read bool $queryAppended
 * @property-read bool $hasLikeClause
 * @property-read string $query
 * @property-read string $queryTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 *
 * @package SimpleComplex\Database
 */
abstract class DatabaseQuery extends Explorable implements DbQueryInterface
{
    /**
     * Whether the database type supports multi-query.
     *
     * @var bool
     */
    const MULTI_QUERY_SUPPORT = true;

    /**
     * Char or string flagging a parameter a query,
     * to be substituted by an argument.
     *
     * Typically question mark (Postgresql uses dollar sign).
     *
     * @var string
     */
    const QUERY_PARAMETER = '?';

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var DatabaseClient
     */
    public $client;

    /**
     * Random ID used for error handling, set on demand.
     *
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $query;

    /**
     * Copy of instance var $query manipulated in one more ways:
     * - parameter markers substituted by arguments
     * - another query has been appended
     * - the base query repeated (with parameter markers substituted)
     *
     * Must be null when empty (not used).
     *
     * @var string|null
     */
    protected $queryTampered;

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
     * Prepared or simple statement.
     *
     * A simple statement might not be linked at all (MySQLi).
     *
     * @var mixed|null
     *      Extending class must override to annotate exact type.
     */
    protected $statement;

    /**
     * Will only have a single bucket, 'prepared' or 'simple'.
     * Array because that allows unsetting.
     *
     * Prepared statement arguments are referred, to reflect changes
     * (from outside).
     *
     * Simple statement arguments may not be linked at all (MySQLi).
     *
     * @var array {
     *      @var array &$prepared  If prepared statement.
     *      @var array $simple  If simple statement.
     * }
     */
    protected $arguments = [];

    /**
     * @var boolean|null
     */
    protected $statementClosed;

    /**
     * @param DbClientInterface|DatabaseClient $client
     *      Reference to parent client.
     * @param string $baseQuery
     * @param array $options {
     *      @var bool $is_multi_query
     *          True: arg $baseQuery contains multiple queries.
     * }
     *
     * @throws \InvalidArgumentException
     *      Arg $query empty.
     * @throws DbLogicalException
     *      True $options['is_multi_query'] and query class doesn't support it.
     */
    public function __construct(DbClientInterface $client, string $baseQuery, array $options = [])
    {
        $this->client = $client;

        if (!$baseQuery) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $baseQuery cannot be empty.'
            );
        }
        // Remove trailing (and leading) semicolon; for multi-query.
        $this->query = trim($baseQuery, " \t\n\r\0\x0B;");

        // The $isMulti parameter is needed because we cannot safely deduct
        // that a query is multi-query contains semicolon.
        // False positive if the query contains literal parameter value
        // and that value contains semicolon.

        if (!empty($options['is_multi_query'])) {
            if (!static::MULTI_QUERY_SUPPORT) {
                throw new DbLogicalException(
                    $this->client->errorMessagePrefix() . ' doesn\'t support multi-query.'
                );
            }
            $this->isMultiQuery = true;
        }
    }

    /**
     * Turn query into server-side prepared statement and bind parameters.
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
     *
     * @return $this|DbQueryInterface
     *
     * @throws \SimpleComplex\Database\Exception\DbConnectionException
     *      Propagated.
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    abstract public function prepare(string $types, array &$arguments) : DbQueryInterface;

    /**
     * Non-prepared statement: set query arguments, for native automated
     * parameter marker substitution or direct substition in the query.
     *
     * The base query remains reusable allowing more ->parameters()->execute(),
     * much like a prepared statement (except arguments aren't referred).
     *
     * An $arguments bucket must be integer|float|string|binary,
     * unless database-specific behaviour (Sqlsrv type qualifying array).
     *
     * Arg $types types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * Query parameter marker is typically question mark:
     * @see DatabaseQuery::QUERY_PARAMETER
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
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     *      Arg $types length (unless empty) doesn't match number of parameters.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        // Reset; secure base query reusability.
        $this->queryTampered = null;

        if ($this->isRepeatStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePrefix()
                . ' - passing parameters to base query is illegal when base query has been repeated.'
            );
        }
        if ($this->queryAppended) {
            throw new DbLogicalException(
                $this->client->errorMessagePrefix()
                . ' - passing parameters to base query is illegal after another query has been appended.'
            );
        }
        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new DbLogicalException(
                $this->client->errorMessagePrefix()
                . ' - passing parameters to prepared statement is illegal except via call to prepare().'
            );
        }

        if ($types !== '' && ($type_illegals = $this->parameterTypesCheck($types))) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix()
                . ' - arg $types contains illegal char(s) ' . $type_illegals . '.'
            );
        }

        // Checks for parameters/arguments count mismatch.
        $query_fragments = $this->queryFragments($this->query, $arguments);

        if ($query_fragments) {
            $this->queryTampered = $this->substituteParametersByArgs($query_fragments, $types, $arguments);
        }

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
     *      Propagated; parameters/arguments count mismatch.
     */
    public function append(string $query, string $types, array $arguments) : DbQueryInterface
    {
        if (!static::MULTI_QUERY_SUPPORT) {
            throw new DbLogicalException(
                $this->client->errorMessagePrefix() . ' doesn\'t support multi-query.'
            );
        }
        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new DbLogicalException(
                $this->client->errorMessagePrefix() . ' - appending to prepared statement is illegal.'
            );
        }

        if (!$query) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $query cannot be empty.'
            );
        }

        $this->isMultiQuery = $this->queryAppended = true;

        if (!$this->queryTampered) {
            // First time appending.
            $this->queryTampered = $this->query;
        }

        // Checks for parameters/arguments count mismatch.
        $query_fragments = $this->queryFragments($query, $arguments);

        $this->queryTampered .= '; ' . (
            !$query_fragments ? $query :
                $this->substituteParametersByArgs($query_fragments, $types, $arguments)
            );

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
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     */
    public function repeat(string $types, array $arguments) : DbQueryInterface
    {
        if (!static::MULTI_QUERY_SUPPORT) {
            throw new DbLogicalException(
                $this->client->errorMessagePrefix() . ' doesn\'t support multi-query.'
            );
        }
        if ($this->queryAppended) {
            throw new DbLogicalException(
                $this->client->errorMessagePrefix()
                . ' - repeating base query is illegal after another query has been appended.'
            );
        }
        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new DbLogicalException(
                $this->client->errorMessagePrefix() . ' - appending to prepared statement is illegal.'
            );
        }

        // Checks for parameters/arguments count mismatch.
        $query_fragments = $this->queryFragments($this->query, $arguments);

        $repeated_query = !$query_fragments ? $this->query :
            $this->substituteParametersByArgs($query_fragments, $types, $arguments);

        if (!$this->queryTampered) {
            // Not necessarily multi-query yet.

            $this->queryTampered = $repeated_query;
        }
        else {
            $this->isMultiQuery = $this->isRepeatStatement = true;

            $this->queryTampered .= '; ' . $repeated_query;
        }

        return $this;
    }

    /**
     * Flag that the query contains LIKE clause(s).
     *
     * Affects parameter escaping: chars %_ won't be escaped.
     * @see DatabaseQuery::escapeString()
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
     * Splits a query by parameter flags and checks that number of arguments
     * matches number of parameters.
     *
     * @see DatabaseQuery::QUERY_PARAMETER
     *
     * @param string $query
     * @param array $arguments
     *
     * @return array
     *      Empty: query contains no parameter flags.
     *
     * @throws \InvalidArgumentException
     *      Arg $arguments length doesn't match number of parameters.
     */
    public function queryFragments(string $query, array $arguments) : array
    {
        $fragments = explode(static::QUERY_PARAMETER, $query);
        $n_params = count($fragments) - 1;
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $arguments length[' . $n_args
                . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
            );
        }
        return $n_params ? $fragments : [];
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
     * Substitute query parameters markers by arguments.
     *
     * An $arguments bucket must be integer|float|string|binary.
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @see DatabaseQuery::QUERY_PARAMETER
     *
     * @param array $queryFragments
     *      A query string split by parameter marker.
     * @param string $types
     * @param array $arguments
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     *      Arg $types length (unless empty) doesn't match number of parameters.
     */
    protected function substituteParametersByArgs(array $queryFragments, string $types, array $arguments) : string
    {
        $n_params = count($queryFragments) - 1;

        $tps = $types;
        if ($tps === '') {
            // Be friendly, all strings.
            $tps = str_repeat('s', $n_params);
        }
        elseif (strlen($types) != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $types length[' . strlen($types)
                . '] doesn\'t match query\'s ?-parameters count[' . $n_params . '].'
            );
        }

        // Mend that arguments array may not be numerically indexed,
        // nor correctly indexed.
        $args = array_values($arguments);

        $query_with_args = '';
        for ($i = 0; $i < $n_params; ++$i) {
            $value = $args[$i];

            /**
             * Reject attempt to use array value.
             * No compatibility with Sqlsrv type qualifying array.
             * @see MsSqlQuery::prepare()
             */
            if (!is_scalar($value) || is_bool($value)) {
                // Unlikely when checked via parameterTypesCheck().
                throw new \InvalidArgumentException(
                    $this->client->errorMessagePrefix() . ' - arg $arguments index[' . $i
                    . '] type[' . gettype($value) . '] is not integer|float|string|binary.'
                );
            }

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
                        $this->client->errorMessagePrefix()
                        . ' - arg $types[' . $types . '] index[' . $i . '] char[' . $tps{$i} . '] is not i|d|s|b.'
                    );
            }
            $query_with_args .= $queryFragments[$i] . $value;
        }

        return $query_with_args . $queryFragments[$i];
    }


    // Package protected.-------------------------------------------------------

    /**
     * Unset external references.
     *
     * Before throwing exception and when closing a statement.
     *
     * @internal Package protected.
     *
     * @return void
     */
    public function unsetReferences() /*:void*/
    {
        // Prepared statement arguments refer.
        // If not unset, the point of reference could get messed up.
        if (isset($this->arguments['prepared'])) {
            unset($this->arguments['prepared']);
        }
    }


    // Explorable.--------------------------------------------------------------

    /**
     * List of names of members (private, protected or public which should be
     * exposed as accessibles in count()'ing and foreach'ing.
     *
     * Private/protected members are also be readable via 'magic' __get().
     *
     * @see DatabaseQuery::__get()
     *
     * @internal
     *
     * @var string[]
     */
    protected $explorableIndex = [
        // Protected; readable via 'magic' __get().
        'id',
        'isPreparedStatement',
        'isMultiQuery',
        'isRepeatStatement',
        'queryAppended',
        'hasLikeClause',
        'query',
        'queryTampered',
        'arguments',
        'statementClosed',
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
            // Set 'id' on demand.
            if ($name == 'id' && !$this->id) {
                $c = explode('.', $uni = uniqid('', TRUE));
                $utils = Utils::getInstance();
                $this->id = $utils->baseConvert($c[0], 16, 62) . $utils->baseConvert($c[1], 16, 62);
            }
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

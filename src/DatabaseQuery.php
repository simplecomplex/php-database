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
use SimpleComplex\Utils\Dependency;

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
 * An sql string containing 'CRUD; non-CRUD' (INSERT...; SELECT...) is not considered
 * a multi-query. And MS SQL supports such.
 *
 * Prepared statement vs. simple statement
 * ---------------------------------------
 * A prepared statement's arguments are referred and therefore changes will
 * reflect in later execution of the the statement.
 * A simple statement's arguments get consumed and one has to pass new arguments
 * for later execution of the statement.
 * Normally simple statements don't support automated (and safe) ?-parameter
 * substitution (argument value parsed into sql string). However this library
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
 * @property-read bool $sqlAppended
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
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
    const MULTI_QUERY_SUPPORT = false;

    /**
     * Char or string flagging an sql parameter,
     * to be substituted by an argument.
     *
     * Typically question mark (Postgresql uses dollar sign).
     *
     * @var string
     */
    const SQL_PARAMETER = '?';

    /**
     * Truncate sql to that length when logging.
     *
     * @int
     */
    const LOG_SQL_TRUNCATE = 8192;

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
    protected $sql;

    /**
     * Copy of instance var $sql manipulated in one more ways:
     * - parameter markers substituted by arguments
     * - another sql string has been appended
     * - the base sql repeated (with parameter markers substituted)
     *
     * Must be null when empty (not used).
     *
     * @var string|null
     */
    protected $sqlTampered;

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
    protected $sqlAppended = false;

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
     * @param string $sql
     * @param array $options {
     *      @var bool $is_multi_query
     *          True: arg $sql contains multiple queries.
     * }
     *
     * @throws \InvalidArgumentException
     *      Arg $sql empty.
     * @throws DbLogicalException
     *      True $options['is_multi_query'] and query class doesn't support it.
     */
    public function __construct(DbClientInterface $client, string $sql, array $options = [])
    {
        $this->client = $client;

        if (!$sql) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $sql cannot be empty.'
            );
        }
        // Remove trailing (and leading) semicolon; for multi-query.
        $this->sql = trim($sql, " \t\n\r\0\x0B;");

        // The 'is_multi_query' options is needed because we cannot safely deduct
        // that a query is multi-query contains semicolon.
        // False positive if the sql string contains literal parameter value
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
     * Non-prepared statement: set query arguments, for native automated
     * parameter marker substitution or direct substition in the sql string.
     *
     * The base sql remains reusable allowing more ->parameters()->execute(),
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
     * Sql parameter marker is typically question mark:
     * @see DatabaseQuery::SQL_PARAMETER
     *
     * @param string $types
     *      Empty: uses string for all.
     * @param array $arguments
     *      Values to substitute sql ?-parameters with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Base sql has been repeated.
     *      Another sql string has been appended to base sql.
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     *      Arg $types length (unless empty) doesn't match number of parameters.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->isRepeatStatement) {
            throw new DbLogicalException(
                $this->client->errorMessagePrefix()
                . ' - passing parameters to base sql is illegal when base sql has been repeated.'
            );
        }
        if ($this->sqlAppended) {
            throw new DbLogicalException(
                $this->client->errorMessagePrefix()
                . ' - passing parameters to base sql is illegal after another sql string has been appended.'
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

        // Reset; secure base sql reusability; @todo: needed?.
        $this->sqlTampered = null;

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sql, $arguments);

        if ($sql_fragments) {
            $this->sqlTampered = $this->substituteParametersByArgs($sql_fragments, $types, $arguments);
        }

        return $this;
    }

    /**
     * Append sql to previously defined sql.
     *
     * Non-prepared statement only.
     *
     * Turns the full query into multi-query.
     *
     * @param string $sql
     * @param string $types
     *      Empty: uses string for all.
     * @param array $arguments
     *      Values to substitute sql ?-parameters with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Query class doesn't support multi-query.
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Arg $sql empty.
     *      Propagated; parameters/arguments count mismatch.
     */
    public function append(string $sql, string $types, array $arguments) : DbQueryInterface
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

        if (!$sql) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $sql cannot be empty.'
            );
        }

        $this->isMultiQuery = $this->sqlAppended = true;

        if (!$this->sqlTampered) {
            // First time appending.
            $this->sqlTampered = $this->sql;
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($sql, $arguments);

        $this->sqlTampered .= '; ' . (
            !$sql_fragments ? $sql :
                $this->substituteParametersByArgs($sql_fragments, $types, $arguments)
            );

        return $this;
    }

    /**
     * Repeat base sql, and substitute it's ?-parameters by arguments.
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
     *      Values to substitute sql ?-parameters with.
     *
     * @return $this|DbQueryInterface
     *
     * @throws DbLogicalException
     *      Query class doesn't support multi-query.
     *      Another sql string has been appended to base sql.
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
        if ($this->sqlAppended) {
            throw new DbLogicalException(
                $this->client->errorMessagePrefix()
                . ' - repeating base sql is illegal after another sql string has been appended.'
            );
        }
        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new DbLogicalException(
                $this->client->errorMessagePrefix() . ' - appending to prepared statement is illegal.'
            );
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sql, $arguments);

        $repeated_query = !$sql_fragments ? $this->sql :
            $this->substituteParametersByArgs($sql_fragments, $types, $arguments);

        if (!$this->sqlTampered) {
            // Not necessarily multi-query yet.

            $this->sqlTampered = $repeated_query;
        }
        else {
            $this->isMultiQuery = $this->isRepeatStatement = true;

            $this->sqlTampered .= '; ' . $repeated_query;
        }

        return $this;
    }

    /**
     * Flag that the sql contains LIKE clause(s).
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
     * Splits an sql string by parameter flags and checks that
     * number of arguments matches number of parameters.
     *
     * @see DatabaseQuery::SQL_PARAMETER
     *
     * @param string $sql
     * @param array $arguments
     *
     * @return array
     *      Empty: sql contains no parameter flags.
     *
     * @throws \InvalidArgumentException
     *      Arg $arguments length doesn't match number of parameters.
     */
    public function sqlFragments(string $sql, array $arguments) : array
    {
        $fragments = explode(static::SQL_PARAMETER, $sql);
        $n_params = count($fragments) - 1;
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $arguments length[' . $n_args
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
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
     * Substitute sql parameters markers by arguments.
     *
     * An $arguments bucket must be integer|float|string|binary.
     *
     * Types:
     * - i: integer.
     * - d: float (double).
     * - s: string.
     * - b: blob.
     *
     * @see DatabaseQuery::SQL_PARAMETER
     *
     * @param array $sqlFragments
     *      An sql string split by parameter marker.
     * @param string $types
     * @param array $arguments
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     *      Arg $types length (unless empty) doesn't match number of parameters.
     */
    protected function substituteParametersByArgs(array $sqlFragments, string $types, array $arguments) : string
    {
        $n_params = count($sqlFragments) - 1;

        $tps = $types;
        if ($tps === '') {
            // Be friendly, all strings.
            $tps = str_repeat('s', $n_params);
        }
        elseif (strlen($types) != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $types length[' . strlen($types)
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
            );
        }

        // Mend that arguments array may not be numerically indexed,
        // nor correctly indexed.
        $args = array_values($arguments);

        $sql_with_args = '';
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
            $sql_with_args .= $sqlFragments[$i] . $value;
        }

        return $sql_with_args . $sqlFragments[$i];
    }


    // Package protected.-------------------------------------------------------

    /**
     * @internal Package protected.
     *
     * @return string
     */
    public function errorMessagePrefix() : string
    {
        return $this->client->errorMessagePrefix() . '[' . $this->__get('id') . ']';
    }

    /**
     * Log query self or just the active sql string.
     *
     * @param string $method
     * @param bool $sqlOnly
     */
    public function log(string $method, bool $sqlOnly = false)
    {
        $sql_only = $sqlOnly;
        if (!$sql_only) {
            try {
                $sql_only = !Dependency::container()->has('inspect');
            } catch (\Throwable $ignore) {
                $sql_only = false;
            }
        }
        $this->client->log(
            $this->errorMessagePrefix() . ' - ' . $method . '(), ' . (!$sqlOnly ? 'query' : 'sql') . ':',
            !$sql_only ? $this : substr(
                $this->sqlTampered ?? $this->sql,
                0,
                static::LOG_SQL_TRUNCATE
            ),
            [
                'wrappers' => 1,
            ]
        );
    }

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
        'sqlAppended',
        'hasLikeClause',
        'sql',
        'sqlTampered',
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

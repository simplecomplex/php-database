<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Utils\Explorable;
use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;

/**
 * Database query.
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
 * @property-read int $execution
 * @property-read string $cursorMode
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 * @property-read bool $transactionStarted  Value of client ditto.
 *
 * @package SimpleComplex\Database
 */
abstract class DatabaseQuery extends Explorable implements DbQueryInterface
{
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
     * Remove trailing (and leading) semicolon,
     * to prevent appearance as more queries.
     *
     * @see DatabaseQuery::__construct()
     *
     * @var string
     */
    const SQL_TRIM = " \t\n\r\0\x0B;";

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
     * @var int
     */
    protected $execution = -1;

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
     * Create a query.
     *
     * Options affected_rows and num_rows may override default
     * cursor mode (not option cursor_mode) and adjust to support result
     * affectedRows/insertId/numRows().
     *
     * For more options, see:
     * @see MariaDbQuery::__construct()
     * @see MsSqlQuery::__construct()
     *
     * @param DbClientInterface|DatabaseClient $client
     *      Reference to parent client.
     * @param string $sql
     * @param array $options {
     *      @var string $cursor_mode
     *      @var bool $affected_rows
     *      @var bool $num_rows
     * }
     *
     * @throws \InvalidArgumentException
     *      Arg $sql effectively empty.
     */
    public function __construct(DbClientInterface $client, string $sql, array $options = [])
    {
        $this->client = $client;
        $this->sql = trim($sql, static::SQL_TRIM);
        if (!$this->sql) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $sql length[' . strlen($sql) . '] is effectively empty.'
            );
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
     * Chainable.
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
     *      Values to substitute sql parameter markers with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryInterface
     *
     * @throws \LogicException
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     *      Arg $types length (unless empty) doesn't match number of parameters.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new \LogicException(
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
     * Flag that the sql contains LIKE clause(s).
     *
     * Affects parameter escaping: chars %_ won't be escaped.
     * @see DatabaseQuery::escapeString()
     *
     * Chainable.
     *
     * @return $this|DbQueryInterface
     */
    public function hasLikeClause() : DbQueryInterface
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
     * @param string $str
     *
     * @return string
     */
    public function escapeString(string $str) : string
    {
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

        return '' . preg_replace($this->escapeStringPattern, '\\\$0', $str);
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

    /**
     * Close query statement, and log.
     *
     * @param string $method
     *
     * @return void
     */
    protected function closeAndLog(string $method) /*: void*/
    {
        $this->close();
        $this->log($method, false, 1);
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
     * @param int $wrappers
     */
    public function log(string $method, bool $sqlOnly = false, int $wrappers = 0)
    {
        $sql_only = $sqlOnly ? true : !Dependency::container()->has('inspect');
        $this->client->log(
            $this->errorMessagePrefix() . ' - ' . $method . '(), ' . (!$sqlOnly ? 'query' : 'sql') . ':',
            !$sql_only ? $this : substr(
                $this->sqlTampered ?? $this->sql,
                0,
                static::LOG_SQL_TRUNCATE
            ),
            [
                'wrappers' => $wrappers + 1,
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
    public function unsetReferences() /*: void*/
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
        'execution',
        'cursorMode',
        'isPreparedStatement',
        'hasLikeClause',
        'sql',
        'sqlTampered',
        'arguments',
        'statementClosed',
        // Value of client ditto.
        'transactionStarted',
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
            switch ($name) {
                case 'id':
                    // Set 'id' on demand.
                    if (!$this->id) {
                        $c = explode('.', $uni = uniqid('', TRUE));
                        $utils = Utils::getInstance();
                        $this->id = $utils->baseConvert($c[0], 16, 62) . $utils->baseConvert($c[1], 16, 62);
                    }
                    break;
                case 'transactionStarted':
                    return $this->client->transactionStarted;
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

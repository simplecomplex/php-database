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
use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Dependency;
use SimpleComplex\Validate\Validate;

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
 * @see DbQuery::parameters()
 *
 * CRUD vs non-CRUD statements
 * ---------------
 * CRUD: (at least) INSERT, UPDATE, REPLACE, DELETE.
 * Non-CRUD: (at least) SELECT, DESCRIBE, EXPLAIN, HELP, USE.
 *
 * @property-read string $id
 * @property-read int $execution
 * @property-read string $resultMode
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 * @property-read bool $transactionStarted  Value of client ditto.
 * @property-read int $validateArguments
 *
 * @package SimpleComplex\Database
 */
abstract class DbQuery extends Explorable implements DbQueryInterface
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
     * List of supported parameter type characters.
     *
     * Types:
     * - i: integer
     * - d: float
     * - s: string
     * - b: binary
     *
     * @see DbQueryInterface::prepare()
     * @see DbQuery::parameters()
     *
     * @var string[]
     */
    const PARAMETER_TYPE_CHARS = [
        'i',
        'd',
        's',
        'b',
    ];

    /**
     * List of class names of objects that automatically gets stringed,
     * and accepted as strings, by the DBMS driver.
     *
     * @var string[]
     */
    const AUTO_STRINGABLE_CLASSES = [];

    /**
     * Remove trailing (and leading) semicolon,
     * to prevent appearance as more queries.
     *
     * @see DbQuery::__construct()
     *
     * @var string
     */
    const SQL_TRIM = " \t\n\r\0\x0B;";

    /**
     * Whether to minify the base sql string.
     *
     * Option (bool) sql_minify overrules.
     *
     * Defaults to false because costly; uses regular expressions.
     *
     * @see DbQuery::sqlMinify()
     *
     * @var bool
     */
    const SQL_MINIFY = false;

    /**
     * Whether to validate query arguments.
     *
     * Values:
     * - 0: no check
     * - 1: validate prepare()/parameters() methods' $types
     * - 2: validate prepare()/parameters() methods' $arguments against $types
     * - 3: validate prepared statement arguments at every execute()
     *
     * Option (int) validate_arguments overrules.
     *
     * @see DbQuery::validateArguments()
     */
    const VALIDATE_ARGUMENTS = 1;

    /**
     * Truncate sql to that length when logging.
     *
     * @int
     */
    const LOG_SQL_TRUNCATE = 8192;

    /**
     * Query options allowed by any implementation.
     *
     * If any of these options isn't supported by an implementation,
     * it must be ignored; not cause an error.
     *
     * @var string[]
     */
    const OPTIONS_GENERIC = [
        'sql_minify',
        'validate_arguments',
        'result_mode',
        'affected_rows',
        'insert_id',
        'num_rows',
        'query_timeout',
    ];

    /**
     * RMDBS specific query options supported, adding to generic options.
     *
     * @var string[]
     */
    const OPTIONS_SPECIFIC = [];

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var DbClient
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
     * @var int
     */
    protected $validateArguments;

    /**
     * @var Validate
     */
    protected $validate;

    /**
     * Create a query.
     *
     * Allowed options:
     * @see DbQuery::OPTIONS_GENERIC
     * @see MariaDbQuery::OPTIONS_SPECIFIC
     * @see MsSqlQuery::OPTIONS_SPECIFIC
     *
     * Actual use of options:
     * @see MariaDbQuery::__construct()
     * @see MsSqlQuery::__construct()
     *
     * @param DbClientInterface|DbClient $client
     *      Reference to parent client.
     * @param string $sql
     * @param array $options
     *
     * @throws \InvalidArgumentException
     *      Arg $sql effectively empty.
     * @throws \LogicException
     *      Arg $options contains illegal option.
     */
    public function __construct(DbClientInterface $client, string $sql, array $options = [])
    {
        $this->client = $client;

        $this->sql = trim($sql, static::SQL_TRIM);
        if (!$this->sql) {
            throw new \InvalidArgumentException(
                $this->client->messagePrefix() . ' - arg $sql length[' . strlen($sql) . '] is effectively empty.'
            );
        }
        if ($options['sql_minify'] ?? static::SQL_MINIFY) {
            $this->sql = $this->sqlMinify($sql);
        }

        $this->validateArguments = isset($options['validate_arguments']) ? (int) $options['validate_arguments'] :
            static::VALIDATE_ARGUMENTS;

        if ($options) {
            $specifics = array_diff(array_keys($options), static::OPTIONS_GENERIC);
            if ($specifics) {
                $illegals = array_diff($specifics, static::OPTIONS_SPECIFIC);
                if ($illegals) {
                    throw new \LogicException(
                        $this->client->messagePrefix() . ' - query arg $options contains illegal options['
                        . join(', ', $illegals) . '].'
                    );
                }
            }
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
     * @see DbQuery::SQL_PARAMETER
     *
     * @param string $types
     *      Empty: uses $arguments' actual types.
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
                $this->client->messagePrefix()
                . ' - passing parameters to prepared statement is illegal except via call to prepare().'
            );
        }

        if (
            $this->validateArguments
            && ($types || $arguments)
            && ($valid = $this->validateArguments($types, $arguments, $this->validateArguments > 1)) !== true
        ) {
            throw new \InvalidArgumentException(
                $this->client->messagePrefix() . ' - ' . $valid . '.'
            );
        }

        // Reset; secure base sql reusability; @todo: needed?.
        $this->sqlTampered = null;

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sqlTampered ?? $this->sql, $arguments);

        if ($sql_fragments) {
            $this->sqlTampered = $this->substituteParametersByArgs($sql_fragments, $types, $arguments);
        }

        return $this;
    }

    /**
     * Flag that the sql contains LIKE clause(s).
     *
     * Affects parameter escaping: chars %_ won't be escaped.
     * @see DbQuery::escapeString()
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
     * Minify sql string.
     *
     * Transformations:
     * - remove carriage return
     * - remove leading line space
     * - remove line comment, except first line as comment
     * - convert newline to single space
     *
     * @param string $sql
     *
     * @return string
     */
    public function sqlMinify(string $sql) : string
    {
        return
            // Convert newline to space.
            str_replace(
                "\n",
                ' ',
                // remove line comment, except first line as comment.
                preg_replace(
                    '/\n\-\-[^\n]*/',
                    '',
                    // Remove leading line space.
                    preg_replace(
                        '/\n[ ]+/',
                        "\n",
                        // Remove carriage return.
                        str_replace("\r", '', $sql)
                    )
                )
            );
    }

    /**
     * Splits an sql string by parameter flags and checks that
     * number of arguments matches number of parameters.
     *
     * @see DbQuery::SQL_PARAMETER
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
                $this->client->messagePrefix() . ' - arg $arguments length[' . $n_args
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
            );
        }
        return $n_params ? $fragments : [];
    }

    /**
     * Validate type chars, optionally also that arguments match the types.
     *
     * Loose typings:
     * - 'i' integer allows stringed integer
     * - 'd' float allows integer and stringed number
     * - 's' string and 'b' binary allow any scalar but boolean
     *
     * @see DbQuery::PARAMETER_TYPE_CHARS
     *
     * @param string $types
     * @param array $arguments
     * @param bool $actualTypes
     *      True: validate $arguments' actual types againt $types.
     *
     * @return bool|string
     *      True on success.
     *      String error details message on error.
     */
    public function validateArguments(string $types, array $arguments, bool $actualTypes = false)
    {
        $n_types = strlen($types);
        if ($n_types != count($arguments)) {
            return 'arg $types length[' . strlen($types) . '] doesn\'t match arg $arguments length['
                . count($arguments) . ']';
        }

        // Probably faster than regular expression check.
        $invalids = str_replace(
            static::PARAMETER_TYPE_CHARS,
            '',
            $types
        );
        if ($invalids) {
            $invalids = [];
            for ($i = 0; $i < $n_types; ++$i) {
                if (!in_array($types{$i}, static::PARAMETER_TYPE_CHARS)) {
                    $invalids[] = 'index[' . $i . '] char[' . $types{$i} . ']';
                }
            }
            return 'arg $types invalid ' . join(', ', $invalids);
        }

        // @todo: separate actual type checking (and with $errOnFailure arg) to support validateArguments:3 (prepared st.)

        if ($actualTypes) {
            if (!$this->validate) {
                $this->validate = Validate::getInstance();
            }
            $invalids = [];
            $i = -1;
            foreach ($arguments as $value) {
                ++$i;
                switch ($types{$i}) {
                    case 'i':
                        if (!is_int($value)) {
                            if ($value === '') {
                                $invalids[] = 'index[' . $i . '] char[' . $types{$i}
                                    . '] empty string is neither integer nor stringed integer';
                                break;
                            }
                            // Allow stringed integer.
                            if (!is_string($value) || $this->validate->numeric($value) !== 'integer') {
                                $invalids[] = 'index[' . $i . '] char[' . $types{$i}
                                    . '] type[' . Utils::getType($value) . '] is neither integer nor stringed integer';
                            }
                        }
                        break;
                    case 'd':
                        if (!is_float($value)) {
                            if ($value === '') {
                                $invalids[] = 'index[' . $i . '] char[' . $types{$i}
                                    . '] empty string is neither number nor stringed number';
                                break;
                            }
                            // Allow stringed number.
                            if (!is_string($value) || !$this->validate->numeric($value)) {
                                $invalids[] = 'index[' . $i . '] char[' . $types{$i}
                                    . '] type[' . Utils::getType($value) . '] is neither number nor stringed number';
                            }
                        }
                        break;
                    case 's':
                    case 'b':
                        if (!is_string($value) && (!is_scalar($value) || is_bool($value))) {
                            $invalids[] = 'index[' . $i . '] char[' . $types{$i} . '] type[' . Utils::getType($value)
                                . '] is not string or scalar except boolean';
                        }
                        break;
                    default:
                        throw new \InvalidArgumentException(
                            $this->client->messagePrefix() . ' - arg $types index[' . $i . '] char[' . $types{$i}
                            . '] is not ' . join('|', static::PARAMETER_TYPE_CHARS) . '.'
                        );
                }
            }
            if ($invalids) {
                return 'args $types $arguments mismatch ' . join(', ', $invalids);
            }
        }

        return true;
    }

    /**
     * Create arguments type string based on arguments' actual types.
     *
     * Only supports string, integer, float.
     *
     * @param array $arguments
     * @param array $skipIndexes
     *      Skip detecting type of buckets at those indexes, setting underscore
     *      as mock type char at those indexes in the output type string.
     *
     * @return string
     */
    public function parameterTypesDetect(array $arguments, array $skipIndexes = []) : string
    {
        $types = '';
        $index = -1;
        foreach ($arguments as $value) {
            ++$index;
            if ($skipIndexes && in_array($index, $skipIndexes)) {
                $types .= '_';
            }
            else {
                $type = gettype($value);
                switch ($type) {
                    case 'string':
                        // Cannot discern binary from string.
                        $types .= 's';
                        break;
                    case 'integer':
                        $types .= 'i';
                        break;
                    case 'double':
                    case 'float':
                        $types .= 'd';
                        break;
                    default:
                        $auto_stringable_object = false;
                        if ($type == 'object' && static::AUTO_STRINGABLE_CLASSES) {
                            foreach (static::AUTO_STRINGABLE_CLASSES as $class_name) {
                                if (is_a($value, $class_name)) {
                                    $auto_stringable_object = true;
                                    $types .= 's';
                                    break;
                                }
                            }
                        }
                        if (!$auto_stringable_object) {
                            throw new \InvalidArgumentException(
                                $this->client->messagePrefix()
                                . ' - cannot detect parameter type char for arguments index[' . $index
                                . '], type[' . Utils::getType($value) . '] not supported.'
                            );
                        }
                }
            }
        }
        return $types;
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
     * @see DbQuery::SQL_PARAMETER
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
     * @throws \LogicException
     *      Method called when no parameters to substitute.
     */
    protected function substituteParametersByArgs(array $sqlFragments, string $types, array $arguments) : string
    {
        $n_params = count($sqlFragments) - 1;
        if ($n_params < 1) {
            throw new \LogicException(
                $this->client->messagePrefix() . ' - calling this method when no parameters is illegal.'
            );
        }

        $tps = $types;
        if ($tps === '') {
            // Detect types.
            $tps = $this->parameterTypesDetect($arguments);
        }
        elseif (strlen($types) != $n_params) {
            throw new \InvalidArgumentException(
                $this->client->messagePrefix() . ' - arg $types length[' . strlen($types)
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
             * No compatibility with Sqlsrv type qualifying array,
             * nor with non-scalar like DateTime.
             * @see MsSqlQuery::prepare()
             */
            if (!is_scalar($value) || is_bool($value)) {
                // Unlikely when checked via validateArguments().
                throw new \InvalidArgumentException(
                    $this->client->messagePrefix() . ' - arg $arguments index[' . $i
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
                    if (in_array($tps{$i}, static::PARAMETER_TYPE_CHARS)) {
                        // Unlikely if previous validateArguments().
                        throw new \InvalidArgumentException(
                            $this->client->messagePrefix()
                            . ' - arg $types[' . $types . '] index[' . $i . '] char[' . $tps{$i} . '] is not '
                            . join('|', static::PARAMETER_TYPE_CHARS) . '.'
                        );
                    }
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
    public function messagePrefix() : string
    {
        return $this->client->messagePrefix() . '[' . $this->__get('id') . ']';
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
            $this->messagePrefix() . ' - dump for erring ' . $method . '(), ' . (!$sqlOnly ? 'query' : 'sql') . ':',
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
     * @see DbQuery::__get()
     *
     * @internal
     *
     * @var string[]
     */
    protected $explorableIndex = [
        // Protected; readable via 'magic' __get().
        'id',
        'execution',
        'resultMode',
        'isPreparedStatement',
        'hasLikeClause',
        'sql',
        'sqlTampered',
        'arguments',
        'statementClosed',
        // Value of client ditto.
        'transactionStarted',
        'validateArguments',
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

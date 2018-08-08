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
use SimpleComplex\Database\Exception\DbQueryArgumentException;

/**
 * Database query.
 *
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
 *
 * Multi-query vs. batch query vs. stored procedure
 * ------------------------------------------------
 * A batch query contains more non-selecting queries.
 * A multi-query contains more selecting queries; producing result set.
 * Batch query is supported by all common RMDSs.
 * Multi-query is supported by MariaDB/MySQL and Postgresql.
 * A stored procedure can also produce more (selecting) result sets.
 * Using multi-query in production is probably a mistake. A prepared statement
 * calling a stored procedure is safer.
 *
 *
 * CRUD vs non-CRUD statements
 * ---------------------------
 * CRUD: (at least) INSERT, UPDATE, REPLACE, DELETE.
 * Non-CRUD: (at least) SELECT, DESCRIBE, EXPLAIN, HELP, USE.
 *
 *
 * @property-read string $name
 * @property-read string $id
 * @property-read int $nExecution
 * @property-read string $resultMode
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 * @property-read bool $transactionStarted  Value of client ditto.
 * @property-read int $validateParams
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
     * Remove trailing (and leading) semicolon,
     * to prevent appearance as more queries.
     *
     * @see DbQuery::__construct()
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
     * Whether the DBMS attempts to stringify objects, as query arguments.
     *
     * Values:
     * - 0: makes no attempt to stringify object, even if __toString() method
     * - 1: attempts to stringify without checking for __toString() method
     * - 2: stringifies if the object has a __toString() method
     *
     * Examples:
     * - MariaDb: 1, attempts to stringify recklessly
     * - MsSql: 0, doesn't attempt stringifying (except \DateTime to varchar)
     *
     * Prepared statement only; simple query (using parameter substitution)
     * only cares about/accepts object having toString() method.
     *
     * @developer
     * Do NOT make a means for stringifying objects having __toString().
     * Because it would only work for truly simple queries, using parameter
     * substitution.
     * Would not work for prepared statements, because one can't tamper with
     * theirs arguments, since they are referred.
     *
     * @var int
     */
    const AUTO_STRINGIFIES_OBJECT = 0;

    /**
     * List of class names of objects that automatically gets stringified,
     * and accepted as strings, by the DBMS driver
     *
     * Prepared statement only; simple query (using parameter substitution)
     * only cares about/accepts object having toString() method.
     *
     * @var string[]
     */
    const AUTO_STRINGABLE_CLASSES = [];

    /**
     * Query options allowed by any implementation.
     *
     * If any of these options isn't supported by an implementation,
     * it must be ignored; not cause an error.
     *
     * @var string[]
     */
    const OPTIONS_GENERIC = [
        'name',
        'sql_minify',
        'validate_params',
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
     * Whether, how and when to validate query parameters.
     *
     * Option (int) validate_params overrules.
     *
     * Recommended value by environment
     * --------------------------------
     * Production: 1 (VALIDATE_PARAMS_FAILURE)
     * Development/test: 2 (VALIDATE_PARAMS_ALWAYS) because great for debugging,
     * however horrible performance-wise.
     *
     * Gets evaluated as bitmask.
     *
     * @see DbQuery::validateArguments()
     */
    const VALIDATE_PARAMS = 1;

    /**
     * Validate query parameters on query failure (only).
     *
     * Bitmask value.
     */
    const VALIDATE_FAILURE = 1;

    /**
     * Validate query parameters during preparation.
     *
     * Bitmask value.
     */
    const VALIDATE_PREPARE = 2;

    /**
     * Validate query parameters before execution.
     *
     * Bitmask value.
     */
    const VALIDATE_EXECUTE = 4;

    /**
     * Check for parameter values that are non-stringable object,
     * before execution.
     *
     * AUTO_STRINGIFIES_OBJECT:1
     * If the DBMS attempts stringification without __toString() method check
     * (like MariaDb), this validation will fail when no __toString().
     *
     * AUTO_STRINGIFIES_OBJECT:0
     * If the DBMS doesn't attempt stringification at all, this validation
     * will fail when object (regardless of __toString()).
     *
     * Prepared statement only; simple query (using parameter substitution)
     * always checks if object has toString() method.
     *
     * Bitmask value.
     *
     * @see DbQuery::AUTO_STRINGIFIES_OBJECT
     */
    const VALIDATE_STRINGABLE_EXEC = 64;

    /**
     * Validate query parameters all-sorts:
     * - on creation
     * - before execution
     * - on query failure
     *
     * And do all other checks (like VALIDATE_PARAMS_STRINGABLE_EXEC)
     * if relevant and applicable for the DBMS context.
     *
     * Bitmask value.
     *
     * @see DbQuery::VALIDATE_PARAMS
     */
    const VALIDATE_ALWAYS = 127;

    /**
     * Ought to be protected, but too costly since result instance
     * may use it repetetively; via the query instance.
     *
     * @var DbClient
     */
    public $client;

    /**
     * Query name.
     *
     * Option 'name'.
     *
     * @var string
     */
    protected $name;

    /**
     * Random ID used for error handling, set on demand.
     *
     * @var string
     */
    protected $id;

    /**
     * @var int
     */
    protected $nExecution = 0;

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
     * Record of prepare()/parameters() $types, or likewise created
     * via type detection.
     * MsSql doesn't use this property at all, due to native typing regime.
     *
     * @var string
     */
    protected $parameterTypes;

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
    protected $validateParams;

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

        $this->name = $options['name'] ?? '';

        $this->sql = trim($sql, static::SQL_TRIM);
        if (!$this->sql) {
            throw new \InvalidArgumentException(
                $this->messagePrefix() . ' - arg $sql length[' . strlen($sql) . '] is effectively empty.'
            );
        }
        if ($options['sql_minify'] ?? static::SQL_MINIFY) {
            $this->sql = $this->sqlMinify($sql);
        }

        $this->validateParams = isset($options['validate_params']) ? (int) $options['validate_params'] :
            static::VALIDATE_PARAMS;

        if ($options) {
            $specifics = array_diff(array_keys($options), static::OPTIONS_GENERIC);
            if ($specifics) {
                $illegals = array_diff($specifics, static::OPTIONS_SPECIFIC);
                if ($illegals) {
                    throw new \LogicException(
                        $this->messagePrefix() . ' - query arg $options contains illegal options['
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
     * @throws DbQueryArgumentException
     *      Propagated; parameters/arguments count mismatch.
     *      Arg $types contains illegal char(s).
     *      Arg $types length (unless empty) doesn't match number of parameters.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new \LogicException(
                $this->messagePrefix()
                . ' - passing parameters to prepared statement is illegal except via call to prepare().'
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
     * @throws DbQueryArgumentException
     *      Arg $arguments length doesn't match number of parameters.
     */
    public function sqlFragments(string $sql, array $arguments) : array
    {
        $fragments = explode(static::SQL_PARAMETER, $sql);
        $n_params = count($fragments) - 1;
        $n_args = count($arguments);
        if ($n_args != $n_params) {
            throw new DbQueryArgumentException(
                $this->messagePrefix() . ' - arg $arguments length[' . $n_args
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
            );
        }
        return $n_params ? $fragments : [];
    }

    /**
     * Validate type chars.
     *
     * @param string $types
     *
     * @return bool|string
     *      True on success.
     *      String error details message on error.
     */
    public function validateTypes(string $types)
    {
        $n_types = strlen($types);
        if ($n_types) {
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
                return join(', ', $invalids);
            }
        }
        return true;
    }

    /**
     * Validate that arguments matches types.
     *
     * Loose typings:
     * - 'i' integer allows stringed integer
     * - 'd' float allows integer and stringed number
     * - 's' string and 'b' binary allow any scalar but boolean
     *
     * @see DbQuery::PARAMETER_TYPE_CHARS
     * @see DbQuery::VALIDATE_PARAMS
     *
     * @param string $types
     * @param array $arguments
     * @param string $errorContext
     *      Non-empty: throw exception on validation failure.
     *      Values: prepare|execute|failure
     *
     * @return bool|string
     *      True on success.
     *      String error details message on error.
     *
     * @throws DbQueryArgumentException
     *      If validation failure and non-empty arg $errorContext.
     *      Unconditionally if $types and $arguments differ in length.
     *      Unconditionally if a $types char is unsupported.
     */
    public function validateArguments(string $types, array $arguments, string $errorContext = '')
    {
        if (strlen($types) != count($arguments)) {
            return $this->messagePrefix() . ' - arg $types length[' . strlen($types)
                . '] doesn\'t match arg $arguments length[' . count($arguments) . ']';
        }
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
                    if (!is_float($value) && !is_int($value)) {
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
                    // Camnot discern binary from non-binary string.
                    if (!is_string($value)) {
                        $valid = false;
                        switch (gettype($value)) {
                            case 'integer':
                            case 'double':
                            case 'float':
                                $valid = true;
                                break;
                            case 'object':
                                if (!$this->isPreparedStatement) {
                                    // Parameter substition only cares about
                                    // __toString().
                                    if (method_exists($value, '__toString')) {
                                        $valid = true;
                                    };
                                }
                                else {
                                    if (static::AUTO_STRINGIFIES_OBJECT && method_exists($value, '__toString')) {
                                        $valid = true;
                                    }
                                    elseif (static::AUTO_STRINGABLE_CLASSES) {
                                        foreach (static::AUTO_STRINGABLE_CLASSES as $class_name) {
                                            if (is_a($value, $class_name)) {
                                                $valid = true;
                                                break;
                                            }
                                        }
                                    }
                                }
                                break;
                        }
                        if (!$valid) {
                            $invalids[] = 'index[' . $i . '] char[' . $types{$i} . '] type[' . Utils::getType($value)
                                . '] is not string, integer, float or stringable object';
                        }
                    }
                    break;
                default:
                    throw new DbQueryArgumentException(
                        $this->messagePrefix() . ' - arg $types index[' . $i . '] char[' . $types{$i}
                        . '] is not ' . join('|', static::PARAMETER_TYPE_CHARS) . '.'
                    );
            }
        }
        if ($invalids) {
            if ($errorContext) {
                switch ($errorContext) {
                    case 'prepare':
                        $msg = ' - arg $arguments ';
                        break;
                    case 'execute':
                        $msg = $this->isPreparedStatement ?
                            (' - aborted prepared statement execution[' . $this->nExecution . '], argument ') :
                            ' - aborted simple query execution, argument ';
                        break;
                    default:
                        $msg = ' - argument ';
                }
                throw new DbQueryArgumentException(
                    $this->messagePrefix() . $msg . join(' | ', $invalids) . '.'
                );
            }
            return join(' | ', $invalids);
        }

        return true;
    }

    /**
     * Validate that string|binary arguments aren't non-stringable object.
     *
     * @see DbQuery::VALIDATE_PARAMS
     *
     * @param string $types
     * @param array $arguments
     * @param string $errorContext
     *      Non-empty: throw exception on validation failure.
     *      Values: prepare|execute|failure
     *
     * @return bool|string
     *      True on success.
     *      String error details message on error.
     *
     * @throws DbQueryArgumentException
     *      If validation failure and non-empty arg $errorContext.
     *      Unconditionally if $types and $arguments differ in length.
     */
    public function validateArgumentsStringable(string $types, array $arguments, string $errorContext = '')
    {
        if (strlen($types) != count($arguments)) {
            return $this->messagePrefix() . ' - arg $types length[' . strlen($types)
                . '] doesn\'t match arg $arguments length[' . count($arguments) . ']';
        }
        if (!$this->validate) {
            $this->validate = Validate::getInstance();
        }
        $invalids = [];
        $i = -1;
        foreach ($arguments as $value) {
            ++$i;
            // Camnot discern binary from non-binary string.
            if ($types{$i} == 's' || $types{$i} == 'b') {
                if (is_object($value)) {
                    $valid = false;
                    if (static::AUTO_STRINGIFIES_OBJECT && method_exists($value, '__toString')) {
                        $valid = true;
                    }
                    elseif (static::AUTO_STRINGABLE_CLASSES) {
                        foreach (static::AUTO_STRINGABLE_CLASSES as $class_name) {
                            if (is_a($value, $class_name)) {
                                $valid = true;
                                break;
                            }
                        }
                    }
                    if (!$valid) {
                        $invalids[] = 'index[' . $i . '] char[' . $types{$i} . '] type[' . Utils::getType($value)
                            . '] is not stringable object';
                    }
                }
            }
        }
        if ($invalids) {
            if ($errorContext) {
                switch ($errorContext) {
                    case 'prepare':
                        $msg = ' - arg $arguments ';
                        break;
                    case 'execute':
                        $msg = $this->isPreparedStatement ?
                            (' - aborted prepared statement execution[' . $this->nExecution . '], argument ') :
                            ' - aborted simple query execution, argument ';
                        break;
                    default:
                        $msg = ' - argument ';
                }
                throw new DbQueryArgumentException(
                    $this->messagePrefix() . $msg . join(' | ', $invalids) . '.'
                );
            }
            return join(' | ', $invalids);
        }

        return true;
    }

    /**
     * Create arguments type string based on arguments' actual types.
     *
     * Only supports string, integer, float, and stringable classes.
     *
     * @see DbQuery::AUTO_STRINGIFIES_OBJECT
     * @see DbQuery::AUTO_STRINGABLE_CLASSES
     *
     * @param array $arguments
     * @param array $skipIndexes
     *      Skip detecting type of buckets at those indexes, setting underscore
     *      as mock type char at those indexes in the output type string.
     *
     * @return string
     *
     * @throws DbQueryArgumentException
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
                        if ($type == 'object') {
                            if (static::AUTO_STRINGIFIES_OBJECT && method_exists($value, '__toString')) {
                                $auto_stringable_object = true;
                            }
                            elseif (static::AUTO_STRINGABLE_CLASSES) {
                                foreach (static::AUTO_STRINGABLE_CLASSES as $class_name) {
                                    if (is_a($value, $class_name)) {
                                        $auto_stringable_object = true;
                                        $types .= 's';
                                        break;
                                    }
                                }
                            }
                        }
                        if (!$auto_stringable_object) {
                            throw new DbQueryArgumentException(
                                $this->messagePrefix()
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
     * @throws DbQueryArgumentException
     *      Arg $types length (unless empty) doesn't match number of parameters.
     *      On $types or $arguments validation failure
     * @throws \LogicException
     *      Method called when no parameters to substitute.
     */
    protected function substituteParametersByArgs(array $sqlFragments, string $types, array $arguments) : string
    {
        $n_params = count($sqlFragments) - 1;
        if ($n_params < 1) {
            throw new \LogicException(
                $this->messagePrefix() . ' - calling this method when no parameters is illegal.'
            );
        }

        $tps = $types;
        if ($tps === '') {
            // Detect types.
            $tps = $this->parameterTypesDetect($arguments);
        }
        elseif (strlen($types) != $n_params) {
            throw new DbQueryArgumentException(
                $this->messagePrefix() . ' - arg $types length[' . strlen($types)
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
            );
        }
        elseif (count($arguments) != $n_params) {
            throw new DbQueryArgumentException(
                $this->messagePrefix() . ' - arg $arguments length[' . count($arguments)
                . '] doesn\'t match sql\'s ?-parameters count[' . $n_params . '].'
            );
        }
        else if (($this->validateParams & DbQuery::VALIDATE_PREPARE)) {
            if (($valid = $this->validateTypes($types)) !== true) {
                throw new DbQueryArgumentException(
                    $this->messagePrefix() . ' - arg $types ' . $valid . '.'
                );
            }
            // Throws exception on validation failure.
            $this->validateArguments($types, $arguments, 'prepare');
        }

        // Mend that arguments array may not be numerically indexed,
        // nor correctly indexed.
        $args = array_values($arguments);

        $sql_with_args = '';
        for ($i = 0; $i < $n_params; ++$i) {
            $value = $args[$i];
            // Validate always; could be hazardous on invalid value.
            if (!is_scalar($value) || is_bool($value)) {
                if (!is_object($value) || !method_exists($value, '__toString')) {
                    throw new DbQueryArgumentException(
                        $this->messagePrefix() . ' - arg $arguments index[' . $i . '] type[' . Utils::getType($value)
                        . '] is not integer, float, string or object having __toString() method.'
                    );
                }
            }
            switch ($tps{$i}) {
                case 's':
                case 'b':
                    $value = "'" . $this->escapeString('' . $value) . "'";
                    break;
                case 'i':
                case 'd':
                    break;
                default:
                    if (in_array($tps{$i}, static::PARAMETER_TYPE_CHARS)) {
                        // Unlikely if previous validateArguments().
                        throw new DbQueryArgumentException(
                            $this->messagePrefix()
                            . ' - arg $types[' . $types . '] index[' . $i . '] char[' . $tps{$i} . '] is not '
                            . join('|', static::PARAMETER_TYPE_CHARS) . '.'
                        );
                    }
            }
            $sql_with_args .= $sqlFragments[$i] . $value;
        }

        // Record types and arguments for parameter validation before execution
        // and/or on query failure.
        if ($this->validateParams) {
            $this->parameterTypes = $tps;
            $this->arguments['simple'] =& $args;
        }

        return $sql_with_args . $sqlFragments[$i];
    }

    /**
     * Close query statement, and log.
     *
     * In reverse order, that is; to secure arguments in log.
     *
     * @param string $method
     *
     * @return void
     */
    protected function closeAndLog(string $method) /*: void*/
    {
        // Log before closing, to be able to list arguments.
        $this->log($method, false, 1);
        $this->close();
    }


    // Package protected.-------------------------------------------------------

    /**
     * @internal Package protected.
     *
     * @return string
     */
    public function messagePrefix() : string
    {
        return $this->client->messagePrefix()
            . (!$this->name ? '' : ('[' . $this->name . ']'))
            . '[' . $this->__get('id') . ']';
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
        'name',
        'id',
        'nExecution',
        'resultMode',
        'isPreparedStatement',
        'hasLikeClause',
        'sql',
        'sqlTampered',
        'arguments',
        'statementClosed',
        // Value of client ditto.
        'transactionStarted',
        'validateParams',
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

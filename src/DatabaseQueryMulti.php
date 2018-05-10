<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbClientInterface;
use SimpleComplex\Database\Interfaces\DbQueryInterface;
use SimpleComplex\Database\Interfaces\DbQueryMultiInterface;

/**
 * Database query supporting multi-query.
 *
 * For multi-query explanation, see:
 * @see DbClientMultiInterface
 *
 * Inherited properties:
 * @property-read string $id
 * @property-read bool $isPreparedStatement
 * @property-read bool $hasLikeClause
 * @property-read string $sql
 * @property-read string $sqlTampered
 * @property-read array $arguments
 * @property-read bool|null $statementClosed
 * @property-read bool $transactionStarted  Value of client ditto.
 *
 * Own properties:
 * @property-read bool $isMultiQuery
 * @property-read bool $isRepeatStatement
 * @property-read bool $sqlAppended
 *
 * @package SimpleComplex\Database
 */
abstract class DatabaseQueryMulti extends DatabaseQuery implements DbQueryMultiInterface
{
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
     * @param DbClientInterface|DatabaseClientMulti $client
     *      Reference to parent (DbQueryMultiInterface) client.
     * @param string $sql
     * @param array $options {
     *      @var bool $is_multi_query
     *          True: arg $sql contains multiple queries.
     * }
     *
     * @throws \InvalidArgumentException
     *      Propagated; arg $sql empty.
     */
    public function __construct(DbClientInterface $client, string $sql, array $options = [])
    {
        parent::__construct($client, $sql, $options);

        $this->isMultiQuery = !empty($options['is_multi_query']);

        $this->explorableIndex[] = 'isMultiQuery';
        $this->explorableIndex[] = 'isRepeatStatement';
        $this->explorableIndex[] = 'sqlAppended';
    }

    /**
     * Non-prepared statement: set query arguments, for native automated
     * parameter marker substitution or direct substition in the sql string.
     *
     * @see DatabaseQuery::parameters()
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
     *      Base sql has been repeated.
     *      Another sql string has been appended to base sql.
     *      Propagated.
     * @throws \InvalidArgumentException
     *      Propagated.
     */
    public function parameters(string $types, array $arguments) : DbQueryInterface
    {
        if ($this->isRepeatStatement) {
            throw new \LogicException(
                $this->client->errorMessagePrefix()
                . ' - passing parameters to base sql is illegal when base sql has been repeated.'
            );
        }
        if ($this->sqlAppended) {
            throw new \LogicException(
                $this->client->errorMessagePrefix()
                . ' - passing parameters to base sql is illegal after another sql string has been appended.'
            );
        }

        return parent::parameters($types, $arguments);
    }

    /**
     * Non-prepared statement: repeat base sql, and substitute it's parameter
     * markers by arguments.
     *
     * Turns the full query into multi-query.
     *
     * Chainable.
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
     *      Values to substitute sql parameter markers with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryMultiInterface
     *
     * @throws \LogicException
     *      Query class doesn't support multi-query.
     *      Another sql string has been appended to base sql.
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Propagated; parameters/arguments count mismatch.
     */
    public function repeat(string $types, array $arguments) : DbQueryMultiInterface
    {
        if ($this->sqlAppended) {
            throw new \LogicException(
                $this->client->errorMessagePrefix()
                . ' - repeating base sql is illegal after another sql string has been appended.'
            );
        }
        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new \LogicException(
                $this->client->errorMessagePrefix() . ' - appending to prepared statement is illegal.'
            );
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($this->sql, $arguments);

        $repeated_query = !$sql_fragments ? $this->sql :
            $this->substituteParametersByArgs($sql_fragments, $types, $arguments);

        if (!$this->sqlTampered) {
            // Not already multi-query.
            $this->sqlTampered = $repeated_query;
        }
        $this->isMultiQuery = $this->isRepeatStatement = true;
        $this->sqlTampered .= '; ' . $repeated_query;

        return $this;
    }

    /**
     * Append sql to previously defined sql.
     *
     * Non-prepared statement only.
     *
     * Turns the full query into multi-query.
     *
     * Chainable.
     *
     * @param string $sql
     * @param string $types
     *      Empty: uses string for all.
     * @param array $arguments
     *      Values to substitute sql parameter markers with.
     *      Arguments are consumed once, not referred.
     *
     * @return $this|DbQueryMultiInterface
     *
     * @throws \LogicException
     *      Query is prepared statement.
     * @throws \InvalidArgumentException
     *      Arg $sql empty.
     *      Propagated; parameters/arguments count mismatch.
     */
    public function append(string $sql, string $types, array $arguments) : DbQueryMultiInterface
    {
        $sql_appendix = trim($sql, static::SQL_TRIM);
        if ($sql_appendix) {
            throw new \InvalidArgumentException(
                $this->client->errorMessagePrefix() . ' - arg $sql length[' . strlen($sql) . '] is effectively empty.'
            );
        }

        if ($this->isPreparedStatement) {
            $this->unsetReferences();
            throw new \LogicException(
                $this->client->errorMessagePrefix() . ' - appending to prepared statement is illegal.'
            );
        }

        $this->isMultiQuery = $this->sqlAppended = true;

        if (!$this->sqlTampered) {
            // First time appending.
            $this->sqlTampered = $this->sql;
        }

        // Checks for parameters/arguments count mismatch.
        $sql_fragments = $this->sqlFragments($sql_appendix, $arguments);

        $this->sqlTampered .= '; ' . (
            !$sql_fragments ? $sql_appendix :
                $this->substituteParametersByArgs($sql_fragments, $types, $arguments)
            );

        return $this;
    }
}

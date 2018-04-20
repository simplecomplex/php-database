<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

use SimpleComplex\Database\Interfaces\DbResultInterface;

/**
 * Maria DB result.
 *
 * @package SimpleComplex\Database
 */
class MariaDbResult implements DbResultInterface
{
    /**
     * @var MariaDbQuery
     */
    protected $query;

    /**
     * @var bool
     */
    protected $isMultiQuery;

    /**
     * @var bool
     */
    protected $isPreparedStatement;

    /**
     * @param MariaDbQuery $query
     */
    public function __construct(MariaDbQuery $query)
    {
        $this->query = $query;

        // Don't
        $this->isMultiQuery = $this->query->isMultiQuery;
        $this->isPreparedStatement = $this->query->isPreparedStatement;
    }

    public function nextRow()
    {
        // @todo
    }
}

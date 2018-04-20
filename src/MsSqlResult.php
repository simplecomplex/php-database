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
 * MS SQL result.
 *
 * @package SimpleComplex\Database
 */
class MsSqlResult implements DbResultInterface
{
    /**
     * @var MsSqlQuery
     */
    protected $query;

    /**
     * @var bool
     */
    protected $isPreparedStatement;

    /**
     * @param MsSqlQuery $query
     */
    public function __construct(MsSqlQuery $query)
    {
        $this->query = $query;

        $this->isPreparedStatement = $this->query->isPreparedStatement;
    }

    public function next()
    {
        // @todo
    }
}

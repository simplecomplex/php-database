<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-databse
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-databse/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

/**
 * A constant value is the same as \PDO equivalent, when such available.
 *
 * @see \PDO
 *
 * @package SimpleComplex\Database
 */
abstract class Database
{
    /**
     * @var int
     */
    const FETCH_ASSOC = 2;

    /**
     * @var int
     */
    const FETCH_NUMERIC = 3;

    /**
     * @var int
     */
    const FETCH_OBJECT = 5;

    /**
     * @see \SimpleComplex\Database\Interfaces\DbClientInterface::getErrors()
     *
     * @var int
     */
    const ERRORS_STRING = 1;

    /**
     * @see \SimpleComplex\Database\Interfaces\DbClientInterface::getErrors()
     *
     * @var int
     */
    const ERRORS_STRING_EMPTY_NONE = 2;
}

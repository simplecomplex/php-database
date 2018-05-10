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
 * Lists of relevant RMDBS native connection|query|result error codes.
 *
 * @see MariaDbErrorCodes
 * @see MsSqlErrorCodes
 *
 * @see DatabaseClient::errorsToException()
 *
 * @package SimpleComplex\Database
 */
abstract class DatabaseErrorCodes
{
    /**
     * Connection related RMDBS native error codes.
     *
     * @var array
     */
    const CONNECTION = [];

    /**
     * Query related RMDBS native error codes.
     *
     * @var array
     */
    const QUERY = [];

    /**
     * Result related RMDBS native error codes.
     *
     * @var array
     */
    const RESULT = [];
}

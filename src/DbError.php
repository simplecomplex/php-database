<?php
/**
 * SimpleComplex PHP Database
 * @link      https://github.com/simplecomplex/php-database
 * @copyright Copyright (c) 2018 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-database/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\Database;

/**
 * Lists of relevant RMDBS native connection|query|result error codes.
 *
 * @see MariaDbError
 * @see MsSqlError
 *
 * @see DbClient::errorsToException()
 *
 * @package SimpleComplex\Database
 */
abstract class DbError
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

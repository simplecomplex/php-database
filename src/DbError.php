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
     * Some DBMSs (like MsSql) forbids stored procedures from (re)using
     * a native error code when throwing an error.
     *
     * If an error code is larger than this and less than twice this
     * it will be evaluated with this subtracted.
     *
     * Set (override) to zero if irrelevant and misleading.
     *
     * @see DbClient::errorsToException()
     *
     * @var int
     */
    const FORWARDED_CODE_OFFSET = 100000;

    /**
     * Connection related RMDBS native error codes.
     *
     * @var int[]
     */
    const CONNECTION_CODES = [];

    /**
     * Connection related RMDBS native error code ranges.
     *
     * @var array
     */
    const CONNECTION_RANGES = [];

    /**
     * Query related RMDBS native error codes.
     *
     * @var int[]
     */
    const QUERY_CODES = [];

    /**
     * Query related RMDBS native error code ranges.
     *
     * @var array
     */
    const QUERY_RANGES = [];

    /**
     * Result related RMDBS native error codes.
     *
     * @var int[]
     */
    const RESULT_CODES = [];

    /**
     * Result related RMDBS native error code ranges.
     *
     * @var array
     */
    const RESULT_RANGES = [];

    /**
     * @see \SimpleComplex\Database\Interfaces\DbClientInterface::getErrors()
     *
     * @var int
     */
    const AS_STRING = 1;

    /**
     * @see \SimpleComplex\Database\Interfaces\DbClientInterface::getErrors()
     *
     * @var int
     */
    const AS_STRING_EMPTY_ON_NONE = 2;
}

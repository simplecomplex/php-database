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
 * Lists of relevant MsSQL native connection|query|result error codes.
 *
 * SQL Server error codes (no up-to-date documentation available):
 * @see https://docs.microsoft.com/en-us/previous-versions/sql/sql-server-2008-r2/cc645603(v=sql.105)
 *
 * @see DbClient::errorsToException()
 *
 * @package SimpleComplex\Database
 */
class MsSqlError extends DbError
{
    /**
     * Connection related RMDBS native error codes.
     *
     * @var int[]
     */
    const CONNECTION_CODES = [
        // Cannot open database ”%s” requested by the login. The login failed.
        4060,
        // Login failed for user ’%s’.
        18456,
    ];

    /**
     * Query related RMDBS native error codes.
     *
     * @var int[]
     */
    const QUERY_CODES = [
        // Use range 101, 701 instead.
        // Incorrect syntax near '%s'.
        // 102,
        // Cannot insert the value NULL into column '%.*ls', table '%.*ls'; column does not allow nulls. %ls fails.
        // 515,
        // Cannot truncate table '%s' because it is being referenced by a FOREIGN KEY constraint.
        // 4712,
    ];

    /**
     * Query related RMDBS native error code ranges.
     *
     * @var array
     */
    const QUERY_RANGES = [
        [
            /**
             * @see https://docs.microsoft.com/en-us/previous-versions/sql/sql-server-2008-r2/cc645611(v%3dsql.105)
             */
            101, 681
        ]
    ];

    /**
     * Result related RMDBS native error codes.
     *
     * @var int[]
     */
    const RESULT_CODES = [];
}

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
 * Lists of relevant MariaDb/MySQL native connection|query|result error codes.
 *
 * @see DbClient::errorsToException()
 *
 * @package SimpleComplex\Database
 */
class MariaDbError extends DbError
{
    /**
     * Connection related RMDBS native error codes.
     *
     * @var int
     */
    const CONNECTION = [
        // Can't create UNIX socket (%d).
        2001,
        // Can't connect to local MySQL server through socket '%s' (%d).
        2002,
        // Can't connect to MySQL server on '%s' (%d).
        2003,
        // Can't create TCP/IP socket (%d).
        2004,
        // Unknown MySQL server host '%s' (%d).
        2005,
        // MySQL server has gone away.
        2006,
        // Protocol mismatch; server version = %d, client version = %d.
        2007,
        // Wrong host info.
        2009,
        // Localhost via UNIX socket.
        2010,
        // %s via TCP/IP.
        2011,
        // Error in server handshake.
        2012,
        // Lost connection to MySQL server during query.
        2013,
        // Error connecting to slave
        2024,
        // Error connecting to master
        2025,
        // SSL connection error: %s.
        2026,
        // Invalid connection handle.
        2048,
        // Lost connection to MySQL server at '%s', system error: %d.
        2055,
        // Too many connections.
        1040,
        // Can't get hostname for your address.
        1042,
        // Bad handshake.
        1043,
        // Access denied for user '%s'@'%s' to database '%s'.
        1044,
        // Access denied for user '%s'@'%s' (using password: %s).
        1045,
        // Server shutdown in progress.
        1053,
    ];

    /**
     * Query related RMDBS native error codes.
     *
     * @var int
     */
    const QUERY = [
        // Statement not prepared
        2030,
        // No data supplied for parameters in prepared statement
        2031,
        // No parameters exist in the statement
        2033,
        // Statement closed indirectly because of a preceding %s() call
        2056,
        // Can't create table '%s' (errno: %d - %s)
        1005,
        // Can't create database '%s' (errno: %d - %s)
        1006,
        // Can't create database '%s'; database exists
        1007,
        // Can't drop database '%s'; database doesn't exist
        1008,
        // Error dropping database (can't rmdir '%s', errno: %d - %s)
        1010,
        // No database selected
        1046,
        // Column '%s' cannot be null
        1048,
        // Unknown database '%s'
        1049,
        // Table '%s' already exists
        1050,
        // Unknown table '%s'
        1051,
        // Column '%s' in %s is ambiguous
        1052,
        // Unknown column '%s' in '%s'
        1054,
        // '%s' isn't in GROUP BY
        1055,
        // Can't group on '%s'
        1056,
        // Statement has sum functions and columns in same statement
        1057,
        // Column count doesn't match value count
        1058,
        // Identifier name '%s' is too long
        1059,
        // Duplicate column name '%s'
        1060,
        // Duplicate key name '%s'
        1061,
        // Duplicate entry '%s' for key %d
        1062,
        // Incorrect column specifier for column '%s'
        1063,
        // %s near '%s' at line %d
        // = General syntax error.
        1064,
        // Not unique table/alias: '%s'
        1066,
        // Invalid default value for '%s'
        1067,
        // Multiple primary key defined
        1068,
        // Too many keys specified; max %d keys allowed
        1069,
        // Too many key parts specified; max %d parts allowed
        1070,
        // Specified key was too long; max key length is %d bytes
        1071,
        // Key column '%s' doesn't exist in table
        1072,
        // BLOB column '%s' can't be used in key specification with the used table type
        1073,
        // Column length too big for column '%s' (max = %lu); use BLOB or TEXT instead
        1074,
        // Incorrect table definition; there can be only one auto column and it must be defined as a key
        1075,
        // Column count doesn't match value count at row %ld
        1136,
        // %s command denied to user '%s'@'%s' for table '%s'
        1142,
        // %s command denied to user '%s'@'%s' for column '%s' in table '%s'
        1143,
        // Table '%s.%s' doesn't exist
        1146,
        // Cannot add foreign key constraint
        1215,
        // Cannot add or update a child row: a foreign key constraint fails
        1216,
        // Cannot delete or update a parent row: a foreign key constraint fails
        1217,
        // Incorrect foreign key definition for '%s': %s
        1239,
        // Field '%s' doesn't have a default value
        1364,
        // %s command denied to user '%s'@'%s' for routine '%s'.
        1370,
        // Cannot delete or update a parent row: a foreign key constraint fails (%s)
        1451,
        // Foreign keys are not yet supported in conjunction with partitioning
        1506,
        // Cannot drop index '%s': needed in a foreign key constraint
        1553,
        // Upholding foreign key constraints for table '%s', entry '%s', key %d would lead to a duplicate entry
        1557,
        // Cannot truncate a table referenced in a foreign key constraint (%s)
        1701,
        // Table is being used in foreign key check.
        1725,
        // Table to exchange with partition has foreign key references: '%s'
        1740,
        // Data for column '%s' too long
        1742,
        //  Foreign key constraint for table '%s', record '%s' would lead to a duplicate entry in table '%s', key '%s'
        1761,
        // Foreign key constraint for table '%s', record '%s' would lead to a duplicate entry in a child table
        1762,
        // There is a foreign key check running on table '%s'. Cannot discard the table
        1807,
        // Failed to add the foreign key constraint. Missing index for constraint '%s' in the foreign table '%s'
        1821,
        // Failed to add the foreign key constraint. Missing index for constraint '%s' in the referenced table '%s'
        1822,
        // Failed to add the foreign key constraint on table '%s'. Incorrect options in FOREIGN KEY constraint '%s'
        1825,
        // Duplicate foreign key constraint name '%s'
        1826,
    ];

    /**
     * Result related RMDBS native error codes.
     *
     * @var int
     */
    const RESULT = [
        // Commands out of sync; you can't run this command now
        // = Typically omitting to do a next_result().
        2014,
        // Row retrieval was canceled by mysql_stmt_close() call.
        2050,
        // Attempt to read column without prior row fetch.
        2051,
        // Attempt to read a row while there is no result set associated with the statement.
        2053,
        // The number of columns in the result set differs from the number of bound buffers.
        2057,
        // (42000) Result string is longer than 'max_allowed_packet' bytes.
        1162,
        // (42000) Result consisted of more than one row.
        1172,
        // (HY000) Result of %s() was larger than max_allowed_packet (%ld) - truncated.
        1301,
        // (0A000) PROCEDURE %s can't return a result set in the given context.
        1312,
        // (0A000) Not allowed to return a result set from a %s.
        1415,
        // (HY000) The result string is larger than the result buffer.
        3684,
        // (HY000) Error getting result data: %s.
        11343,
    ];
}

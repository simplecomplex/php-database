<?php

declare(strict_types=1);


use SimpleComplex\Utils\Dependency;
use SimpleComplex\Time\Time;
use SimpleComplex\Database\DbQuery;
use SimpleComplex\Tests\Database\Stringable;

// Bootstrap.-------------------------------------------------------------------

//Dependency::genericSet('database-broker', function() {
//    return new \SimpleComplex\Database\DatabaseBroker();
//});


/**
 * Include script example, for 'utils-execute' CLI command.
 *
 * @code
 * # CLI
 * php cli.php utils-execute backend/vendor/simplecomplex/database/dev/mariadb/utils_execute_mariadb.php -yf
 * @endcode
 */

(function() {
    /** @var \Psr\Container\ContainerInterface $container */
    $container = Dependency::container();

    /** @var \SimpleComplex\Database\DatabaseBroker $db_broker */
    $db_broker = $container->get('database-broker');

    $client = $db_broker->getClient('test_scx_mariadb', 'mariadb', [
        'host' => 'localhost',
        'database' => 'test_scx_mariadb',
        'user' => 'test_scx_mariadb',
        'pass' => '1234',
    ]);

    $query = $client->query(
        'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            //'validate_params' => DbQuery::VALIDATE_FAILURE,
            'sql_minify' => true,
            'affected_rows' => true,
        ]
    );

    $types = 'idssbsss';

    $time = new Time();
    $args = [
        '_0_int' => 0,
        '_1_float' => 1.0,
        '_2_decimal' => '2.0',
        // Yes, MySQLi attempts to stringify object.
        '_3_varchar' => new Stringable('stringable from execute'),
        '_4_blob' => sprintf("%08d", decbin(4)),
        '_5_date' => $time->toISOLocalDate(),
        '_6_datetime' => '' . $time->toISOLocalDate(),
        // But MySQLi doesn't check if object has __toString() method.
        '_7_text' => new stdClass(),
    ];
    $query->prepare($types, $args);
    $query->execute();

})();

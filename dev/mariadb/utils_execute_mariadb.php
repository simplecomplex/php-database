<?php

declare(strict_types=1);


use SimpleComplex\Utils\Dependency;
use SimpleComplex\Utils\Time;
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
 * php cli.phpsh utils-execute backend/vendor/simplecomplex/database/dev/mariadb/utils_execute_mariadb.php -yf
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
        'INSERT INTO typish (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            'validate_params' => DbQuery::VALIDATE_FAILURE,
            'sql_minify' => true,
            'affected_rows' => true,
        ]
    );

    $types = 'idssbss';

    $time = new Time();
    $args = [
        '_0_int' => 0,
        '_1_float' => 1.0,
        '_2_decimal' => '2.0',
        '_3_varchar' => new stdClass(), //'stringable from execute',
        '_4_blob' => sprintf("%08d", decbin(4)),
        '_5_date' => $time->getDateISOlocal(),
        '_6_datetime' => '' . $time->getDateISOlocal(),
    ];
    $query->prepare($types, $args);
    echo "prepared\n";
    $query->execute();
    echo "executed\n";


    // Yes, MySQLi attempts to stringify object.
    $args['_3_varchar'] = new stdClass();
    /**
     * But MySQLi doesn't check if object has __toString() method.
     *
     * If
     * @see DbQuery::VALIDATE_PARAMS
     * is
     * @see DbQuery::VALIDATE_ALWAYS
     * @throws \SimpleComplex\Database\Exception\DbQueryArgumentException
     *
     * Else
     * throws fatal error :-(
     *
     * @throws \SimpleComplex\Database\Exception\DbRuntimeException
     */
    //$args['_6_datetime'] = new \DateTime('2000-01-01');

    $query->execute();

})();

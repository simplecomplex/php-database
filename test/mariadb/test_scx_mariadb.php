<?php

declare(strict_types=1);

use \SimpleComplex\Utils\Dependency;

use \SimpleComplex\Database\Database;


// Bootstrap.-------------------------------------------------------------------

Dependency::genericSet('database-broker', function() {
    return new \SimpleComplex\Database\DatabaseBroker();
});


// HTTP service responder.------------------------------------------------------

/** @var \Psr\Container\ContainerInterface $container */
$container = Dependency::container();

/** @var \Psr\Log\LoggerInterface $logger */
$logger = $container->get('logger');
/** @var \SimpleComplex\Inspect\Inspect $inspect */
$inspect = $container->get('inspect');
/** @var \KkBase\Base\Config\IniSectionedConfig $config_store */
$config_store = $container->get('config');
/** @var \SimpleComplex\Database\DatabaseBroker $db_broker */
$db_broker = $container->get('database-broker');

/** @var \SimpleComplex\Database\MariaDbClient $client */
$client = $db_broker->getClient(
    'test_scx_mariadb',
    'mariadb',
    $config_store->get('database-info.test_scx_mariadb', '*')
);
//$client->optionsResolve();

//$logger->debug('Client (mariadb)' . "\n" . $inspect->variable($client));

/** @noinspection SqlResolve */
/** @var \SimpleComplex\Database\MariaDbQuery $query */
$query = $client->query('INSERT INTO parent (lastName, firstName, birthday) VALUES (?, ?, ?)');
/** @noinspection SqlResolve */
/** @var \SimpleComplex\Database\MariaDbQuery $query */
//$query = $client->multiQuery('INSERT INTO parent (lastName, firstName, birthday) VALUES (?, ?, ?); SELECT LAST_INSERT_ID()');
$arguments = [
    'lastName' => 'Mathiasen',
    'firstName' => 'Jacob Friis',
    'birthday' => '1970-01-02',
];
$query->prepare('sss', $arguments);
$logger->debug('Query (mariadb)' . "\n" . $inspect->variable($query));
$result = $query->execute();
//$arguments['birthday'] = '1969-02-01';
//$result = $query->parameters('sss', $arguments)->execute();

$logger->debug('inserted' . "\n" . $inspect->variable([
        'affectedRows' => $result->affectedRows(),
        'insertId' => $result->insertId(),
    ]));
/*
$result->nextSet();
$logger->debug('fetchArray' . "\n" . $inspect->variable([
        'fetchArray' => $result->fetchArray(),
    ]));
*/

/*$logger->debug('LAST_INSERT_ID' . "\n" . $inspect->variable(
    $client->query('SELECT LAST_INSERT_ID()')->execute()->fetchArray()
));*/

//SELECT LAST_INSERT_ID()


$query = $client->query('SELECT * FROM parent');
//$logger->debug('query' . "\n" . $inspect->variable($query));

$result = $query->execute();
//$logger->debug('result' . "\n" . $inspect->variable($result));

$logger->debug('all rows' . "\n" . $inspect->variable($result->fetchAll(Database::FETCH_ASSOC, ['list_by_column' => 'id'])));

return;


$last_name = 'Mathiasen';
$first_name = 'Jacob Friis';
$age = 117;

$arguments = [
    [
        &$last_name,
        SQLSRV_PARAM_IN
    ],
    [
        &$first_name,
        SQLSRV_PARAM_IN
    ],
    [
        &$age,
        SQLSRV_PARAM_IN,
        null,
        SQLSRV_SQLTYPE_SMALLINT
    ]
];

$last_name = 'm';
$id = 60;
$arguments = [
    [
        &$last_name,
        SQLSRV_PARAM_IN
    ],
    [
        &$id,
        SQLSRV_PARAM_IN,
        null,
        SQLSRV_SQLTYPE_SMALLINT
    ]
];

/** @noinspection SqlResolve */
/** @var \SimpleComplex\Database\MsSqlQuery $query */
//$query = $database->query('INSERT INTO Persons (LastName, FirstName, Age) VALUES (?, ?, ?)', [
$query = $database->query('UPDATE Persons SET LastName = ? WHERE ID = ?', [
    'cursor_mode' => 'forward',
    'get_insert_id' => true,
]);
$query->prepare('', $arguments);

/*$arguments = [10];
$query = $database->query('SELECT * FROM Persons WHERE ID = ?', [
    // 'cursor_mode' => 'forward',
]);*/


//$query = $database->query('SELECT MedarbejderNR FROM KsRefund_Main WHERE id = 10; SELECT id, MedarbejderNR FROM KsRefund_Main WHERE id >= ? AND id <= ?');

//$query->prepare('i', $arguments);
//$query->parameters('ii', [$id_first, $id_last]);

//$logger->debug('Query' . "\n" . $inspect->variable($query));

//$query->parameters($types, [$id]);
//$logger->debug('' . "\n" . $inspect->variable($query->preparedStatementArgs));

//$variable = $query;
$result = $query->execute();

$logger->debug('affectedRows' . "\n" . $inspect->variable($result->affectedRows()));
//$logger->debug('numRows' . "\n" . $inspect->variable($result->numRows()));
$logger->debug('insertId' . "\n" . $inspect->variable($result->insertId('i')));

return;

//$variable = $result->numRows();
$variable = [];
while (($row = $result->fetchArray())) {
    $variable[] = $row;
}
$logger->debug('' . "\n" . $inspect->variable($variable));

//return;

$id_first = 2;
$id_last = 4;

//$query->parameters('ii', [$id_first, $id_last]);
$result = $query->execute();
$variable = $result->fetchAll(Database::FETCH_ASSOC, [
    'list_by_column' => 'MedarbejderNR',
]);
$logger->debug('' . "\n" . $inspect->variable($variable));

$query->close();

$logger->debug('Query' . "\n" . $inspect->variable($query));

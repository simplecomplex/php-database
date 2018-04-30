<?php

declare(strict_types=1);

use \SimpleComplex\Utils\Dependency;

use \SimpleComplex\Database\Database;


// Bootstrap.-------------------------------------------------------------------

Dependency::genericSet('database-broker', function() {
    return new \SimpleComplex\Database\DatabaseBroker();
});


// HTTP service responder.------------------------------------------------------

$container = Dependency::container();

/** @var \Psr\Log\LoggerInterface $logger */
$logger = $container->get('logger');
/** @var \SimpleComplex\Inspect\Inspect $inspect */
$inspect = $container->get('inspect');

/** @var \KkBase\Base\Config\IniSectionedConfig $config_store */
$config_store = $container->get('config');

/** @var \SimpleComplex\Database\DatabaseBroker $db_broker */
$db_broker = $container->get('database-broker');

/** @var \SimpleComplex\Database\DatabaseClient $database */
/** @var \SimpleComplex\Database\MsSqlClient $database */
$database = $db_broker->getClient(
    'test',
    'mssql',
    $config_store->get('database-info.test', '*')
);
$database->optionsResolve();

//$logger->debug('Client' . "\n" . $inspect->variable($database));


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

/** @noinspection SqlResolve */
/** @var \SimpleComplex\Database\MsSqlQuery $query */
$query = $database->query('INSERT INTO Persons (LastName, FirstName, Age) VALUES (?, ?, ?)', [
    'cursor_mode' => 'forward',
    'get_insert_id' => true,
]);


//$query = $database->query('SELECT MedarbejderNR FROM KsRefund_Main WHERE id = 10; SELECT id, MedarbejderNR FROM KsRefund_Main WHERE id >= ? AND id <= ?');

$query->prepare('', $arguments);
//$query->parameters('ii', [$id_first, $id_last]);

$logger->debug('Query' . "\n" . $inspect->variable($query));

//$query->parameters($types, [$id]);
//$logger->debug('' . "\n" . $inspect->variable($query->preparedStatementArgs));

//$variable = $query;
$result = $query->execute();

$logger->debug('affectedRows' . "\n" . $inspect->variable($result->affectedRows()));
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

$query->closeStatement();

$logger->debug('Query' . "\n" . $inspect->variable($query));

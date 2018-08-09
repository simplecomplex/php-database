<?php

declare(strict_types=1);

use SimpleComplex\Utils\Dependency;

use SimpleComplex\Database\DbResult;


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

/** @var \SimpleComplex\Config\IniSectionedConfig $config_store */
$config_store = $container->get('config');

/** @var \SimpleComplex\Database\DatabaseBroker $db_broker */
$db_broker = $container->get('database-broker');

/** @var \SimpleComplex\Database\DbClient $database */
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
        //'Mathiasen',
        SQLSRV_PARAM_IN,
        null,
        SQLSRV_SQLTYPE_VARCHAR('max')
    ],
    [
        &$first_name,
        //'Jacob Friis',
        SQLSRV_PARAM_IN,
        null,
        SQLSRV_SQLTYPE_VARCHAR('max')
    ],
    [
        &$age,
        //15,
        SQLSRV_PARAM_IN,
        null,
        SQLSRV_SQLTYPE_SMALLINT
    ]
];
/*
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
];*/
/*
$arguments = [
    'lastName' => 'Mathiasen',
    'firstName' => 'Jacob Friis',
    'age' => 118,
];
*/
/** @noinspection SqlResolve */
/** @var \SimpleComplex\Database\MsSqlQuery $query */
$query = $database->query('INSERT INTO Persons (LastName, FirstName, Age) VALUES (?, ?, ?)', [
//$query = $database->query('UPDATE Persons SET LastName = ? WHERE ID = ?', [
    'result_mode' => 'forward',
    'insert_id' => true,
]);
//$query->prepare('ssi', $arguments);
$query->parameters('ssi', $arguments);
//$logger->debug('Query (mssql)' . "\n" . $inspect->variable($query));

/*$arguments = [10];
$query = $database->query('SELECT * FROM Persons WHERE ID = ?', [
    // 'result_mode' => 'forward',
]);*/


//$query = $database->query('SELECT MedarbejderNR FROM KsRefund_Main WHERE id = 10; SELECT id, MedarbejderNR FROM KsRefund_Main WHERE id >= ? AND id <= ?');

//$query->prepare('i', $arguments);
//$query->parameters('ii', [$id_first, $id_last]);

//$logger->debug('Query' . "\n" . $inspect->variable($query));

//$query->parameters($types, [$id]);
//$logger->debug('' . "\n" . $inspect->variable($query->preparedStatementArgs));

//$variable = $query;
//$query->execute();
$age = 99;
//$arguments['age'] = 99;
//$arguments['age'][0] = 31;
//$logger->debug('Query (mssql)' . "\n" . $inspect->variable($query));
//$arguments[2][0] = 51;
//$logger->debug('Query (mssql)' . "\n" . $inspect->variable($query));
$result = $query->execute();

$logger->debug('affectedRows' . "\n" . $inspect->variable($result->affectedRows()));
//$logger->debug('numRows' . "\n" . $inspect->variable($result->numRows()));
$logger->debug('fetchField' . "\n" . $inspect->variable($result->fetchField()));
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
$variable = $result->fetchAllArrays(DbResult::FETCH_ASSOC, 'MedarbejderNR');
$logger->debug('' . "\n" . $inspect->variable($variable));

$query->close();

$logger->debug('Query' . "\n" . $inspect->variable($query));

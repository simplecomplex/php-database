# (PHP) Database #

- [Database engine specifics](#database-engine-specifics)
- [Examples - MariaDB/MySQL](#mariadb-mysql)
- [Examples - MS SQL](#ms-sql)
- [Requirements](#requirements)

## Scope ##

Compact cross-engine relational database abstraction which handles common engine-specific peculiarities.

## Features ##

- uniform classes and methods across database engines
- _client_ - _query_ - _result_ architecture
- chainable methods
- a database broker to keep track of all created clients
- extensive error handling/logging and defensive code style

### Client ###
- auto-reconnects, when reasonable
- safe transaction handling
- all connection options supported

### Query ###
- auto-detects parameter types when no type string (i|d|s|b) given
- ?-parameter substitution in non-prepared statements

### Result ###
- affected rows, insert ID, number of rows, number of columns
- fetch array/object, fetch all rows
- moving to next set/row

### MariaDB/MySQL features ###
- multiple selecting queries, append query
- result mode _use_; _store_ only for non-prepared statement
- number of rows not supported for prepared statement (because _use_)

### MS SQL features ###
- automated typed arguments handling (SQLSRV_PARAM_IN etc.)
- automated insert ID retrieval
- result mode (SQLSRV_CURSOR_FORWARD etc.) defense against wrong use

## Database engine specifics ##

### MariaDB/MySQL (PHP MySQLi/mysqlnd) ###

A MariaDB/MySQL **multi-query** is an SQL string containing more queries delimited by semicolon.  
Every query may be a SELECT (or likewise) producing a result set.

PHP's MySQLi extension only offers native ?-parameter substitution for prepared statements,
however the **``` MariaDb::parameters() ```** method mends that (_somewhat_); for simple statements.  
Still, **go for prepared statements if security is the major concern**.

The MySQLi extension encompasses around 100 functions/methods/properties.
Fairly confusing; this abstraction only utilizes a dozen or so of them.

### MS SQL (PHP Sqlsrv) ###

PHP's Sqlsrv offers native ?-parameter substitution for simple statements as well as prepared statements.

There's no MS SQL **result mode** which supports (INSERT) affected-rows as well as (SELECT) num-rows.  
So care should be taken to use 'forward' when inserting and 'static' or 'keyset' when selecting;  
use **``` MsSqlClient::query() ```** option ``` (string) result_mode ```.

MS SQL/Sqlsrv has no direct means for getting insert ID, but supports likewise via a 'magic' query
appended to an INSERT statement.  
**``` MsSqlQuery ```**+**``` MsSqlResult ```** handles the issue transparently when **``` MsSqlClient::query() ```** receives the option ``` (bool) insert_id ```.

The Sqlsrv extension is a well-made tight no-nonsense API consisting of 20-odd functions.

## Examples ##

### MariaDB/MySQL ###

```php
// Get or create client via the broker -----------------------------------------
/** @var \Psr\Container\ContainerInterface $container */
$container = Dependency::container();
/** @var \SimpleComplex\Database\DatabaseBroker $db_broker */
$db_broker = $container->get('database-broker');
/** @var \SimpleComplex\Database\MariaDbClient $client */
$client = $db_broker->getClient(
    'some-client',
    'mariadb',
    [
        'host' => 'localhost',
        'database' => 'some_database',
        'user' => 'some-user',
        'pass' => '∙∙∙∙∙∙∙∙',
    ]
);

// Or create client directly ---------------------------------------------------
use SimpleComplex\Database\MariaDbClient;
$client = new MariaDbClient(
    'some-client',
    [
        'host' => 'localhost',
        'database' => 'some_database',
        'user' => 'some-user',
        'pass' => '∙∙∙∙∙∙∙∙',
    ]
);

// Insert two rows, using a prepared statement ---------------------------------
$arguments = [
    'lastName' => 'Doe',
    'firstName' => 'Jane',
    'birthday' => '1970-01-01',
];
/** @var \SimpleComplex\Database\MariaDbQuery $query */
$query = $client->query('INSERT INTO person (lastName, firstName, birthday) VALUES (?, ?, ?)')
    ->prepare('sss', $arguments)
    // Insert first row.
    ->execute();
$arguments['firstName'] = 'John';
// Insert second row.
/** @var \SimpleComplex\Database\MariaDbResult $result */
$result = $query->execute();
$affected_rows = $result->affectedRows();
$insert_id = $result->insertId();

// Get a row, using a simple statement -----------------------------------------
$somebody = $client->query('SELECT * FROM person WHERE personId > ? AND personId < ?')
    ->parameters('ii', [1, 3])
    ->execute()
    ->fetchArray();

// Get all rows, using a simple statement, and list them by 'personId' column --
$everybody = $client->query('SELECT * FROM person')
    ->execute()
    ->fetchAllArrays(DbResult::FETCH_ASSOC, 'personId'));
```

### MS SQL ###

```php
// Create client via the broker ------------------------------------------------
/** @var \SimpleComplex\Database\MsSqlClient $client */
$client = Dependency::container()
    ->get('database-broker')
    ->getClient(
        'some-client',
        'mssql',
        [
            'host' => 'localhost',
            'database' => 'some_database',
            'user' => 'some-user',
            'pass' => '∙∙∙∙∙∙∙∙',
        ]
    );

// Insert two rows, using a prepared statement
// and arguments that aren't declared as sqlsrv typed arrays -------------------
$arguments = [
    'lastName' => 'Doe',
    'firstName' => 'Jane',
    'birthday' => '1970-01-01',
];
/** @var \SimpleComplex\Database\MsSqlQuery $query */
$query = $client->query('INSERT INTO person (lastName, firstName, birthday) VALUES (?, ?, ?)', [
        // SQLSRV_CURSOR_FORWARD to get affected rows.
        'result_mode' => 'forward',
        // For MsSqlResult::insertId().
        'insert_id' => true,
    ])
    ->prepare('sss', $arguments)
    // Insert first row.
    ->execute();
$arguments['firstName'] = 'John';
// Insert second row.
/** @var \SimpleComplex\Database\MsSqlResult $result */
$result = $query->execute();
$affected_rows = $result->affectedRows();
$insert_id = $result->insertId();

// Insert two rows, using a prepared statement
// and types empty (guess type argument's actual type)
// and arguments partially declared as sqlsrv typed arrays ---------------------
$arguments = [
    [
        'Doe',
        SQLSRV_PARAM_IN,
        null,
        SQLSRV_SQLTYPE_VARCHAR('max')
    ],
    [
        'Jane',
    ],
    '1970-01-01',
];
$query = $client->query('INSERT INTO person (lastName, firstName, birthday) VALUES (?, ?, ?)')
    ->prepare('', $arguments)
    // Insert first row.
    ->execute();
// Insert second row.
$arguments[1][0] = 'John';
$query->execute();

// Get a row, using a simple statement -----------------------------------------
$somebody = $client->query('SELECT * FROM person WHERE personId > ? AND personId < ?')
    ->parameters('ii', [1, 3])
    ->execute()
    ->fetchArray();

// Get all rows, using a simple statement, and list them by 'personId' column --
$everybody = $client->query('SELECT * FROM person')
    ->execute()
    ->fetchAllArrays(DbResult::FETCH_ASSOC, 'personId'));
```

## Requirements ##

- PHP >=7.0
- [PSR-3 Log](https://github.com/php-fig/log)
- [SimpleComplex Utils](https://github.com/simplecomplex/php-utils)
- [SimpleComplex Validate](https://github.com/simplecomplex/php-validate)

MariaDB equires the [mysqlnd driver](https://dev.mysql.com/downloads/connector/php-mysqlnd) (PHP default since v. 5.4),
or better.

### Suggestions ###

- PHP MySQLi extension, if using MariaDB/MySQL database
- PHP (PECL) Sqlsrv extension, if using MS SQL database
- [SimpleComplex Inspect](https://github.com/simplecomplex/inspect) Great for logging; better variable dumps and traces.

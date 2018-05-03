## (PHP) Database ##

- [Examples - MariaDB](#mariadb)
- [Examples - MS SQL](#ms-sql)
- [Requirements](#requirements)

### Scope ###

Compact cross-engine relational database abstraction which handles all common engine-specific peculiarities.

### Features ###

- uniform classes and methods across database engines
- _client_ - _query_ - _result_ architecture
- chainable methods
- a database broker to keep track of all created clients
- extensive error handling/logging and defensive code style

#### Client ####
- auto-reconnect, when reasonable
- safe transaction handling
- all connection options supported

#### Query ####
- prepared statements
- ?-parameter substitution in non-prepared statements

#### Result ####
- affected rows, insert ID, number of rows, number of columns
- fetch array/object, fetch all rows
- moving to next set/row

#### MariaDB specials ####
- multi-queries, repeat query, append query
- cursor mode _store_ vs. _use_

#### MS SQL specials ####
- automated (array) arguments handling (SQLSRV_PARAM_IN etc.)
- automated insert ID retrieval
- cursor mode (SQLSRV_CURSOR_FORWARD etc.) defense against wrong use

### Examples ###

#### MariaDB ####

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

// Get all rows, and list them by the 'personId' column ------------------------
/** @var \SimpleComplex\Database\MariaDbQuery $query */
$query = $client->query('SELECT * FROM person');
/** @var \SimpleComplex\Database\MariaDbResult $result */
$result = $query->execute();
$everybody = $result->fetchAll(Database::FETCH_ASSOC, ['list_by_column' => 'personId']))
```

#### MS SQL ####


### Requirements ###

- PHP >=7.0
- [PSR-3 Log](https://github.com/php-fig/log)
- [SimpleComplex Utils](https://github.com/simplecomplex/php-utils)

MariaDB equires the [mysqlnd driver](https://dev.mysql.com/downloads/connector/php-mysqlnd) (PHP default since v. 5.4),
or better.

#### Suggestions ####

- PHP MySQLi extension, if using Maria DB/MySQL database
- PHP (PECL) Sqlsrv extension, if using MS SQL database
- [SimpleComplex Inspect](https://github.com/simplecomplex/inspect) Great for logging; better variable dumps and traces.

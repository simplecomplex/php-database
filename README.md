# Database #

- [Examples - Maria DB](#maria-db)
- [Examples - MS SQL](#ms-sql)
- [Requirements](#requirements)

## Features ##

### Design ###
- uniform classes and methods across database engines
- _client_, _query_, _result_ architecture
- chainable methods

### Client ###
- prepared statements
- multi-queries (Maria DB)
- ?-parameter substitution in non-prepared statements


MariaDB prepared statement equires the mysqlnd driver.  
Because a result set will eventually be handled as \mysqli_result
via mysqli_stmt::get_result(); only available with mysqlnd.

@see http://php.net/manual/en/mysqli-stmt.get-result.php

## Examples ##

### Maria DB ###


### MS SQL ###


## Requirements ##

- PHP >=7.0
- [PSR-3 Log](https://github.com/php-fig/log)
- [SimpleComplex Inspect](https://github.com/simplecomplex/inspect)
- [SimpleComplex Utils](https://github.com/simplecomplex/php-utils)

### Suggestions ###

- PHP MySQLi extension, if using Maria DB/MySQL database
- PHP (PECL) Sqlsrv extension, if using MS SQL database
- [SimpleComplex Inspect](https://github.com/simplecomplex/inspect) Great for logging; better variable dumps and traces.

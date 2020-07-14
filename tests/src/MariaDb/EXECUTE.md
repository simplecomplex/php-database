
Execute all MariaDb tests.

```bash
# Beware: may max out CPU and never complete.
backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/

# Far more lenient.
backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/ClientTest.php
backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/ResetTest.php
backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/PopulateTest.php
backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/QueryArgumentTest.php
backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/QueryTest.php
backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/ResultTest.php
backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/StoredProcedureTest.php
#backend/vendor/bin/phpunit --do-not-cache-result backend/vendor/simplecomplex/database/tests/src/MariaDb/SslTest.php
```

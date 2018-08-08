CREATE PROCEDURE test_scx_mariadb.typishInsert(
    IN _0_int INT,
    IN _1_float FLOAT(24),
    IN _2_decimal DECIMAL(14,2),
    IN _3_varchar VARCHAR(255),
    IN _4_blob BLOB,
    IN _5_date DATE,
    IN _6_datetime DATETIME,
    IN _7_text TEXT
)
SQL SECURITY INVOKER
    BEGIN
        INSERT INTO test_scx_mariadb.typish
            (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_text)
        VALUES (_0_int, _1_float, _2_decimal, _3_varchar, _4_blob, _5_date, _6_datetime, _7_text);
    END

/*
CALL typishInsert (0, 1.1, '2.2', 'routine typishInsert', 'whatever', '2018-08-08', '2018-08-08 12:52', 'routine typishInsert');
*/

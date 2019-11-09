SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;

IF NOT EXISTS
    (  SELECT [name]
       FROM sys.databases
       WHERE [name] = 'test_scx_mssql'
    )
    -- SQLserver uses UTF-8 collation by default.
    CREATE DATABASE test_scx_mssql;

-- @todo: Is USE allowed when already using that?
USE test_scx_mssql;


IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'child' )
    ALTER TABLE child NOCHECK CONSTRAINT ALL;
IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'child' )
    DROP TABLE child;

IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'relationship' )
    ALTER TABLE relationship NOCHECK CONSTRAINT ALL;
IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'relationship' )
    DROP TABLE relationship;

IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'parent' )
    DROP TABLE parent;
IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'typish' )
    DROP TABLE typish;
IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'emptyish' )
    DROP TABLE emptyish;

CREATE TABLE parent (
    id INT IDENTITY(1,1),
    lastName VARCHAR(128) NOT NULL,
    firstName VARCHAR(128) NOT NULL,
    birthday DATE NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE relationship (
    spouseA INT NOT NULL,
    spouseB INT NOT NULL,
    active TINYINT NOT NULL DEFAULT 1,
    -- @todo: ON UPDATE CURRENT_TIMESTAMP: use trigger.
    since DATETIME NOT NULL DEFAULT GETDATE(),
    PRIMARY KEY (spouseA, spouseB),
    INDEX k_relationship_spouse_a(spouseA),
    INDEX k_relationship_spouse_b(spouseB),
    CONSTRAINT fk_relationship_spouse_a
    FOREIGN KEY(spouseA)
    REFERENCES parent(id)
        ON DELETE NO ACTION
        ON UPDATE NO ACTION,
    CONSTRAINT fk_relationship_spouse_b
    FOREIGN KEY(spouseB)
    REFERENCES parent(id)
        ON DELETE NO ACTION
        ON UPDATE NO ACTION
);

CREATE TABLE child (
    id INT IDENTITY(1,1),
    parentA INT NOT NULL,
    parentB INT DEFAULT NULL,
    lastName VARCHAR(128) NOT NULL,
    firstName VARCHAR(128) NOT NULL,
    birthday DATETIME NOT NULL,
    PRIMARY KEY (id),
    INDEX kc_child_parents(parentA, parentB),
    INDEX k_child_parent_a(parentA),
    INDEX k_child_parent_b(parentB),
    CONSTRAINT fk_child_parent_id_a
    FOREIGN KEY(parentA)
    REFERENCES parent(id)
        ON DELETE NO ACTION
        ON UPDATE NO ACTION,
    CONSTRAINT fk_child_parent_id_b
    FOREIGN KEY(parentB)
    REFERENCES parent(id)
        ON DELETE NO ACTION
        ON UPDATE NO ACTION
);

CREATE TABLE typish (
    id INT IDENTITY(1,1),
    _0_int INT NOT NULL,
    _1_float FLOAT(24) NOT NULL,
    _2_decimal DECIMAL(14,2) NOT NULL,
    _3_varchar VARCHAR(255) NOT NULL,
    _4_blob VARBINARY(max),
    _5_date DATE NOT NULL,
    _6_datetime DATETIME2 NOT NULL,
    _7_nvarchar NVARCHAR(255) NOT NULL,
    _8_bit BIT,
    _9_time TIME,
    _10_uuid UNIQUEIDENTIFIER
    PRIMARY KEY (id)
);

CREATE TABLE emptyish (
    id INT IDENTITY(1,1),
    whatever VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
);

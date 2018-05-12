SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO

IF NOT EXISTS
    (  SELECT [name]
       FROM sys.databases
       WHERE [name] = 'test_scx_mssql'
    )
    -- SQLserver uses UTF-8 collation by default.
    CREATE DATABASE test_scx_mssql
GO

USE test_scx_mssql;
GO


IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'child' )
    ALTER TABLE child NOCHECK CONSTRAINT ALL
GO
IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'child' )
    DROP TABLE child;
GO

IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'relationship' )
    ALTER TABLE relationship NOCHECK CONSTRAINT ALL
GO
IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'relationship' )
    DROP TABLE relationship;
GO

IF EXISTS ( SELECT [name] FROM sys.tables WHERE [name] = 'parent' )
    DROP TABLE parent;
GO


CREATE TABLE parent (
    id INT IDENTITY(1,1),
    lastName VARCHAR(128) NOT NULL,
    firstName VARCHAR(128) NOT NULL,
    birthday DATE NOT NULL,
    PRIMARY KEY (id)
)

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
)

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
)

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS test_scx_mariadb DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE test_scx_mariadb;

DROP TABLE IF EXISTS parent;
CREATE TABLE parent (
    id INT(11) NOT NULL AUTO_INCREMENT,
    lastName VARCHAR(128) NOT NULL,
    firstName VARCHAR(128) NOT NULL,
    birthday DATE NOT NULL,
    PRIMARY KEY (id)
);

DROP TABLE IF EXISTS relationship;
CREATE TABLE relationship (
    spouseA INT(11) NOT NULL,
    spouseB INT(11) NOT NULL,
    active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    since DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (spouseA, spouseB),
    INDEX fk_resres_parent_id_a(spouseA),
    INDEX fk_resres_parent_id_b(spouseB),
    FOREIGN KEY(spouseA)
    REFERENCES parent(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    FOREIGN KEY(spouseB)
    REFERENCES parent(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
);

DROP TABLE IF EXISTS child;
CREATE TABLE child (
    id INT(11) NOT NULL AUTO_INCREMENT,
    parentA INT(11) NOT NULL,
    parentB INT(11) DEFAULT NULL,
    lastName VARCHAR(128) NOT NULL,
    firstName VARCHAR(128) NOT NULL,
    birthday DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    INDEX (parentA, parentB),
    INDEX fk_resres_parent_id_a(parentA),
    INDEX fk_resres_parent_id_b(parentB),
    FOREIGN KEY(parentA)
    REFERENCES parent(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    FOREIGN KEY(parentB)
    REFERENCES parent(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
);

DROP TABLE IF EXISTS typish;
CREATE TABLE typish (
    id int(11) NOT NULL AUTO_INCREMENT,
    _0_int INT NOT NULL,
    _1_float FLOAT(24) NOT NULL,
    _2_decimal DECIMAL(14,2) NOT NULL,
    _3_varchar VARCHAR(255) NOT NULL,
    _4_blob BLOB,
    _5_date DATE NOT NULL,
    _6_datetime DATETIME(6) NOT NULL,
    _7_text TEXT,
    PRIMARY KEY (id)
);

DROP TABLE IF EXISTS typish_null;
CREATE TABLE typish_null (
    id int(11) NOT NULL AUTO_INCREMENT,
    _0_int INT,
    _1_float FLOAT(24),
    _2_decimal DECIMAL(14,2),
    _3_varchar VARCHAR(255),
    _4_blob BLOB,
    _5_date DATE,
    _6_datetime DATETIME(6),
    _7_text TEXT,
    PRIMARY KEY (id)
);

DROP TABLE IF EXISTS emptyish;
CREATE TABLE emptyish (
    id INT(11) NOT NULL AUTO_INCREMENT,
    whatever VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
);

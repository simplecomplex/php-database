SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS test_scx_mariadb DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE test_scx_mariadb;

DROP TABLE IF EXISTS parent;
CREATE TABLE parent (
    id int(11) NOT NULL AUTO_INCREMENT,
    lastName varchar(128) NOT NULL,
    firstName varchar(128) NOT NULL,
    birthday date NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS relationship;
CREATE TABLE relationship (
    spouseA int(11) NOT NULL,
    spouseB int(11) NOT NULL,
    active tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
    since datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
) ENGINE=InnoDB;

DROP TABLE IF EXISTS child;
CREATE TABLE child (
    id int(11) NOT NULL AUTO_INCREMENT,
    parentA int(11) NOT NULL,
    parentB int(11) DEFAULT NULL,
    lastName varchar(128) NOT NULL,
    firstName varchar(128) NOT NULL,
    birthday datetime NOT NULL,
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
) ENGINE=InnoDB;

DROP TABLE IF EXISTS typish;
CREATE TABLE typish (
    id int(11) NOT NULL AUTO_INCREMENT,
    _0_int INT,
    _1_float FLOAT(24),
    _2_decimal DECIMAL(14,2) NOT NULL,
    _3_varchar VARCHAR(255) NOT NULL,
    _4_blob BLOB,
    _5_date DATE NOT NULL,
    _6_datetime DATETIME NOT NULL,
    _7_text TEXT,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS rubbish;
CREATE TABLE rubbish (
    id int(11) NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS trash;
CREATE TABLE trash (
    id int(11) NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

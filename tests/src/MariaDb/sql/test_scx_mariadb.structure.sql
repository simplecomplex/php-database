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
    since timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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

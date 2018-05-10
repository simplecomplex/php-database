SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

--
-- Database: test_scx_mariadb
--

--
-- Dumping data for table child
--

INSERT INTO child (id, parentA, parentB, lastName, firstName, birthday) VALUES
    (1, 1, 2, 'Cognomen', 'Filia', '2018-01-01 00:00:00');

--
-- Dumping data for table parent
--

INSERT INTO parent (id, lastName, firstName, birthday) VALUES
    (1, 'Cognomen', 'Praenomena', '1970-01-02'),
    (2, 'Cognomen', 'Praenomeno', '1970-01-01');

--
-- Dumping data for table relationship
--

INSERT INTO relationship (spouseA, spouseB, active, since) VALUES
    (1, 2, 1, '2018-05-10 05:24:07');
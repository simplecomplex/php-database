SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;

-- Allow explicit insert into autoincrement column.
SET IDENTITY_INSERT parent ON;
INSERT INTO parent (id, lastName, firstName, birthday) VALUES
    (1, 'Cognomen', 'Praenomena', '1970-01-02'),
    (2, 'Cognomen', 'Praenomeno', '1970-01-01');
SET IDENTITY_INSERT parent OFF;


INSERT INTO relationship (spouseA, spouseB, active, since) VALUES
    (1, 2, 1, '2018-05-10 05:24:07');


SET IDENTITY_INSERT child ON;
INSERT INTO child (id, parentA, parentB, lastName, firstName, birthday) VALUES
    (1, 1, 2, 'Cognomen', 'Filia', '2018-01-01 00:00:00');
SET IDENTITY_INSERT child OFF;

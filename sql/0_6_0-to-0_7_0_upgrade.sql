--
-- UDS Report 0.6.0 to 0.7.0: the tables for the Tables 6B and 7 eCQM calculation.
-- The same statements are in install.sql; each is guarded, so running both changes nothing.
--

#IfNotTable uds_quality_run
CREATE TABLE `uds_quality_run` (
    `year` SMALLINT NOT NULL,
    `status` VARCHAR(15) NOT NULL COMMENT 'running, done or failed',
    `patients` LONGTEXT NOT NULL COMMENT 'JSON list of the pids being calculated, in order',
    `done` INT NOT NULL DEFAULT 0 COMMENT 'how many of them are calculated',
    `measures` TEXT NOT NULL COMMENT 'JSON list of the measures calculated',
    `missing` TEXT NOT NULL COMMENT 'JSON list of the measures not installed at the manual''s version',
    `seconds` DOUBLE NOT NULL DEFAULT 0,
    `error` TEXT DEFAULT NULL,
    `started_by` INT(11) DEFAULT NULL,
    `started_at` DATETIME NOT NULL,
    `finished_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`year`)
) ENGINE=InnoDB COMMENT='UDS Report: the eCQM calculation for Tables 6B and 7, one per year';
#EndIf

#IfNotTable uds_quality_result
CREATE TABLE `uds_quality_result` (
    `year` SMALLINT NOT NULL,
    `measure` VARCHAR(31) NOT NULL COMMENT 'such as CMS165v14',
    `population_set` VARCHAR(63) NOT NULL COMMENT 'such as PopulationSet_1',
    `pid` INT(11) NOT NULL,
    `ipp` SMALLINT NOT NULL DEFAULT 0,
    `denom` SMALLINT NOT NULL DEFAULT 0,
    `denex` SMALLINT NOT NULL DEFAULT 0,
    `denexcep` SMALLINT NOT NULL DEFAULT 0,
    `numer` SMALLINT NOT NULL DEFAULT 0,
    `numex` SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`year`, `measure`, `population_set`, `pid`)
) ENGINE=InnoDB COMMENT='UDS Report: each patient''s eCQM populations, from OpenEMR''s engine';
#EndIf

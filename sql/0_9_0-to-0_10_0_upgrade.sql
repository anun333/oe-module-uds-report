--
-- UDS Report 0.9.0 to 0.10.0: the VSAC value sets a site fetches with its own UMLS API key,
-- for the Table 6A lines the manual defines by value set. Named from 0.9.0 so that Module
-- Manager runs it for a module installed at 0.8.0 or 0.9.0. The same blocks are in
-- install.sql; they are guarded, so running both changes nothing.
--

#IfNotTable uds_value_set
CREATE TABLE `uds_value_set` (
    `oid` VARCHAR(63) NOT NULL,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `version` VARCHAR(63) NOT NULL DEFAULT '' COMMENT 'the version VSAC gave, such as 20260701',
    `codes` INT NOT NULL DEFAULT 0 COMMENT 'how many codes are in uds_value_set_code',
    `fetched_by` INT(11) DEFAULT NULL,
    `fetched_at` DATETIME NOT NULL,
    PRIMARY KEY (`oid`)
) ENGINE=InnoDB COMMENT='UDS Report: VSAC value sets this site fetched with its own UMLS API key';
#EndIf

#IfNotTable uds_value_set_code
CREATE TABLE `uds_value_set_code` (
    `oid` VARCHAR(63) NOT NULL,
    `system` VARCHAR(15) NOT NULL COMMENT 'icd10, cpt, hcpcs, snomed, rxnorm or loinc',
    `code` VARCHAR(63) NOT NULL,
    PRIMARY KEY (`oid`, `system`, `code`)
) ENGINE=InnoDB COMMENT='UDS Report: the codes of each fetched value set';
#EndIf

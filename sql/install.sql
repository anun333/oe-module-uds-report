--
-- UDS Report module.
--
-- Settings tables, plus three coded demographic fields the 2026 UDS Manual needs and
-- OpenEMR doesn't capture in coded form: housing status (Table 4, Lines 17-23),
-- agricultural worker status (Lines 14-16) and Veteran status (Line 25). They are
-- ordinary layout fields in the Demographics "Stats" group, next to race and income,
-- so staff fill them in at registration like any other field.
--

#IfNotTable uds_settings
CREATE TABLE `uds_settings` (
    `name` VARCHAR(63) NOT NULL,
    `value` TEXT NOT NULL COMMENT 'JSON',
    `updated_by` INT(11) DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB COMMENT='UDS Report: clinic choices (countable visit categories, sites, poverty region)';
#EndIf

#IfNotTable uds_insurance_map
CREATE TABLE `uds_insurance_map` (
    `insurance_company_id` INT(11) NOT NULL,
    `category` VARCHAR(31) NOT NULL COMMENT 'none, medicaid, chip_medicaid, medicare, other_public, other_public_chip, private',
    `updated_by` INT(11) DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`insurance_company_id`)
) ENGINE=InnoDB COMMENT='UDS Report: clinic-chosen UDS insurance category per insurance company';
#EndIf

-- Lists
#IfNotRow2D list_options list_id lists option_id uds_housing_status
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`) VALUES ('lists', 'uds_housing_status', 'UDS Housing Status', 1);
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`) VALUES
    ('uds_housing_status', 'housed', 'Housed', 10),
    ('uds_housing_status', 'shelter', 'Homeless shelter', 20),
    ('uds_housing_status', 'transitional', 'Transitional housing', 30),
    ('uds_housing_status', 'doubling_up', 'Doubling up', 40),
    ('uds_housing_status', 'street', 'Street', 50),
    ('uds_housing_status', 'permanent_supportive', 'Permanent supportive housing', 60),
    ('uds_housing_status', 'other_homeless', 'Other homeless arrangement', 70),
    ('uds_housing_status', 'homeless_unknown', 'Homeless, arrangement unknown', 80),
    ('uds_housing_status', 'declined', 'Chose not to answer', 90);
#EndIf

#IfNotRow2D list_options list_id lists option_id uds_agricultural_worker
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`) VALUES ('lists', 'uds_agricultural_worker', 'UDS Agricultural Worker Status', 1);
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`) VALUES
    ('uds_agricultural_worker', 'not_agricultural', 'Not an agricultural worker', 10),
    ('uds_agricultural_worker', 'migratory', 'Migratory agricultural worker or family member', 20),
    ('uds_agricultural_worker', 'seasonal', 'Seasonal agricultural worker or family member', 30),
    ('uds_agricultural_worker', 'former_migratory', 'Aged or disabled former migratory worker or family member', 40),
    ('uds_agricultural_worker', 'declined', 'Chose not to answer', 90);
#EndIf

#IfNotRow2D list_options list_id lists option_id uds_veteran_status
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`) VALUES ('lists', 'uds_veteran_status', 'UDS Veteran Status', 1);
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`) VALUES
    ('uds_veteran_status', 'veteran', 'Served in the U.S. military or uniformed services (not on active duty now)', 10),
    ('uds_veteran_status', 'active_duty', 'Currently on active duty', 20),
    ('uds_veteran_status', 'not_veteran', 'Has not served', 30),
    ('uds_veteran_status', 'declined', 'Chose not to answer', 90);
#EndIf

-- Patient fields
#IfMissingColumn patient_data uds_housing_status
ALTER TABLE `patient_data` ADD `uds_housing_status` VARCHAR(31) NOT NULL DEFAULT '';
#EndIf

#IfMissingColumn patient_data uds_agricultural_worker
ALTER TABLE `patient_data` ADD `uds_agricultural_worker` VARCHAR(31) NOT NULL DEFAULT '';
#EndIf

#IfMissingColumn patient_data uds_veteran_status
ALTER TABLE `patient_data` ADD `uds_veteran_status` VARCHAR(31) NOT NULL DEFAULT '';
#EndIf

-- Demographics layout, "Stats" group (5), after the stock fields, which end with Religion (15) and
-- Tribal Affiliations (16) in OpenEMR 7.0 to 8.5.
#IfNotRow2D layout_options form_id DEM field_id uds_housing_status
INSERT INTO `layout_options` (`form_id`, `field_id`, `group_id`, `title`, `seq`, `data_type`, `uor`, `fld_length`, `max_length`, `list_id`, `titlecols`, `datacols`, `default_value`, `edit_options`, `description`, `fld_rows`)
VALUES ('DEM', 'uds_housing_status', '5', 'Housing Status (UDS)', 17, 1, 1, 0, 0, 'uds_housing_status', 1, 1, '', '', 'Where the patient stayed last night; homeless arrangements are reported on UDS Table 4, Lines 17-23. The UDS report reads this field, not the free-text Homeless field.', 0);
#EndIf

#IfNotRow2D layout_options form_id DEM field_id uds_agricultural_worker
INSERT INTO `layout_options` (`form_id`, `field_id`, `group_id`, `title`, `seq`, `data_type`, `uor`, `fld_length`, `max_length`, `list_id`, `titlecols`, `datacols`, `default_value`, `edit_options`, `description`, `fld_rows`)
VALUES ('DEM', 'uds_agricultural_worker', '5', 'Agricultural Worker (UDS)', 18, 1, 1, 0, 0, 'uds_agricultural_worker', 1, 1, '', '', 'Principal employment in agriculture within 24 months of the last visit; UDS Table 4, Lines 14-16. The UDS report reads this field, not the free-text Migrant/Seasonal field.', 0);
#EndIf

#IfNotRow2D layout_options form_id DEM field_id uds_veteran_status
INSERT INTO `layout_options` (`form_id`, `field_id`, `group_id`, `title`, `seq`, `data_type`, `uor`, `fld_length`, `max_length`, `list_id`, `titlecols`, `datacols`, `default_value`, `edit_options`, `description`, `fld_rows`)
VALUES ('DEM', 'uds_veteran_status', '5', 'Military Service (UDS)', 19, 1, 1, 0, 0, 'uds_veteran_status', 1, 1, '', '', 'Ask: "Have you served in the U.S. military, armed forces, or uniformed services?" UDS Table 4, Line 25', 0);
#EndIf

-- Before 0.15.1 the three fields took positions 15 to 17, sharing 15 and 16 with Religion and Tribal
-- Affiliations. Move them after the stock fields, only where they are still where the module put them,
-- so a site's own order is kept. Veteran first, so no two of ours share a position on the way.
#IfRow3D layout_options form_id DEM field_id uds_veteran_status seq 17
UPDATE `layout_options` SET `seq` = 19 WHERE `form_id` = 'DEM' AND `field_id` = 'uds_veteran_status' AND `seq` = 17;
#EndIf

#IfRow3D layout_options form_id DEM field_id uds_agricultural_worker seq 16
UPDATE `layout_options` SET `seq` = 18 WHERE `form_id` = 'DEM' AND `field_id` = 'uds_agricultural_worker' AND `seq` = 16;
#EndIf

#IfRow3D layout_options form_id DEM field_id uds_housing_status seq 15
UPDATE `layout_options` SET `seq` = 17 WHERE `form_id` = 'DEM' AND `field_id` = 'uds_housing_status' AND `seq` = 15;
#EndIf

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

#IfMissingColumn uds_quality_run refused
ALTER TABLE `uds_quality_run` ADD COLUMN `refused` LONGTEXT DEFAULT NULL COMMENT 'JSON list of {pid, reason}: patients the eCQM engine refused' AFTER `error`;
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

-- An index on core's form_questionnaire_assessments.response_id, which the questionnaire queries join on;
-- shared by name with the UDS, Title X and CCBHC Report modules (see the upgrade file that adds it).
#IfNotIndex form_questionnaire_assessments response_id
ALTER TABLE `form_questionnaire_assessments` ADD INDEX `response_id` (`response_id`(64));
#EndIf

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

-- Demographics layout, "Stats" group (5), after the stock Homeless and Migrant/Seasonal fields.
#IfNotRow2D layout_options form_id DEM field_id uds_housing_status
INSERT INTO `layout_options` (`form_id`, `field_id`, `group_id`, `title`, `seq`, `data_type`, `uor`, `fld_length`, `max_length`, `list_id`, `titlecols`, `datacols`, `default_value`, `edit_options`, `description`, `fld_rows`)
VALUES ('DEM', 'uds_housing_status', '5', 'Housing Status (UDS)', 15, 1, 1, 0, 0, 'uds_housing_status', 1, 1, '', '', 'Where the patient stayed last night; homeless arrangements are reported on UDS Table 4, Lines 17-23', 0);
#EndIf

#IfNotRow2D layout_options form_id DEM field_id uds_agricultural_worker
INSERT INTO `layout_options` (`form_id`, `field_id`, `group_id`, `title`, `seq`, `data_type`, `uor`, `fld_length`, `max_length`, `list_id`, `titlecols`, `datacols`, `default_value`, `edit_options`, `description`, `fld_rows`)
VALUES ('DEM', 'uds_agricultural_worker', '5', 'Agricultural Worker (UDS)', 16, 1, 1, 0, 0, 'uds_agricultural_worker', 1, 1, '', '', 'Principal employment in agriculture within 24 months of the last visit; UDS Table 4, Lines 14-16', 0);
#EndIf

#IfNotRow2D layout_options form_id DEM field_id uds_veteran_status
INSERT INTO `layout_options` (`form_id`, `field_id`, `group_id`, `title`, `seq`, `data_type`, `uor`, `fld_length`, `max_length`, `list_id`, `titlecols`, `datacols`, `default_value`, `edit_options`, `description`, `fld_rows`)
VALUES ('DEM', 'uds_veteran_status', '5', 'Military Service (UDS)', 17, 1, 1, 0, 0, 'uds_veteran_status', 1, 1, '', '', 'Ask: "Have you served in the U.S. military, armed forces, or uniformed services?" UDS Table 4, Line 25', 0);
#EndIf

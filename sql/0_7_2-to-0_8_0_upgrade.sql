--
-- UDS Report 0.7.x to 0.8.0: the patients OpenEMR's eCQM engine refuses, and why, kept with the
-- calculation so Tables 6B and 7 can say who is left out. Named from 0.7.2 so that Module Manager
-- runs it for a module installed at 0.7.0, 0.7.1 or 0.7.2. The same block is in install.sql;
-- it is guarded, so running both changes nothing.
--

#IfMissingColumn uds_quality_run refused
ALTER TABLE `uds_quality_run` ADD COLUMN `refused` LONGTEXT DEFAULT NULL COMMENT 'JSON list of {pid, reason}: patients the eCQM engine refused' AFTER `error`;
#EndIf

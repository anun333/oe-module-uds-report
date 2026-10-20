--
-- UDS Report 0.15.0 to 0.15.1: the three UDS fields on the Demographics form took positions 15 to 17
-- in its Stats group, sharing 15 and 16 with the stock Religion and Tribal Affiliations fields. This
-- moves them to 17 to 19, only where they are still at the module's positions. The same blocks are
-- in install.sql; they are guarded, so running both changes nothing.
--
#IfRow3D layout_options form_id DEM field_id uds_veteran_status seq 17
UPDATE `layout_options` SET `seq` = 19 WHERE `form_id` = 'DEM' AND `field_id` = 'uds_veteran_status' AND `seq` = 17;
#EndIf

#IfRow3D layout_options form_id DEM field_id uds_agricultural_worker seq 16
UPDATE `layout_options` SET `seq` = 18 WHERE `form_id` = 'DEM' AND `field_id` = 'uds_agricultural_worker' AND `seq` = 16;
#EndIf

#IfRow3D layout_options form_id DEM field_id uds_housing_status seq 15
UPDATE `layout_options` SET `seq` = 17 WHERE `form_id` = 'DEM' AND `field_id` = 'uds_housing_status' AND `seq` = 15;
#EndIf

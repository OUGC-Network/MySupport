<?php

function mysupport_setting_names()
{
	$settings = mysupport_settings_info();
	$setting_names = array();

	foreach($settings as $setting)
	{
		$setting_names[] = $setting['name'];
	}

	return $setting_names;
}

function mysupport_settings_info()
{
}

/**
 * Update the display order of settings if settings
**/
function mysupport_update_setting_orders()
{
	global $db;

	$settings = mysupport_setting_names();

	$i = 1;
	foreach($settings as $setting)
	{
		$update = array(
			"disporder" => $i
		);
		$db->update_query("settings", $update, "name = '" . $db->escape_string($setting) . "'");
		$i++;
	}

	rebuild_settings();
}
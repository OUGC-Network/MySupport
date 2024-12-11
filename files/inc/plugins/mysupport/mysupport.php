<?php

/**
 * MySupport 1.8.0
 * Copyright 2010 Matthew Rogowski
 * https://matt.rogow.ski/
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 ** http://www.apache.org/licenses/LICENSE-2.0
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 **/

declare(strict_types=1);

function mysupport_setting_names(): array
{
    $settings = mysupport_settings_info();
    $setting_names = [];

    foreach ($settings as $setting) {
        $setting_names[] = $setting['name'];
    }

    return $setting_names;
}

function mysupport_settings_info(): array
{
    return [];
}

/**
 * Update the display order of settings if settings
 **/
function mysupport_update_setting_orders(): bool
{
    global $db;

    $settings = mysupport_setting_names();

    $i = 1;
    foreach ($settings as $setting) {
        $update = [
            'disporder' => $i
        ];
        $db->update_query('settings', $update, "name = '" . $db->escape_string($setting) . "'");
        $i++;
    }

    rebuild_settings();

    return true;
}
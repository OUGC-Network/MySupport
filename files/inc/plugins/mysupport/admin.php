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

namespace MySupport\Admin;

use DirectoryIterator;

use JetBrains\PhpStorm\NoReturn;

use function MySupport\Core\updateCache;
use function MySupport\Core\loadLanguage;

use function MySupport\MyAlerts\getAvailableLocations;

use function MySupport\MyAlerts\MyAlertsIsIntegrable;

use const MySupport\Core\DATABASE_ROW_TYPE_PRIORITY;
use const MySupport\Core\ROOT;
use const MySupport\Core\VERSION;
use const MySupport\Core\VERSION_CODE;

const TASK_FILE_DEACTIVATE = 0;

const TASK_FILE_INSTALL = 1;

function pluginInformation(): array
{
    global $lang;

    loadLanguage();

    $myAlertsDescription = '';

    if (pluginIsInstalled() && MyAlertsIsIntegrable()) {
        $myAlertsDescription .= $lang->mysupport_myalerts_desc;
    }

    return [
        'name' => 'MySupport',
        'description' => $lang->mysupport_desc . $myAlertsDescription,
        'website' => 'http://mattrogowski.co.uk/mybb/plugins/plugin/mysupport',
        'author' => 'MattRogowski',
        'authorsite' => 'http://mattrogowski.co.uk/mybb/',
        'version' => VERSION,
        'versioncode' => VERSION_CODE,
        'compatibility' => '18*',
        'codename' => 'mysupport',
        'pl' => [
            'version' => 13,
            'url' => 'https://community.mybb.com/mods.php?action=view&pid=573'
        ],
        'myalerts' => [
            'version' => '2.0.4',
            'url' => 'https://community.mybb.com/thread-171301.html'
        ]
    ];
}

function pluginActivation(): bool
{
    global $PL, $lang, $cache, $db;

    loadPluginLibrary();

    $settingsContents = file_get_contents(ROOT . '/settings.json');

    $settingsData = json_decode($settingsContents, true);

    foreach ($settingsData as $settingKey => &$settingData) {
        if (empty($lang->{"setting_mysupport_{$settingKey}"})) {
            continue;
        }

        if (in_array($settingData['optionscode'], ['select', 'checkbox', 'radio'])) {
            foreach ($settingData['options'] as $optionKey) {
                $settingData['optionscode'] .= "\n{$optionKey}={$lang->{"setting_mysupport_{$settingKey}_{$optionKey}"}}";
            }
        }

        $settingData['title'] = $lang->{"setting_mysupport_{$settingKey}"};
        $settingData['description'] = $lang->{"setting_mysupport_{$settingKey}_desc"};
    }

    $PL->settings(
        'mysupport',
        $lang->mysupport,
        $lang->mysupport_desc,
        $settingsData
    );

    $stylesheetsDirIterator = new DirectoryIterator(ROOT . '/stylesheets');

    $stylesheetsItems = [];

    foreach ($stylesheetsDirIterator as $stylesheetFile) {
        if (!$stylesheetFile->isFile()) {
            continue;
        }

        $pathName = $stylesheetFile->getPathname();

        $pathInfo = pathinfo($pathName);

        if ($pathInfo['extension'] === 'css') {
            $stylesheetsItems[$pathInfo['filename']] = file_get_contents($pathName);
        }
    }

    foreach ($stylesheetsItems as $stylesheetContents) {
        $PL->stylesheet(
            'mysupport',
            $stylesheetContents,
            'showthread.php|forumdisplay.php|usercp.php|usercp2.php|modcp.php'
        );
    }

    $templatesDirIterator = new DirectoryIterator(ROOT . '/templates');

    $templatesItems = [];

    foreach ($templatesDirIterator as $templateFile) {
        if (!$templateFile->isFile()) {
            continue;
        }

        $pathName = $templateFile->getPathname();

        $pathInfo = pathinfo($pathName);

        if ($pathInfo['extension'] === 'html') {
            $templatesItems[$pathInfo['filename']] = file_get_contents($pathName);
        }
    }

    if ($templatesItems) {
        $PL->templates('mysupport', 'MySupport', $templatesItems);
    }

    dbVerifyTables();

    dbVerifyColumns();

    taskInstallation();

    change_admin_permission('config', 'mysupport');

    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

    find_replace_templatesets(
        'showthread',
        '#' . preg_quote('{$multipage}') . '#i',
        '{$multipage}{$mysupport_options}'
    );

    find_replace_templatesets(
        'showthread',
        '#' . preg_quote('{$footer}') . '#i',
        '{$mysupport_js}{$footer}'
    );

    find_replace_templatesets(
        'postbit',
        '#' . preg_quote('post_content') . '#i',
        'post_content{$post[\'mysupport_bestanswer_highlight\']}{$post[\'mysupport_staff_highlight\']}'
    );

    find_replace_templatesets(
        'postbit_classic',
        '#' . preg_quote('post_content') . '#i',
        'post_content{$post[\'mysupport_bestanswer_highlight\']}{$post[\'mysupport_staff_highlight\']}'
    );

    find_replace_templatesets(
        'postbit',
        '#' . preg_quote('{$post[\'subject_extra\']}') . '#i',
        '{$post[\'subject_extra\']}<div class="float_right">{$post[\'mysupport_bestanswer\']}{$post[\'mysupport_deny_support_post\']}</div>'
    );

    find_replace_templatesets(
        'postbit_classic',
        '#' . preg_quote('{$post[\'subject_extra\']}') . '#i',
        '{$post[\'subject_extra\']}<div class="float_right">{$post[\'mysupport_bestanswer\']}{$post[\'mysupport_deny_support_post\']}</div>'
    );

    find_replace_templatesets(
        'postbit',
        '#' . preg_quote('{$post[\'icon\']}') . '#i',
        '{$post[\'mysupport_status\']}{$post[\'icon\']}'
    );

    find_replace_templatesets(
        'postbit_classic',
        '#' . preg_quote('{$post[\'icon\']}') . '#i',
        '{$post[\'mysupport_status\']}{$post[\'icon\']}'
    );

    find_replace_templatesets(
        'showthread',
        '#' . preg_quote('{$thread[\'threadprefix\']}') . '#i',
        '{$mysupport_status}{$thread[\'threadprefix\']}'
    );

    find_replace_templatesets(
        'header',
        '#' . preg_quote('{$unreadreports}') . '#i',
        '{$unreadreports}{$mysupport_tech_notice}{$mysupport_assign_notice}'
    );

    find_replace_templatesets(
        'forumdisplay',
        '#' . preg_quote('{$header}') . '#i',
        '{$header}{$mysupport_priority_classes}'
    );

    find_replace_templatesets(
        'search_results_threads ',
        '#' . preg_quote('{$header}') . '#i',
        '{$header}{$mysupport_priority_classes}'
    );

    find_replace_templatesets(
        'forumdisplay_thread',
        '#' . preg_quote('{$prefix}') . '#i',
        '{$mysupport_status}{$mysupport_bestanswer}{$mysupport_assigned}{$prefix}'
    );

    find_replace_templatesets(
        'search_results_threads_thread ',
        '#' . preg_quote('{$prefix}') . '#i',
        '{$mysupport_status}{$mysupport_bestanswer}{$mysupport_assigned}{$prefix}'
    );

    find_replace_templatesets(
        'forumdisplay_thread',
        '#' . preg_quote('{$bgcolor}') . '#i',
        '{$bgcolor}{$priority_class}'
    );

    find_replace_templatesets(
        'forumdisplay_thread_rating',
        '#' . preg_quote('{$bgcolor}') . '#i',
        '{$bgcolor}{$priority_class}'
    );

    find_replace_templatesets(
        'forumdisplay_thread_modbit',
        '#' . preg_quote('{$bgcolor}') . '#i',
        '{$bgcolor}{$priority_class}'
    );

    find_replace_templatesets(
        'search_results_threads_thread',
        '#' . preg_quote('{$bgcolor}') . '#i',
        '{$bgcolor}{$priority_class}'
    );

    find_replace_templatesets(
        'search_results_threads_inlinecheck',
        '#' . preg_quote('{$bgcolor}') . '#i',
        '{$bgcolor}{priority_class}'
    );

    find_replace_templatesets(
        'forumdisplay_inlinemoderation',
        '#' . preg_quote('{$customthreadtools}') . '#i',
        '{$customthreadtools}{$mysupport_inline_thread_moderation}'
    );

    find_replace_templatesets(
        'search_results_threads_inlinemoderation',
        '#' . preg_quote('{$customthreadtools}') . '#i',
        '{$customthreadtools}{$mysupport_inline_thread_moderation}'
    );

    find_replace_templatesets(
        'modcp_nav',
        '#' . preg_quote('{$modcp_nav_users}') . '#i',
        '{$modcp_nav_users}<!--mysupport_nav_option-->'
    );

    find_replace_templatesets(
        'usercp_nav_misc',
        '#' . preg_quote('{$lang->ucp_nav_forum_subscriptions}</a></td></tr>') . '#i',
        '{$lang->ucp_nav_forum_subscriptions}</a></td></tr><!--mysupport_nav_option-->'
    );

    find_replace_templatesets(
        'usercp',
        '#' . preg_quote('{$latest_warnings}') . '#i',
        '{$latest_warnings}<br />{$threads_list}'
    );

    find_replace_templatesets(
        'member_profile',
        '#' . preg_quote('{$profilefields}') . '#i',
        '{$profilefields}{$mysupport_info}'
    );

    find_replace_templatesets(
        'newreply',
        '#' . preg_quote('{$message}</textarea>') . '#i',
        '{$mysupport_solved_bump_message}{$message}</textarea>'
    );

    find_replace_templatesets(
        'showthread_quickreply',
        '#' . preg_quote('</textarea>') . '#i',
        '{$mysupport_solved_bump_message}</textarea>'
    );

    find_replace_templatesets(
        'newthread',
        '#' . preg_quote('{$multiquote_external}') . '#i',
        '{$multiquote_external}{$mysupport_thread_options}'
    );

    /*~*~* RUN UPDATES START *~*~*/

    $db->update_query('mysupport', ['type' => DATABASE_ROW_TYPE_PRIORITY], "type='priority'");

    /*~*~* RUN UPDATES END *~*~*/

    $cache->update_forums();

    $cache->update_usergroups();

    updateCache();

    return true;
}

function pluginDeactivation(): bool
{
    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

    find_replace_templatesets(
        'showthread',
        '#' . preg_quote('{$mysupport_options}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'showthread',
        '#' . preg_quote('{$mysupport_js}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit',
        '#' . preg_quote('{$post[\'mysupport_bestanswer_highlight\']}{$post[\'mysupport_staff_highlight\']}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit_classic',
        '#' . preg_quote('{$post[\'mysupport_bestanswer_highlight\']}{$post[\'mysupport_staff_highlight\']}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit',
        '#' . preg_quote(
            '<div class="float_right">{$post[\'mysupport_bestanswer\']}{$post[\'mysupport_deny_support_post\']}</div>'
        ) . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit_classic',
        '#' . preg_quote(
            '<div class="float_right">{$post[\'mysupport_bestanswer\']}{$post[\'mysupport_deny_support_post\']}</div>'
        ) . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit',
        '#' . preg_quote('{$post[\'mysupport_status\']}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit_classic',
        '#' . preg_quote('{$post[\'mysupport_status\']}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'showthread',
        '#' . preg_quote('{$mysupport_status}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'header',
        '#' . preg_quote('{$mysupport_tech_notice}{$mysupport_assign_notice}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'forumdisplay',
        '#' . preg_quote('{$mysupport_priority_classes}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'search_results_threads ',
        '#' . preg_quote('{$mysupport_priority_classes}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'forumdisplay_thread',
        '#' . preg_quote('{$mysupport_status}{$mysupport_bestanswer}{$mysupport_assigned}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'search_results_threads_thread ',
        '#' . preg_quote('{$mysupport_status}{$mysupport_bestanswer}{$mysupport_assigned}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'forumdisplay_thread',
        '#' . preg_quote('{$priority_class}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'forumdisplay_thread_rating',
        '#' . preg_quote('{$priority_class}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'forumdisplay_thread_modbit',
        '#' . preg_quote('{$priority_class}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'search_results_threads_thread',
        '#' . preg_quote('{$priority_class}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'search_results_threads_inlinecheck',
        '#' . preg_quote('{priority_class}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'forumdisplay_inlinemoderation',
        '#' . preg_quote('{$mysupport_inline_thread_moderation}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'search_results_threads_inlinemoderation',
        '#' . preg_quote('{$mysupport_inline_thread_moderation}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'modcp_nav',
        '#' . preg_quote('<!--mysupport_nav_option-->') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'usercp_nav_misc',
        '#' . preg_quote('<!--mysupport_nav_option-->') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'usercp',
        '#' . preg_quote('<br />{$threads_list}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'member_profile',
        '#' . preg_quote('{$mysupport_info}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'newreply',
        '#' . preg_quote('{$mysupport_solved_bump_message}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'showthread_quickreply',
        '#' . preg_quote('{$mysupport_solved_bump_message}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'newthread',
        '#' . preg_quote('{$mysupport_thread_options}') . '#i',
        '',
        0
    );

    taskDeactivation();

    // Update administrator permissions
    change_admin_permission('config', 'mysupport', 0);

    return true;
}

function loadPluginLibrary(): bool
{
    global $PL, $lang;

    loadLanguage();

    if ($fileExists = file_exists(PLUGINLIBRARY)) {
        global $PL;

        $PL or require_once PLUGINLIBRARY;
    }

    $pluginInformation = pluginInformation();

    if (!$fileExists || $PL->version < $pluginInformation['pl']['version']) {
        flash_message(
            $lang->sprintf(
                $lang->mysupport_pluginlibrary,
                $pluginInformation['pl']['url'],
                $pluginInformation['pl']['version']
            ),
            'error'
        );

        admin_redirect('index.php?module=config-plugins');
    }

    return true;
}

function pluginInstallation(): bool
{
    global $cache, $db, $lang;

    loadLanguage();

    dbVerifyTables();

    dbVerifyColumns();

    $prioritiesContents = file_get_contents(ROOT . '/priorities.json');

    $prioritiesData = json_decode($prioritiesContents, true);

    $priorityItems = [];

    foreach ($prioritiesData as $priorityKey => &$priorityData) {
        if (isset($lang->{"mySupportPriorities{$priorityKey}"})) {
            $priorityItems[] = [
                'name' => $lang->{"mySupportPriorities{$priorityKey}"},
                'description' => $lang->{"mySupportPriorities{$priorityKey}Description"},
                'extra' => $priorityData['extra'],
            ];
        }
    }

    foreach ($priorityItems as $priorityItem) {
        $db->insert_query('mysupport', [
            'type' => DATABASE_ROW_TYPE_PRIORITY,
            'name' => $db->escape_string($priorityItem['name']),
            'description' => $db->escape_string($priorityItem['description']),
            'extra' => $db->escape_string($priorityItem['extra']),
        ]);
    }

    // set some values for the staff groups
    $updateData = [];

    foreach (dbDataColumns()['usergroups'] as $fieldName => $fieldDefinition) {
        $updateData[$fieldName] = 1;
    }

    $db->update_query('usergroups', $updateData, 'gid IN (3,4,6)');

    // MyAlerts
    $MyAlertLocationsInstalled = array_filter(
        getAvailableLocations(),
        '\\MySupport\MyAlerts\\isLocationAlertTypePresent'
    );

    $cache->update('mysupport', [
        'MyAlertLocationsInstalled' => $MyAlertLocationsInstalled,
    ]);

    return true;
}

function pluginIsInstalled(): bool
{
    global $db;

    static $isInstalled = null;

    if ($isInstalled === null) {
        $isInstalled = true;

        foreach (dbDataTables() as $tableName => $tableFields) {
            $isInstalled = $db->table_exists($tableName) && $isInstalled;
        }
    }

    return $isInstalled;
}

function pluginUninstallation(): bool
{
    global $db, $PL, $cache;

    loadPluginLibrary();

    foreach (dbDataTables() as $tableName => $tableFields) {
        $db->drop_table($tableName);
    }

    foreach (dbDataColumns() as $tableName => $tableFields) {
        foreach ($tableFields as $fieldName => $definition) {
            !$db->field_exists($fieldName, $tableName) || $db->drop_column($tableName, $fieldName);
        }
    }

    $PL->stylesheet_delete('mysupport');

    $PL->settings_delete('mysupport');

    $PL->templates_delete('mysupport');

    taskUninstallation();

    // Remove administrator permissions
    change_admin_permission('config', 'mysupport', -1);

    $cache->update_forums();

    $cache->update_usergroups();

    $cache->update_moderators();

    // Delete version from cache
    $cache->delete('mysupport');

    return true;
}

// List of tables
function dbDataTables(): array
{
    return [
        'mysupport' => [
            'mid' => 'SMALLINT(5) NOT NULL AUTO_INCREMENT',
            'type' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'name' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'description' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'extra' => "VARCHAR(255) NOT NULL default ''",
            'groups' => "VARCHAR(255) NOT NULL default ''",
            'forums' => "VARCHAR(255) NOT NULL default ''",
            'primary_key' => 'mid',
            'unique_key' => []
        ],
        //'unique_key' => ['uid' => 'uid']
    ];
}

// List of columns
function dbDataColumns(): array
{
    return [
        'forums' => [
            'mysupport' => "INT(1) NOT NULL DEFAULT '0'",
            'mysupportmove' => "INT(1) NOT NULL DEFAULT '1'",
            'mysupportdenial' => "INT(1) NOT NULL DEFAULT '1'",
            'technicalthreads' => "INT(5) NOT NULL DEFAULT '0'", // counter stat ?
            'allowsolvestatus' => "INT(1) NOT NULL DEFAULT '1'",
            'allowtechnicalstatus' => "INT(1) NOT NULL DEFAULT '1'",
            'allowbestanswerstatus' => "INT(1) NOT NULL DEFAULT '1'",
            'allowonholdstatus' => "INT(1) NOT NULL DEFAULT '1'",
            'allowhighlight' => "INT(1) NOT NULL DEFAULT '1'",
            'allownonsupportthreads' => "INT(1) NOT NULL DEFAULT '0'",
        ],
        //mysupportmove should probably be left for moderation tools
        'threads' => [
            'status' => "INT(1) NOT NULL DEFAULT '0'",
            'statusuid' => "INT(10) NOT NULL DEFAULT '0'",
            'statustime' => "INT(10) NOT NULL DEFAULT '0'",
            'onhold' => "INT(1) NOT NULL DEFAULT '0'",
            'bestanswer' => "INT(10) NOT NULL DEFAULT '0'",
            'assign' => "INT(10) NOT NULL DEFAULT '0'",
            'assignuid' => "INT(10) NOT NULL DEFAULT '0'",
            'priority' => "INT(5) NOT NULL DEFAULT '0'",
            'closedbymysupport' => "INT(1) NOT NULL DEFAULT '0'",
            'issupportthread' => "INT(1) NOT NULL DEFAULT '1'",
        ],
        'users' => [
            'assignedthreads' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'deniedsupport' => "INT(1) NOT NULL DEFAULT '0'",
            'deniedsupportreason' => "INT(5) NOT NULL DEFAULT '0'",
            'deniedsupportuid' => "INT(10) NOT NULL DEFAULT '0'",
            'mysupportdisplayastext' => "INT(1) NOT NULL DEFAULT '0'"
        ],
        'usergroups' => [
            'canmarksolved' => "INT(1) NOT NULL DEFAULT '1'",
            'canseetechnotice' => "INT(1) NOT NULL DEFAULT '1'",
            'canbeassigned' => "INT(1) NOT NULL DEFAULT '1'",
            'canseepriorities' => "INT(1) NOT NULL DEFAULT '0'",
            'canmarkbestanswer' => "INT(1) NOT NULL DEFAULT '1'",
            'canmarkonhold' => "INT(1) NOT NULL DEFAULT '1'",
            'canmarkasnonsupport' => "INT(1) NOT NULL DEFAULT '0'",
            'canmanagesupportdenial' => "INT(1) NOT NULL DEFAULT '0'",
        ],
        // we will use the is_moderator() function to allow moderators run some tools so we leave only tools at least the author will be able to use
        'moderators' => [
            'canmarksolved' => "INT(1) NOT NULL DEFAULT '1'",
            'canmarktechnical' => "INT(1) NOT NULL DEFAULT '1'",
            'canassign' => "INT(1) NOT NULL DEFAULT '1'",
            'cansetpriorities' => "INT(1) NOT NULL DEFAULT '1'",
            'canmanagesupportdenial' => "INT(1) NOT NULL DEFAULT '1'",
            // this will be forum specific (mods) or all forums (super mods)
            'canmarkbestanswer' => "INT(1) NOT NULL DEFAULT '1'",
            'canmarkonhold' => "INT(1) NOT NULL DEFAULT '1'",
            'canmarkasnonsupport' => "INT(1) NOT NULL DEFAULT '1'",
        ]
    ];
}

// Verify DB indexes
function dbVerifyIndexes(): bool
{
    global $db;

    foreach (dbDataTables() as $tableName => $tableFields) {
        if (!$db->table_exists($tableName)) {
            continue;
        }

        if (isset($tableFields['unique_key'])) {
            foreach ($tableFields['unique_key'] as $uniqueKeyName => $uniqueKeyFields) {
                if ($db->index_exists($tableName, $uniqueKeyName)) {
                    continue;
                }

                $db->write_query(
                    "ALTER TABLE {$db->table_prefix}{$tableName} ADD UNIQUE KEY {$uniqueKeyName} ({$uniqueKeyFields})"
                );
            }
        }
    }

    return true;
}

// Verify DB tables
function dbVerifyTables(): bool
{
    global $db;

    foreach (dbDataTables() as $tableName => $tableFields) {
        if ($db->table_exists($tableName)) {
            foreach ($tableFields as $fieldName => $fieldDefinition) {
                if ($fieldName == 'primary_key' || $fieldName == 'unique_key') {
                    continue;
                }

                if ($db->field_exists($fieldName, $tableName)) {
                    $db->modify_column($tableName, "`{$fieldName}`", $fieldDefinition);
                } else {
                    $db->add_column($tableName, $fieldName, $fieldDefinition);
                }
            }
        } else {
            $dbQuery = "CREATE TABLE IF NOT EXISTS `{$db->table_prefix}{$tableName}` (";

            foreach ($tableFields as $fieldName => $fieldDefinition) {
                if ($fieldName == 'primary_key') {
                    $dbQuery .= "PRIMARY KEY (`{$fieldDefinition}`)";
                } elseif ($fieldName != 'unique_key') {
                    $dbQuery .= "`{$fieldName}` {$fieldDefinition},";
                }
            }

            $dbQuery .= ") ENGINE=MyISAM{$db->build_create_table_collation()};";

            $db->write_query($dbQuery);
        }
    }

    dbVerifyIndexes();

    return true;
}

function dbVerifyColumns(): bool
{
    global $db;

    foreach (dbDataColumns() as $tableName => $tableFields) {
        foreach ($tableFields as $fieldName => $fieldDefinition) {
            if ($db->field_exists($fieldName, $tableName)) {
                $db->modify_column($tableName, "`{$fieldName}`", $fieldDefinition);
            } else {
                $db->add_column($tableName, $fieldName, $fieldDefinition);
            }
        }
    }

    return true;
}

function taskInstallation(int $processAction = TASK_FILE_INSTALL): bool
{
    global $db, $lang;

    loadLanguage();

    $dbQuery = $db->simple_select('tasks', '*', "file='mysupport'", ['limit' => 1]);

    $taskData = $db->fetch_array($dbQuery);

    if ($taskData) {
        $db->update_query('tasks', ['enabled' => $processAction], "file='mysupport'");
    } else {
        include_once MYBB_ROOT . 'inc/functions_task.php';

        $_ = $db->escape_string('*');

        $newTaskData = [
            'title' => $db->escape_string($lang->mysupport),
            'description' => $db->escape_string($lang->mysupport_task_description),
            'file' => $db->escape_string('mysupport'),
            'minute' => 0,
            'hour' => 0,
            'day' => $_,
            'weekday' => $_,
            'month' => $_,
            'enabled' => 1,
            'logging' => 1
        ];

        $newTaskData['nextrun'] = fetch_next_run($newTaskData);

        $db->insert_query('tasks', $newTaskData);
    }

    return true;
}

function taskUninstallation(): bool
{
    global $db;

    $db->delete_query('tasks', "file='mysupport'");

    return true;
}

function taskDeactivation(): bool
{
    taskInstallation(TASK_FILE_DEACTIVATE);

    return true;
}

function getSettingGroupID(): int
{
    global $db;

    $dbQuery = $db->simple_select('settinggroups', 'gid', "name = 'mysupport'", ['limit' => 1]);

    if (!$db->num_rows($dbQuery)) {
        return 0;
    }

    return (int)$db->fetch_field($dbQuery, 'gid');
}
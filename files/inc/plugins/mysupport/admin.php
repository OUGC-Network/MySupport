<?php

/**
 * MySupport 1.8.0
 * Copyright 2010 Matthew Rogowski
 * https://matt.rogow.ski/
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may get a copy of the License at
 ** http://www.apache.org/licenses/LICENSE-2.0
 * Unless required by applicable law or agreed to in writing; software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 **/

declare(strict_types=1);

namespace MySupport\Admin;

use MyBB;
use DirectoryIterator;

use MySupport\Core\AssignStatus;
use MySupport\Core\ThreadStatus;

use function MySupport\Core\assignGet;
use function MySupport\Core\assignInsert;
use function MySupport\Core\backupUpdate;
use function MySupport\Core\deniedReasonUpdate;
use function MySupport\Core\forumsUpdateTechnicalCount;
use function MySupport\Core\priorityGet;
use function MySupport\Core\deniedReasonGet;
use function MySupport\Core\backupGet;
use function MySupport\Core\priorityInsert;
use function MySupport\Core\priorityUpdate;
use function MySupport\Core\threadsGet;
use function MySupport\Core\threadUpdate;
use function MySupport\Core\updateCache;
use function MySupport\Core\languageLoad;
use function MySupport\Core\usersGet;
use function MySupport\Core\usersUpdateAssignCount;
use function MySupport\MyAlerts\getAvailableLocations;
use function MySupport\MyAlerts\MyAlertsIsIntegrable;

use const MySupport\Core\ROOT;
use const MySupport\Core\VERSION;
use const MySupport\Core\VERSION_CODE;
use const MySupport\Core\DATABASE_ROW_TYPE_PRIORITY;
use const MySupport\Core\DATABASE_ROW_TYPE_DENIED_REASON;
use const MySupport\Core\DATABASE_ROW_TYPE_BACKUP;

const TASK_FILE_DEACTIVATE = 0;

const TASK_FILE_INSTALL = 1;

const TABLES_DATA = [
    'mysupport' => [
        'mid' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'type' => [
            'type' => 'VARCHAR',
            'size' => 20,
            'default' => ''
        ],
        'name' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => ''
        ],
        'description' => [
            'type' => 'VARCHAR',
            'size' => 500,
            'default' => ''
        ],
        'extra' => [
            'type' => 'VARCHAR',
            'size' => 255,
            'default' => ''
        ],
        'allowed_groups' => [
            'type' => 'TEXT',
            'null' => true,
        ],
        'allowed_forums' => [
            'type' => 'TEXT',
            'null' => true,
        ],
        //'unique_key' => ['uid' => 'uid']
    ],
    'mysupport_assigned_threads' => [
        'assign_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'auto_increment' => true,
            'primary_key' => true
        ],
        'user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'assigner_user_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'thread_id' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'dateline' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'status' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 1,
        ],
    ],
];

const FIELDS_DATA = [
    'forums' => [
        'mysupport' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'mysupportmove' => [ // todo, deprecate (alternative: custom moderation tools)
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'mysupportdenial' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'technicalthreads' => [ // counter stat ?
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'allowsolvestatus' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'allowtechnicalstatus' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'allowbestanswerstatus' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'allowonholdstatus' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'allowhighlight' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'allownonsupportthreads' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'mysupport_message_placeholder' => [
            'type' => 'TEXT',
            'null' => true,
            'formType' => 'textarea',
        ],
    ],
    'threads' => [
        'status' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'statusuid' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'statustime' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'mysupport_is_technical' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'onhold' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'bestanswer' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'assign' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'assignuid' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'priority' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'closedbymysupport' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'issupportthread' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
    ],
    'users' => [
        'assignedthreads' => [
            'type' => 'TEXT',
            'null' => true,
        ],
        'deniedsupport' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'deniedsupportreason' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'deniedsupportuid' => [
            'type' => 'INT',
            'unsigned' => true,
            'default' => 0,
        ],
        'mysupportdisplayastext' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
    ],
    'usergroups' => [
        'canmarksolved' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canseetechnotice' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canbeassigned' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canseepriorities' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'canmarkbestanswer' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canmarkonhold' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canmarkasnonsupport' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
        'canmanagesupportdenial' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 0,
        ],
    ],
    // we will use the is_moderator() function to allow moderators run some tools so we leave only tools at least the author will be able to use
    'moderators' => [
        'canmarksolved' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canmarktechnical' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canassign' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'cansetpriorities' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canmanagesupportdenial' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        // this will be forum-specific (mods) or all forums (super mods)
        'canmarkbestanswer' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canmarkonhold' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
        'canmarkasnonsupport' => [
            'type' => 'TINYINT',
            'unsigned' => true,
            'default' => 1,
        ],
    ],
];

function pluginInformation(): array
{
    global $lang;

    languageLoad();

    $myAlertsDescription = '';

    if (pluginIsInstalled() && MyAlertsIsIntegrable()) {
        $myAlertsDescription .= $lang->mysupport_myalerts_desc;
    }

    return [
        'name' => 'MySupport',
        'description' => $lang->mysupport_desc . $myAlertsDescription,
        'website' => 'https://matt.rogow.ski/',
        'author' => 'MattRogowski',
        'authorsite' => 'https://matt.rogow.ski/',
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

function pluginActivation(): void
{
    global $PL, $lang, $mybb;

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
    /*
        find_replace_templatesets(
            'usercp',
            '#' . preg_quote('{$latest_warnings}') . '#i',
            '{$latest_warnings}<br />{$threads_list}'
        );
    */
    /*~*~* RUN UPDATES START *~*~*/

    foreach (priorityGet(["type='priority'"]) as $priorityData) {
        priorityUpdate(['type' => DATABASE_ROW_TYPE_PRIORITY], (int)$priorityData['mid']);
    }

    foreach (deniedReasonGet(["type='deniedreason'"]) as $deniedReasonData) {
        deniedReasonUpdate(['type' => DATABASE_ROW_TYPE_DENIED_REASON], (int)$deniedReasonData['mid']);
    }

    foreach (backupGet(["type='backup'"]) as $backupData) {
        backupUpdate(['type' => DATABASE_ROW_TYPE_BACKUP], (int)$backupData['mid']);
    }

    /*~*~* RUN UPDATES END *~*~*/

    $mybb->cache->update_forums();

    $mybb->cache->update_usergroups();

    updateCache();
}

function pluginDeactivation(): void
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
        '#' . preg_quote('{$post[\'mysupport_staff_highlight\']}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit',
        '#' . preg_quote('{$post[\'mysupport_staff_highlight\']}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit_classic',
        '#' . preg_quote('{$post[\'mysupport_bestanswer_highlight\']}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'postbit_classic',
        '#' . preg_quote('{$post[\'mysupport_staff_highlight\']}') . '#i',
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
        '#' . preg_quote('{$mysupport_tech_notice}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'header',
        '#' . preg_quote('{$mysupport_assign_notice}') . '#i',
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
        '#' . preg_quote('{$mysupport_status}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'forumdisplay_thread',
        '#' . preg_quote('{$mysupport_bestanswer}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'forumdisplay_thread',
        '#' . preg_quote('{$mysupport_assigned}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'search_results_threads_thread ',
        '#' . preg_quote('{$mysupport_status}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'search_results_threads_thread ',
        '#' . preg_quote('{$mysupport_bestanswer}') . '#i',
        '',
        0
    );

    find_replace_templatesets(
        'search_results_threads_thread ',
        '#' . preg_quote('{$mysupport_assigned}') . '#i',
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
}

function loadPluginLibrary(): void
{
    global $PL, $lang;

    languageLoad();

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
}

function pluginInstallation(): void
{
    global $mybb, $db, $lang;

    loadPluginLibrary();

    languageLoad();

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
        priorityInsert([
            'name' => $db->escape_string($priorityItem['name']),
            'description' => $db->escape_string($priorityItem['description']),
            'extra' => $db->escape_string($priorityItem['extra']),
        ]);
    }

    // set some values for the staff groups

    $updateData = array_map(function () {
        return 1;
    }, FIELDS_DATA['usergroups']);

    $db->update_query('usergroups', $updateData, 'gid IN (3,4,6)');

    // MyAlerts
    $MyAlertLocationsInstalled = array_filter(
        getAvailableLocations(),
        '\\MySupport\MyAlerts\\isLocationAlertTypePresent'
    );

    $mybb->cache->update('mysupport', [
        'MyAlertLocationsInstalled' => $MyAlertLocationsInstalled,
    ]);
}

function pluginIsInstalled(): bool
{
    static $isInstalled = null;

    if ($isInstalled === null) {
        global $db;

        $isInstalledEach = true;

        foreach (array_merge(TABLES_DATA, FIELDS_DATA) as $tableName => $tableColumns) {
            $isInstalledEach = $db->table_exists($tableName) && $isInstalledEach;

            if (!$isInstalledEach) {
                break;
            }

            foreach ($tableColumns as $fieldName => $fieldDefinition) {
                $isInstalledEach = $db->field_exists($fieldName, $tableName) && $isInstalledEach;

                if (!$isInstalledEach) {
                    break;
                }
            }
        }

        $isInstalled = $isInstalledEach;
    }

    return $isInstalled;
}

function pluginUninstallation(): void
{
    global $db, $PL, $mybb;

    loadPluginLibrary();

    foreach (TABLES_DATA as $tableName => $tableFields) {
        $db->drop_table($tableName);
    }

    foreach (FIELDS_DATA as $tableName => $tableFields) {
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

    $mybb->cache->update_forums();

    $mybb->cache->update_usergroups();

    $mybb->cache->update_moderators();

    // Delete a version from the cache
    $mybb->cache->delete('mysupport');
}

function dbTables(): array
{
    $tables_data = [];

    foreach (TABLES_DATA as $tableName => $tableColumns) {
        foreach ($tableColumns as $fieldName => $fieldData) {
            if (!isset($fieldData['type'])) {
                continue;
            }

            $tables_data[$tableName][$fieldName] = dbBuildFieldDefinition($fieldData);
        }

        foreach ($tableColumns as $fieldName => $fieldData) {
            if (isset($fieldData['primary_key'])) {
                $tables_data[$tableName]['primary_key'] = $fieldName;
            }

            if ($fieldName === 'unique_key') {
                $tables_data[$tableName]['unique_key'] = $fieldData;
            }
        }
    }

    return $tables_data;
}

function dbVerifyTables(): bool
{
    global $db;

    $collation = $db->build_create_table_collation();

    foreach (dbTables() as $tableName => $tableColumns) {
        if ($db->table_exists($tableName)) {
            foreach ($tableColumns as $fieldName => $fieldData) {
                if ($fieldName === 'primary_key' || $fieldName === 'unique_key') {
                    continue;
                }

                if ($db->field_exists($fieldName, $tableName)) {
                    $db->modify_column($tableName, "`{$fieldName}`", $fieldData);
                } else {
                    $db->add_column($tableName, $fieldName, $fieldData);
                }
            }
        } else {
            $query_string = "CREATE TABLE IF NOT EXISTS `{$db->table_prefix}{$tableName}` (";

            foreach ($tableColumns as $fieldName => $fieldData) {
                if ($fieldName === 'primary_key') {
                    $query_string .= "PRIMARY KEY (`{$fieldData}`)";
                } elseif ($fieldName !== 'unique_key') {
                    $query_string .= "`{$fieldName}` {$fieldData},";
                }
            }

            $query_string .= ") ENGINE=MyISAM{$collation};";

            $db->write_query($query_string);
        }
    }

    dbVerifyIndexes();

    return true;
}

function dbVerifyIndexes(): bool
{
    global $db;

    foreach (dbTables() as $tableName => $tableColumns) {
        if (!$db->table_exists($tableName)) {
            continue;
        }

        if (isset($tableColumns['unique_key'])) {
            foreach ($tableColumns['unique_key'] as $key_name => $key_value) {
                if ($db->index_exists($tableName, $key_name)) {
                    continue;
                }

                $db->write_query(
                    "ALTER TABLE {$db->table_prefix}{$tableName} ADD UNIQUE KEY {$key_name} ({$key_value})"
                );
            }
        }
    }

    return true;
}

function dbVerifyColumns(): bool
{
    global $db;

    foreach (FIELDS_DATA as $tableName => $tableColumns) {
        if (!$db->table_exists($tableName)) {
            continue;
        }

        foreach ($tableColumns as $fieldName => $fieldData) {
            if (!isset($fieldData['type'])) {
                continue;
            }

            if ($db->field_exists($fieldName, $tableName)) {
                $db->modify_column($tableName, "`{$fieldName}`", dbBuildFieldDefinition($fieldData));
            } else {
                $db->add_column($tableName, $fieldName, dbBuildFieldDefinition($fieldData));
            }
        }
    }

    return true;
}

function dbBuildFieldDefinition(array $fieldData): string
{
    $field_definition = '';

    $field_definition .= $fieldData['type'];

    if (isset($fieldData['size'])) {
        $field_definition .= "({$fieldData['size']})";
    }

    if (isset($fieldData['unsigned'])) {
        if ($fieldData['unsigned'] === true) {
            $field_definition .= ' UNSIGNED';
        } else {
            $field_definition .= ' SIGNED';
        }
    }

    if (!isset($fieldData['null'])) {
        $field_definition .= ' NOT';
    }

    $field_definition .= ' NULL';

    if (isset($fieldData['auto_increment'])) {
        $field_definition .= ' AUTO_INCREMENT';
    }

    if (isset($fieldData['default'])) {
        $field_definition .= " DEFAULT '{$fieldData['default']}'";
    }

    return $field_definition;
}

function taskInstallation(int $processAction = TASK_FILE_INSTALL): void
{
    global $db, $lang;

    languageLoad();

    $dbQuery = $db->simple_select('tasks', 'tid', "file='mysupport'", ['limit' => 1]);

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
}

function taskUninstallation(): void
{
    global $db;

    $db->delete_query('tasks', "file='mysupport'");
}

function taskDeactivation(): void
{
    taskInstallation(TASK_FILE_DEACTIVATE);
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

function recountRebuildAssignmentRows(): void
{
    global $db, $mybb, $lang;

    $db->update_query('users', ['assignedthreads' => '']);

    $totalAssignedThreads = threadsGet(
        ["assign!='0'"],
        ['COUNT(tid) as total_threads'],
        ['limit' => 1]
    )['total_threads'] ?? 0;

    $page = $mybb->get_input('page', MyBB::INPUT_INT);

    $perPage = $mybb->get_input('mysupport_rebuild_assignment_rows', MyBB::INPUT_INT);

    $start = ($page - 1) * $perPage;

    $end = $start + $perPage;

    foreach (
        threadsGet(
            ["assign!='0'"],
            ['tid', 'assign', 'assignuid'],
            ['order_by' => 'tid', 'order_dir' => 'asc', 'limit_start' => $start, 'limit' => $perPage]
        ) as $threadID => $threadData
    ) {
        if (!assignGet(["thread_id='{$threadID}'", "status='" . AssignStatus::Active . "'"])) {
            assignInsert([
                'user_id' => $threadData['assign'],
                'assigner_user_id' => $threadData['assignuid'],
                'thread_id' => $threadID,
            ]);
        }
    }

    check_proceed(
        $totalAssignedThreads,
        $end,
        ++$page,
        $perPage,
        'mysupport_rebuild_assignment_rows',
        'do_mysupport_rebuild_assignment_rows',
        $lang->mySupportRebuildAssignmentRowsSuccess
    );
}

function recountRebuildAssignmentCounters(): void
{
    global $db, $mybb, $lang;

    $query = $db->simple_select('users', 'COUNT(uid) as total_users');

    $totalUsers = $db->fetch_field($query, 'total_users');

    $page = $mybb->get_input('page', MyBB::INPUT_INT);

    $perPage = $mybb->get_input('mysupport_recount_assignment_counters', MyBB::INPUT_INT);

    $start = ($page - 1) * $perPage;

    $end = $start + $perPage;

    foreach (
        usersGet(
            queryFields: ['uid'],
            queryOptions: ['order_by' => 'uid', 'order_dir' => 'asc', 'limit_start' => $start, 'limit' => $perPage]
        ) as $userID => $userData
    ) {
        usersUpdateAssignCount($userID);
    }

    check_proceed(
        $totalUsers,
        $end,
        ++$page,
        $perPage,
        'mysupport_recount_assignment_counters',
        'do_mysupport_recount_assignment_counters',
        $lang->mySupportRebuildAssignmentCountersSuccess
    );
}

function recountRebuildTechnicalRows(): void
{
    global $mybb, $lang;

    $totalTechnicalThreads = threadsGet(
        ["status='2'"],
        ['COUNT(tid) as total_technical_threads'],
        ['limit' => 1]
    )['total_technical_threads'] ?? 0;

    $page = $mybb->get_input('page', MyBB::INPUT_INT);

    $perPage = $mybb->get_input('mysupport_rebuild_technical_rows', MyBB::INPUT_INT);

    $start = ($page - 1) * $perPage;

    $end = $start + $perPage;

    foreach (
        threadsGet(
            ["status='2'"],
            ['tid'],
            ['order_by' => 'tid', 'order_dir' => 'asc', 'limit_start' => $start, 'limit' => $perPage]
        ) as $threadID => $threadData
    ) {
        threadUpdate([
            'status' => ThreadStatus::NotSolved,
            'mysupport_is_technical' => ThreadStatus::Technical,
        ], $threadID);
    }

    check_proceed(
        $totalTechnicalThreads,
        $end,
        ++$page,
        $perPage,
        'mysupport_rebuild_technical_rows',
        'do_mysupport_rebuild_technical_rows',
        $lang->mySupportRebuildTechnicalRowsSuccess
    );
}

function recountRebuildTechnicalCounters(): void
{
    global $db, $mybb, $lang;

    $query = $db->simple_select('forums', 'COUNT(fid) as total_forums');

    $totalForums = $db->fetch_field($query, 'total_forums');

    $page = $mybb->get_input('page', MyBB::INPUT_INT);

    $perPage = $mybb->get_input('mysupport_recount_technical_counters', MyBB::INPUT_INT);

    $start = ($page - 1) * $perPage;

    $end = $start + $perPage;

    $query = $db->simple_select(
        'forums',
        'fid',
        '',
        ['order_by' => 'fid', 'order_dir' => 'asc', 'limit_start' => $start, 'limit' => $perPage]
    );

    while ($forumData = $db->fetch_array($query)) {
        forumsUpdateTechnicalCount((int)$forumData['fid']);
    }

    $mybb->cache->update_forums();

    check_proceed(
        $totalForums,
        $end,
        ++$page,
        $perPage,
        'mysupport_recount_technical_counters',
        'do_mysupport_recount_technical_counters',
        $lang->mySupportRebuildTechnicalCountersSuccess
    );
}
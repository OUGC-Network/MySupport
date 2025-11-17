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

namespace MySupport\AdminHooks;

use Form;
use MyBB;

use function MySupport\Admin\recountRebuildAssignmentCounters;
use function MySupport\Admin\recountRebuildAssignmentRows;
use function MySupport\Admin\recountRebuildTechnicalCounters;
use function MySupport\Admin\recountRebuildTechnicalRows;
use function MySupport\Core\languageLoad;
use function MySupport\Admin\getSettingGroupID;
use function MySupport\MyAlerts\getAvailableLocations;
use function MySupport\MyAlerts\installLocation;
use function MySupport\MyAlerts\MyAlertsIsIntegrable;

use const MySupport\Core\ROOT;
use const MySupport\Admin\FIELDS_DATA;

function admin_config_plugins_begin01(): void
{
    global $mybb, $lang, $page;

    if ($mybb->get_input('action') !== 'mysupport') {
        return;
    }

    languageLoad();

    if ($mybb->request_method !== 'post') {
        $page->output_confirm_action(
            'index.php?module=config-plugins&amp;action=mysupport',
            $lang->mysupport_myalerts_confirm
        );
    }

    if ($mybb->get_input('no') || !MyAlertsIsIntegrable()) {
        admin_redirect('index.php?module=config-plugins');
    }

    $availableLocations = getAvailableLocations();

    //$installedLocations = \MySupport\MyAlerts\getInstalledLocations();

    foreach ($availableLocations as $availableLocation) {
        installLocation($availableLocation);
    }

    flash_message($lang->mysupport_myalerts_success, 'success');

    admin_redirect('index.php?module=config-plugins');
}

function admin_config_plugins_deactivate(): void
{
    global $mybb, $page;

    if (
        $mybb->get_input('action') !== 'deactivate' ||
        $mybb->get_input('plugin') !== 'mysupport' ||
        !$mybb->get_input('uninstall', MyBB::INPUT_INT)
    ) {
        return;
    }

    if ($mybb->request_method !== 'post') {
        $page->output_confirm_action(
            'index.php?module=config-plugins&amp;action=deactivate&amp;uninstall=1&amp;plugin=mysupport'
        );
    }

    if ($mybb->get_input('no')) {
        admin_redirect('index.php?module=config-plugins');
    }
}

function admin_load(): void
{
    global $modules_dir, $run_module, $action_file, $page;

    if ($run_module !== 'config' || $page->active_action !== 'mysupport') {
        return;
    }

    $modules_dir = ROOT;

    $run_module = 'admin';

    $action_file = 'mysupport.php';
}

function admin_config_action_handler(array $actionObjects): array
{
    $actionObjects['mysupport'] = [
        'active' => 'mysupport',
        'file' => 'mysupport.php'
    ];

    return $actionObjects;
}

function admin_config_menu(array $subMenuItems): array
{
    global $lang;

    languageLoad();

    $subMenuItems[] = [
        'id' => 'mysupport',
        'title' => $lang->mysupport,
        'link' => 'index.php?module=config-mysupport'
    ];

    return $subMenuItems;
}

function admin_config_permissions(array $adminPermissions): array
{
    global $lang;

    languageLoad();

    $adminPermissions['mysupport'] = $lang->can_manage_mysupport;

    return $adminPermissions;
}

function admin_settings_print_peekers(array $settingPeekers): array
{
    global $mybb;

    if ($mybb->get_input('module') !== 'config-settings' || $mybb->get_input('action') !== 'change') {
        return $settingPeekers;
    }

    $groupID = $mybb->get_input('module', MyBB::INPUT_INT);

    if ($groupID === getSettingGroupID() || !$groupID) {
        $settingPeekers = array_merge($settingPeekers, [
            'new Peeker($(".setting_mysupport_enabletechnical"), $("#row_setting_mysupport_hidetechnical"), 1, true)',
            'new Peeker($(".setting_mysupport_enabletechnical"), $("#row_setting_mysupport_technicalnotice"), 1, true)',
            'new Peeker($(".setting_mysupport_enableassign"), $("#row_setting_mysupport_assignpm"), 1, true)',
            'new Peeker($(".setting_mysupport_enableassign"), $("#row_setting_mysupport_assignsubscribe"), 1, true)',
            'new Peeker($(".setting_mysupport_pointssystem"), $("#row_setting_mysupport_pointssystemname"), /other/, false)',
            'new Peeker($(".setting_mysupport_pointssystem"), $("#row_setting_mysupport_pointssystemcolumn"), /other/, false)',
            'new Peeker($(".setting_mysupport_pointssystem"), $("#row_setting_mysupport_bestanswerpoints"), /[^none]/, false)',
        ]);
    }

    return $settingPeekers;
}

// Insert the require code in the group edit page.
function admin_formcontainer_end(array &$formArguments): array
{
    global $run_module, $form_container, $lang, $form, $mybb;

    if ($run_module !== 'user' || empty($lang->forums_posts) || $form_container->_title !== $lang->forums_posts) {
        return $formArguments;
    }

    languageLoad();

    $userOptions = $moderatorOptions = [];

    foreach (FIELDS_DATA['usergroups'] as $fieldName => $fieldDefinition) {
        $userPermissions = 'userOptions';

        if ($fieldName === 'canmanagesupportdenial') {
            $userPermissions = 'moderatorOptions';
        }

        ${$userPermissions}[] = $form->generate_check_box(
            $fieldName,
            1,
            $lang->{'mysupport_usergroups_' . $fieldName},
            ['id' => $fieldName, 'checked' => $mybb->get_input($fieldName, MyBB::INPUT_INT)]
        );
    }

    foreach ([$userOptions, $moderatorOptions] as $groupOptions) {
        $form_container->output_row(
            $lang->mysupport,
            '',
            '<div class="group_settings_bit">' . implode(
                '</div><div class="group_settings_bit">',
                $groupOptions
            ) . '</div>'
        );
    }

    return $formArguments;
}

// Save group data
function admin_user_groups_edit_commit(): void
{
    global $updated_group, $mybb, $updated_group;

    foreach (FIELDS_DATA['usergroups'] as $fieldName => $fieldDefinition) {
        if (isset($mybb->input[$fieldName])) {
            $updated_group[$fieldName] = $mybb->get_input($fieldName, MyBB::INPUT_INT);
        }
    }
}

function admin_formcontainer_output_row(array &$formArguments): array
{
    /**
     *
     * @var Form $form
     */
    global $lang, $mybb, $form, $forum_data, $form_container, $mod_data;
    global $run_module, $action_file;

    static $done = false;

    $mySupportOptions = [];

    if ($mybb->get_input(
            'module'
        ) === 'forum-management' && !empty($lang->forum) && $formArguments['title'] === $lang->forum) {
        languageLoad();

        foreach (FIELDS_DATA['moderators'] as $fieldName => $fieldDefinition) {
            if ($fieldName === 'technicalthreads') {
                continue;
            }

            $mySupportOptions[] = $form->generate_check_box(
                $fieldName,
                1,
                $lang->{'mysupport_moderators_' . $fieldName},
                ['id' => $fieldName, 'checked' => $mod_data[$fieldName]]
            );
        }
    }

    if ($mybb->get_input(
            'module'
        ) === 'forum-management' && !empty($lang->misc_options) && $formArguments['title'] === $lang->misc_options) {
        languageLoad();

        foreach (FIELDS_DATA['forums'] as $fieldName => $fieldDefinition) {
            if ($fieldName === 'technicalthreads') {
                continue;
            }

            $mySupportOptions[] = match ($fieldDefinition['formType'] ?? '') {
                'textarea' => $lang->{'mysupport_forums_' . $fieldName} . '<br />' . $form->generate_text_area(
                        $fieldName,
                        $forum_data[$fieldName]
                    ),
                default => $form->generate_check_box(
                    $fieldName,
                    1,
                    $lang->{'mysupport_forums_' . $fieldName},
                    ['id' => $fieldName, 'checked' => $forum_data[$fieldName]]
                ),
            };
        }
    }

    if ($mySupportOptions) {
        $form_container->output_row(
            $lang->mysupport,
            '',
            '<div class="forum_settings_bit">' . implode(
                '</div><div class="forum_settings_bit">',
                $mySupportOptions
            ) . '</div>'
        );
    }

    return $formArguments;
}

// Save forum data
function admin_forum_management_edit_commit(): void
{
    global $mybb, $db, $fid;

    $updateData = [];

    foreach (FIELDS_DATA['forums'] as $fieldName => $fieldDefinition) {
        if (isset($mybb->input[$fieldName])) {
            $updateData[$fieldName] = match ($fieldDefinition['type']) {
                'TEXT' => $db->escape_string($mybb->get_input($fieldName)),
                default => $mybb->get_input($fieldName, MyBB::INPUT_INT),
            };
        }
    }

    $db->update_query('forums', $updateData, "fid='{$fid}'");

    $mybb->cache->update_forums();
}

// Save forum data
function admin_forum_management_editmod_commit(): void
{
    global $mybb, $update_array;

    foreach (FIELDS_DATA['moderators'] as $fieldName => $fieldDefinition) {
        if (isset($mybb->input[$fieldName])) {
            $update_array[$fieldName] = $mybb->get_input($fieldName, MyBB::INPUT_INT);
        }
    }
}

function admin_tools_recount_rebuild_output_list(): void
{
    global $lang;
    global $form_container, $form;

    languageLoad();

    $form_container->output_cell(
        "<label>{$lang->mySupportRebuildAssignmentRows}</label><div class=\"description\">{$lang->mySupportRebuildAssignmentRowsDescription}</div>"
    );

    $form_container->output_cell(
        $form->generate_numeric_field(
            'mysupport_rebuild_assignment_rows',
            50,
            ['style' => 'width: 150px;', 'min' => 0]
        )
    );

    $form_container->output_cell(
        $form->generate_submit_button($lang->go, ['name' => 'do_mysupport_rebuild_assignment_rows'])
    );

    $form_container->construct_row();

    $form_container->output_cell(
        "<label>{$lang->mySupportRebuildAssignmentCounters}</label><div class=\"description\">{$lang->mySupportRebuildAssignmentCountersDescription}</div>"
    );

    $form_container->output_cell(
        $form->generate_numeric_field(
            'mysupport_recount_assignment_counters',
            50,
            ['style' => 'width: 150px;', 'min' => 0]
        )
    );

    $form_container->output_cell(
        $form->generate_submit_button($lang->go, ['name' => 'do_mysupport_recount_assignment_counters'])
    );

    $form_container->construct_row();

    $form_container->output_cell(
        "<label>{$lang->mySupportRebuildTechnicalRows}</label><div class=\"description\">{$lang->mySupportRebuildTechnicalRowsDescription}</div>"
    );

    $form_container->output_cell(
        $form->generate_numeric_field(
            'mysupport_rebuild_technical_rows',
            50,
            ['style' => 'width: 150px;', 'min' => 0]
        )
    );

    $form_container->output_cell(
        $form->generate_submit_button($lang->go, ['name' => 'do_mysupport_rebuild_technical_rows'])
    );

    $form_container->construct_row();

    $form_container->output_cell(
        "<label>{$lang->mySupportRebuildTechnicalCounters}</label><div class=\"description\">{$lang->mySupportRebuildTechnicalCountersDescription}</div>"
    );

    $form_container->output_cell(
        $form->generate_numeric_field(
            'mysupport_recount_technical_counters',
            50,
            ['style' => 'width: 150px;', 'min' => 0]
        )
    );

    $form_container->output_cell(
        $form->generate_submit_button($lang->go, ['name' => 'do_mysupport_recount_technical_counters'])
    );

    $form_container->construct_row();
}

function admin_tools_do_recount_rebuild(): void
{
    global $mybb;

    if (isset($mybb->input['do_mysupport_rebuild_assignment_rows'])) {
        if ($mybb->get_input('page', MyBB::INPUT_INT) === 1) {
            log_admin_action('rebuild_assignments');
        }

        $perPage = $mybb->get_input('mysupport_rebuild_assignment_rows', MyBB::INPUT_INT);

        if (!$perPage || $perPage <= 0) {
            $mybb->input['mysupport_rebuild_assignment_rows'] = 50;
        }

        recountRebuildAssignmentRows();
    }

    if (isset($mybb->input['do_mysupport_recount_assignment_counters'])) {
        if ($mybb->get_input('page', MyBB::INPUT_INT) === 1) {
            log_admin_action('recount_assignments');
        }

        $perPage = $mybb->get_input('mysupport_recount_assignment_counters', MyBB::INPUT_INT);

        if (!$perPage || $perPage <= 0) {
            $mybb->input['mysupport_recount_assignment_counters'] = 50;
        }

        recountRebuildAssignmentCounters();
    }

    if (isset($mybb->input['do_mysupport_rebuild_technical_rows'])) {
        if ($mybb->get_input('page', MyBB::INPUT_INT) === 1) {
            log_admin_action('rebuild_assignments');
        }

        $perPage = $mybb->get_input('mysupport_rebuild_technical_rows', MyBB::INPUT_INT);

        if (!$perPage || $perPage <= 0) {
            $mybb->input['mysupport_rebuild_technical_rows'] = 50;
        }

        recountRebuildTechnicalRows();
    }

    if (isset($mybb->input['do_mysupport_recount_technical_counters'])) {
        if ($mybb->get_input('page', MyBB::INPUT_INT) === 1) {
            log_admin_action('recount_assignments');
        }

        $perPage = $mybb->get_input('mysupport_recount_technical_counters', MyBB::INPUT_INT);

        if (!$perPage || $perPage <= 0) {
            $mybb->input['mysupport_recount_technical_counters'] = 50;
        }

        recountRebuildTechnicalCounters();
    }
}
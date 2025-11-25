<?php

/**
 * MySupport 1.8.0 - Admin File
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

use function MySupport\Core\deniedReasonDelete;
use function MySupport\Core\deniedReasonGet;
use function MySupport\Core\deniedReasonInsert;
use function MySupport\Core\languageLoad;
use function MySupport\Core\priorityDelete;
use function MySupport\Core\priorityGet;
use function MySupport\Core\priorityInsert;
use function MySupport\Core\priorityUpdate;
use function MySupport\Core\threadsGet;
use function MySupport\Core\threadUpdate;
use function MySupport\Core\updateCache;
use function MySupport\Core\usersGet;
use function MySupport\Core\userUpdate;

use const MySupport\Core\CACHE_TYPE_DENIED_REASONS;
use const MySupport\Core\CACHE_TYPE_PRIORITIES;
use const MySupport\Core\DATABASE_ROW_TYPE_PRIORITY;

if (!defined('IN_MYBB')) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

global $cache, $page, $lang, $mybb, $db;
global $modules_dir_backup, $run_module_backup, $action_file_backup;

$modules_dir = $modules_dir_backup;

$run_module = $run_module_backup;

$action_file = $action_file_backup;

languageLoad();

$page->add_breadcrumb_item($lang->mysupport, 'index.php?module=config-mysupport');

if ($mybb->get_input('action') === 'do_priorities') {
    if (!verify_post_check($mybb->get_input('my_post_key'))) {
        flash_message($lang->invalid_post_verify_key2, 'error');
        admin_redirect('index.php?module=config-mysupport&action=priorities');
    }

    if ($mybb->get_input('do') === 'do_add') {
        if (!strlen(trim($mybb->get_input('name')))) {
            flash_message($lang->priority_no_name, 'error');
            admin_redirect('index.php?module=config-mysupport&action=priorities');
        }
        $insert = [
            'name' => $db->escape_string($mybb->get_input('name')),
            'description' => $db->escape_string($mybb->get_input('description')),
            'extra' => $db->escape_string(str_replace('#', '', $mybb->get_input('style'))),
            'type' => DATABASE_ROW_TYPE_PRIORITY,
            'allowed_groups' => $db->escape_string(
                implode(
                    ',',
                    $mybb->get_input('allowed_groups', MyBB::INPUT_ARRAY)
                )
            ),
            'allowed_forums' => $db->escape_string(
                implode(
                    ',',
                    $mybb->get_input('allowed_forums', MyBB::INPUT_ARRAY)
                )
            ),
        ];

        priorityInsert($insert);

        updateCache(CACHE_TYPE_PRIORITIES);

        flash_message($lang->priority_added, 'success');
        admin_redirect('index.php?module=config-mysupport&action=priorities');
    } elseif ($mybb->get_input('do') === 'do_edit') {
        $pid = $mybb->get_input('pid', MyBB::INPUT_INT);
        if (!strlen(trim($mybb->get_input('name')))) {
            flash_message($lang->priority_no_name, 'error');
            admin_redirect("index.php?module=config-mysupport&action=priorities&do=edit&pid={$pid}");
        }
        $update = [
            'name' => $db->escape_string($mybb->get_input('name')),
            'description' => $db->escape_string($mybb->get_input('description')),
            'extra' => $db->escape_string(str_replace('#', '', $mybb->get_input('style'))),
            'allowed_groups' => $db->escape_string(
                implode(
                    ',',
                    $mybb->get_input('allowed_groups', MyBB::INPUT_ARRAY)
                )
            ),
            'allowed_forums' => $db->escape_string(
                implode(
                    ',',
                    $mybb->get_input('allowed_forums', MyBB::INPUT_ARRAY)
                )
            ),
        ];

        priorityUpdate($update, $pid);

        updateCache(CACHE_TYPE_PRIORITIES);

        flash_message($lang->priority_edited, 'success');
        admin_redirect('index.php?module=config-mysupport&action=priorities');
    } elseif ($mybb->get_input('do') === 'do_delete') {
        if (isset($mybb->input['no'])) {
            admin_redirect('index.php?module=config-mysupport&action=priorities');
        } else {
            $pid = $mybb->get_input('pid', MyBB::INPUT_INT);
            $update = [
                'priority' => 0
            ];

            foreach (threadsGet(["priority='{$pid}'"], ['tid']) as $threadID => $threadData) {
                threadUpdate($update, $threadID);
            }

            priorityDelete($pid);

            updateCache(CACHE_TYPE_PRIORITIES);

            flash_message($lang->priority_deleted, 'success');
            admin_redirect('index.php?module=config-mysupport&action=priorities');
        }
    }
} elseif ($mybb->get_input('action') === 'do_support_denial') {
    if (!verify_post_check($mybb->get_input('my_post_key'))) {
        flash_message($lang->invalid_post_verify_key2, 'error');
        admin_redirect('index.php?module=config-mysupport&action=support_denial');
    }

    if ($mybb->get_input('do') === 'do_add') {
        if (!strlen(trim($mybb->get_input('name')))) {
            flash_message($lang->support_denial_reason_no_name, 'error');
            admin_redirect('index.php?module=config-mysupport&action=support_denial');
        }
        if (!strlen(trim($mybb->get_input('description')))) {
            flash_message($lang->support_denial_reason_no_description, 'error');
            admin_redirect('index.php?module=config-mysupport&action=support_denial');
        }
        $insert = [
            'name' => $db->escape_string($mybb->get_input('name')),
            'description' => $db->escape_string($mybb->get_input('description')),
        ];

        deniedReasonInsert($insert);

        updateCache(CACHE_TYPE_DENIED_REASONS);

        flash_message($lang->support_denial_reason_added, 'success');
        admin_redirect('index.php?module=config-mysupport&action=support_denial');
    } elseif ($mybb->get_input('do') === 'do_edit') {
        $drid = $mybb->get_input('drid', MyBB::INPUT_INT);
        if (!strlen(trim($mybb->get_input('name')))) {
            flash_message($lang->support_denial_reason_no_name, 'error');
            admin_redirect("index.php?module=config-mysupport&action=support_denial&do=edit&drid={$drid}");
        }
        if (!strlen(trim($mybb->get_input('description')))) {
            flash_message($lang->support_denial_reason_no_description, 'error');
            admin_redirect("index.php?module=config-mysupport&action=support_denial&do=edit&drid={$drid}");
        }
        $update = [
            'name' => $db->escape_string($mybb->get_input('name')),
            'description' => $db->escape_string($mybb->get_input('description'))
        ];

        deniedReasonUpdate($update, $drid);

        updateCache(CACHE_TYPE_DENIED_REASONS);

        flash_message($lang->support_denial_reason_edited, 'success');
        admin_redirect('index.php?module=config-mysupport&action=support_denial');
    } elseif ($mybb->get_input('do') === 'do_delete') {
        if (isset($mybb->input['no'])) {
            admin_redirect('index.php?module=config-mysupport&action=support_denial');
        } else {
            $drid = $mybb->get_input('drid', MyBB::INPUT_INT);
            $update = [
                'deniedsupportreason' => 0
            ];

            foreach (usersGet(["deniedsupportreason='{$drid}'"], ['uid']) as $userID => $userData) {
                userUpdate($update, $userID);
            }

            deniedReasonDelete($drid);

            updateCache(CACHE_TYPE_DENIED_REASONS);

            flash_message($lang->support_denial_reason_deleted, 'success');
            admin_redirect('index.php?module=config-mysupport&action=support_denial');
        }
    }
} elseif ($mybb->get_input('action') === 'support_denial') {
    $page->add_breadcrumb_item(
        $lang->mySupportDenialReasonsBreadcrumb,
        'index.php?module=config-mysupport&amp;action=support_denial'
    );

    if ($mybb->get_input('do') === 'edit') {
        $page->output_header($lang->mysupport);

        generate_mysupport_tabs('support_denial');

        $table = new Table();

        $drid = $mybb->get_input('drid', MyBB::INPUT_INT);
        $deniedreason = deniedReasonGet(["mid='{$drid}'"], ['name', 'description'], ['limit' => 1]);

        if (!$deniedreason) {
            flash_message($lang->support_denial_reason_invalid, 'error');
            admin_redirect('index.php?module=config-mysupport&action=support_denial');

            exit;
        }
        $form = new Form('index.php?module=config-mysupport&amp;action=do_support_denial', 'post');
        $form_container = new FormContainer($lang->support_denial_reason_edit);

        $edit_support_denial_reason_name = $form->generate_text_box(
            'name',
            htmlspecialchars_uni($deniedreason['name'])
        );
        $form_container->output_row($lang->mysupport_name . ' <em>*</em>', '', $edit_support_denial_reason_name);

        $edit_support_denial_reason_description = $form->generate_text_area(
            'description',
            htmlspecialchars_uni($deniedreason['description'])
        );
        $form_container->output_row(
            $lang->mysupport_description . ' <em>*</em>',
            $lang->support_denial_reason_description_description,
            $edit_support_denial_reason_description
        );

        echo $form->generate_hidden_field('do', 'do_edit');
        echo $form->generate_hidden_field('drid', $mybb->get_input('drid', MyBB::INPUT_INT));

        $form_container->end();

        $buttons[] = $form->generate_submit_button($lang->mysupport_edit_support_denial_reason_submit);
        $form->output_submit_wrapper($buttons);
        $form->end();
    } elseif ($mybb->get_input('do') === 'delete') {
        $drid = $mybb->get_input('drid', MyBB::INPUT_INT);

        if (!deniedReasonGet(["mid='{$drid}'"], queryOptions: ['limit' => 1])) {
            flash_message($lang->support_denial_reason_invalid, 'error');
            admin_redirect('index.php?module=config-mysupport&action=support_denial');
        }

        $support_denial_reason_count = (int)(usersGet(
            ["deniedsupportreason='{$drid}'"],
            ['COUNT(uid) AS support_denial_reason_count']
        )['support_denial_reason_count'] ?? 0);

        if ($support_denial_reason_count > 0) {
            $lang->support_denial_reason_delete_confirm .= ' ' . $lang->sprintf(
                    $lang->support_denial_reason_delete_confirm_count,
                    $support_denial_reason_count
                );
        }
        $page->output_confirm_action(
            "index.php?module=config-mysupport&amp;action=do_support_denial&amp;do=do_delete&amp;drid={$drid}",
            $lang->support_denial_reason_delete_confirm
        );
    } else {
        $page->output_header($lang->mysupport);

        generate_mysupport_tabs('support_denial');

        $table = new Table();

        $deniedReasonObjects = deniedReasonGet(queryFields: ['name', 'description']);

        if ($deniedReasonObjects) {
            $table->construct_header($lang->mysupport_name);
            $table->construct_header($lang->mysupport_description);
            $table->construct_header($lang->controls, ['colspan' => 2, 'class' => 'align_center']);

            foreach ($deniedReasonObjects as $deniedreason) {
                $table->construct_cell($deniedreason['name'], ['width' => '20%']);
                $table->construct_cell($deniedreason['description'], ['width' => '50%']);
                $table->construct_cell(
                    "<a href=\"index.php?module=config-mysupport&amp;action=support_denial&amp;do=edit&amp;drid={$deniedreason['mid']}\">{$lang->edit}</a>",
                    ['class' => 'align_center', 'width' => '10%']
                );
                $table->construct_cell(
                    "<a href=\"index.php?module=config-mysupport&amp;action=support_denial&amp;do=delete&amp;drid={$deniedreason['mid']}\">{$lang->delete}</a>",
                    ['class' => 'align_center', 'width' => '10%']
                );
                $table->construct_row();
            }

            $table->output($lang->support_denial_reason_current);
        }

        $form = new Form('index.php?module=config-mysupport&amp;action=do_support_denial', 'post');
        $form_container = new FormContainer($lang->support_denial_reason_add);

        $add_support_denial_reason_name = $form->generate_text_box('name');
        $form_container->output_row($lang->mysupport_name . ' <em>*</em>', '', $add_support_denial_reason_name);

        $add_support_denial_reason_description = $form->generate_text_area('description');
        $form_container->output_row(
            $lang->mysupport_description . ' <em>*</em>',
            $lang->support_denial_reason_description_description,
            $add_support_denial_reason_description
        );

        echo $form->generate_hidden_field('do', 'do_add');

        $form_container->end();

        $buttons[] = $form->generate_submit_button($lang->mysupport_add_support_denial_reason_submit);
        $form->output_submit_wrapper($buttons);
        $form->end();
    }

    $page->output_footer();
} else {
    $page->add_breadcrumb_item(
        $lang->mySupportPrioritiesBreadcrumb,
        'index.php?module=config-mysupport&amp;action=priorities'
    );

    if ($mybb->get_input('do') === 'edit') {
        $page->output_header($lang->mysupport);

        generate_mysupport_tabs('priorities');

        $table = new Table();

        $pid = $mybb->get_input('pid', MyBB::INPUT_INT);
        $priority = priorityGet(
            ["mid='{$pid}'"],
            ['name', 'description', 'extra', 'allowed_groups', 'allowed_forums'],
            ['limit' => 1]
        );

        if (!$priority) {
            flash_message($lang->priority_invalid, 'error');
            admin_redirect('index.php?module=config-mysupport&action=priorities');

            exit;
        }

        $form = new Form('index.php?module=config-mysupport&amp;action=do_priorities', 'post');
        $form_container = new FormContainer($lang->priorities_edit);

        $edit_priority_name = $form->generate_text_box('name', htmlspecialchars_uni($priority['name']));
        $form_container->output_row($lang->mysupport_name . ' <em>*</em>', '', $edit_priority_name);

        $edit_priority_description = $form->generate_text_box(
            'description',
            htmlspecialchars_uni($priority['description'])
        );
        $form_container->output_row($lang->mysupport_description, '', $edit_priority_description);

        $edit_priority_style = $form->generate_text_box('style', htmlspecialchars_uni($priority['extra']));
        $form_container->output_row($lang->priority_style, $lang->priority_style_description, $edit_priority_style);

        $edit_priority_groups = $form->generate_group_select(
            'allowed_groups[]',
            explode(',', $priority['allowed_groups'] ?? ''),
            ['multiple' => true]
        );
        $form_container->output_row($lang->priority_groups, $lang->priority_groups_description, $edit_priority_groups);

        $edit_priority_forums = $form->generate_forum_select(
            'allowed_forums[]',
            explode(',', $priority['allowed_forums'] ?? ''),
            ['multiple' => true]
        );
        $form_container->output_row($lang->priority_forums, $lang->priority_forums_description, $edit_priority_forums);

        echo $form->generate_hidden_field('do', 'do_edit');
        echo $form->generate_hidden_field('pid', $mybb->get_input('pid', MyBB::INPUT_INT));

        $form_container->end();

        $buttons[] = $form->generate_submit_button($lang->mysupport_edit_priority_submit);
        $form->output_submit_wrapper($buttons);
        $form->end();
    } elseif ($mybb->get_input('do') === 'delete') {
        $pid = $mybb->get_input('pid', MyBB::INPUT_INT);

        if (!priorityGet(["mid='{$pid}'"], queryOptions: ['limit' => 1])) {
            flash_message($lang->priority_invalid, 'error');
            admin_redirect('index.php?module=config-mysupport&action=priorities');
        }

        $priority_count = (int)(threadsGet(
            [
                "priority='{$pid}'",
            ],
            ['COUNT(tid) AS priority_count'],
            ['limit' => 1]
        )['priority_count'] ?? 0);

        if ($priority_count > 0) {
            $priority_delete_confirm_count = ' ' . $lang->sprintf(
                    $lang->priority_delete_confirm_count,
                    $priority_count
                );
        } else {
            $priority_delete_confirm_count = '';
        }
        $page->output_confirm_action(
            "index.php?module=config-mysupport&amp;action=do_priorities&amp;do=do_delete&amp;pid={$pid}",
            $lang->priority_delete_confirm . $priority_delete_confirm_count
        );
    } else {
        $page->output_header($lang->mysupport);

        generate_mysupport_tabs('priorities');

        $table = new Table();

        $priorityObjects = priorityGet(queryFields: ['name', 'description', 'extra', 'type']);

        if ($priorityObjects) {
            $table->construct_header($lang->mysupport_name);
            $table->construct_header($lang->mysupport_description);
            $table->construct_header($lang->controls, ['colspan' => 2, 'class' => 'align_center']);

            foreach ($priorityObjects as $priority) {
                if (!empty($priority['extra'])) {
                    $style = "background: #{$priority['extra']}";
                } else {
                    $style = '';
                }
                $table->construct_cell($priority['name'], ['width' => '20%', 'style' => $style]);
                $table->construct_cell($priority['description'], ['width' => '30%', 'style' => $style]);
                $table->construct_cell(
                    "<a href=\"index.php?module=config-mysupport&amp;action=priorities&amp;do=edit&amp;pid={$priority['mid']}\">{$lang->edit}</a>",
                    ['class' => 'align_center', 'width' => '15%']
                );
                $table->construct_cell(
                    "<a href=\"index.php?module=config-mysupport&amp;action=priorities&amp;do=delete&amp;pid={$priority['mid']}\">{$lang->delete}</a>",
                    ['class' => 'align_center', 'width' => '15%']
                );
                $table->construct_row();
            }

            $table->output($lang->priorities_current);
        }

        $form = new Form('index.php?module=config-mysupport&amp;action=do_priorities', 'post');
        $form_container = new FormContainer($lang->priorities_add);

        $add_priority_name = $form->generate_text_box('name');
        $form_container->output_row($lang->mysupport_name . ' <em>*</em>', '', $add_priority_name);

        $add_priority_description = $form->generate_text_box('description');
        $form_container->output_row($lang->mysupport_description, '', $add_priority_description);

        $add_priority_style = $form->generate_text_box('style');
        $form_container->output_row($lang->priority_style, $lang->priority_style_description, $add_priority_style);

        echo $form->generate_hidden_field('do', 'do_add');

        $form_container->end();

        $buttons[] = $form->generate_submit_button($lang->mysupport_add_priority_submit);
        $form->output_submit_wrapper($buttons);
        $form->end();
    }

    $page->output_footer();
}

/**
 * Output the MySupport tabs; save repeating code for each section
 *
 * @param string $selected The tab to show as the current tab.
 **/
function generate_mysupport_tabs(string $selected): void
{
    global $lang, $page;

    $sub_tabs = [];

    $sub_tabs['priorities'] = [
        'title' => $lang->mySupportPrioritiesTab,
        'link' => 'index.php?module=config-mysupport&amp;action=priorities',
        'description' => $lang->mySupportPrioritiesTabDescription
    ];

    $sub_tabs['support_denial'] = [
        'title' => $lang->mySupportDenialReasonsTab,
        'link' => 'index.php?module=config-mysupport&amp;action=support_denial',
        'description' => $lang->mySupportDenialReasonsTabDescription
    ];

    $sub_tabs['categories'] = [
        'title' => $lang->mySupportCategoriesTab,
        'link' => 'index.php?module=config-mysupport&amp;action=categories',
        'description' => $lang->mySupportCategoriesTabDescription
    ];

    $page->output_nav_tabs($sub_tabs, $selected);
}

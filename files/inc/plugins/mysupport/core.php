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

namespace MySupport\Core;

use Moderation;
use MybbStuff_MyAlerts_AlertManager;
use MybbStuff_MyAlerts_AlertTypeManager;
use MybbStuff_MyAlerts_Entity_Alert;
use PMDataHandler;

const CACHE_TYPE_ALL = 0;

const CACHE_TYPE_VERSION = 1;

const CACHE_TYPE_PRIORITIES = 2;

const CACHE_TYPE_DENIED_REASONS = 3;

const DATABASE_ROW_TYPE_PRIORITY = 1;

const REVOKE_DENIED_SUPPORT = -1;

function loadLanguage(): void
{
    global $lang;

    if (isset($lang->mysupport)) {
        return;
    }

    if (defined('IN_ADMINCP')) {
        $lang->load('config_mysupport');
    } else {
        $lang->load('mysupport');
    }
}

function addHooks(string $namespace): void
{
    global $plugins;

    $namespaceLowercase = strtolower($namespace);

    $definedUserFunctions = get_defined_functions()['user'];

    foreach ($definedUserFunctions as $callable) {
        $namespaceWithPrefixLength = strlen($namespaceLowercase) + 1;

        if (substr($callable, 0, $namespaceWithPrefixLength) == $namespaceLowercase . '\\') {
            $hookName = substr_replace($callable, '', 0, $namespaceWithPrefixLength);

            $priority = substr($callable, -2);

            if (is_numeric(substr($hookName, -2))) {
                $hookName = substr($hookName, 0, -2);
            } else {
                $priority = 10;
            }

            $plugins->add_hook($hookName, $callable, $priority);
        }
    }
}

function get_setting(string $setting_key = '')
{
    global $mybb;

    return $mybb->settings['mysupport_' . $setting_key] ?? false;
}

function send_alert(int $tid, int $uid, int $author = 0): void
{
    global $lang, $mybb, $alertType, $db;

    loadLanguage();

    if (!($mybb->settings['mysupport_notifications'] && class_exists('MybbStuff_MyAlerts_AlertTypeManager'))) {
        return;
    }

    $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('mysupport_thread');

    if (!$alertType) {
        return;
    }

    $query = $db->simple_select(
        'alerts',
        'id',
        "object_id='{$tid}' AND uid='{$uid}' AND unread=1 AND alert_type_id='{$alertType->getId()}'"
    );

    if ($db->fetch_field($query, 'id')) {
        return;
    }

    if ($alertType->getEnabled()) {
        $alert = new MybbStuff_MyAlerts_Entity_Alert();

        $alert->setType($alertType)->setUserId($uid)->setExtraDetails([
            'type' => $alertType->getId()
        ]);

        if ($tid) {
            $alert->setObjectId($tid);
        }

        if ($author) {
            $alert->setFromUserId($author);
        }

        MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
    }
}

function updateCache(int $cacheType = CACHE_TYPE_ALL): void
{
    global $db, $cache;

    $currentCachedData = $cache->read('mysupport');

    $newCachedData = [
        'version' => $currentCachedData['version'] ?? '',
        'versionCode' => $currentCachedData['versionCode'] ?? '',
        'priorities' => $currentCachedData['priorities'] ?? [],
        'deniedReasons' => $currentCachedData['deniedReasons'] ?? [],
    ];

    switch ($cacheType) {
        case CACHE_TYPE_ALL:
        case CACHE_TYPE_VERSION:
            $newCachedData['version'] = VERSION;

            $newCachedData['versionCode'] = VERSION_CODE;
        case CACHE_TYPE_ALL:
        case CACHE_TYPE_PRIORITIES:
            $priorityType = DATABASE_ROW_TYPE_PRIORITY;

            $dbQuery = $db->simple_select(
                'mysupport',
                'mid, name, description, extra',
                "type = '{$priorityType}'",
                ['order_by' => 'name']
            );

            $newCachedData['priorities'] = [];

            while ($priorityData = $db->fetch_array($dbQuery)) {
                $newCachedData['priorities'][(int)$priorityData['mid']] = [
                    'name' => $priorityData['name'],
                    'description' => $priorityData['description'],
                    'extra' => $priorityData['extra'],
                ];
            }

        case CACHE_TYPE_ALL:
        case CACHE_TYPE_DENIED_REASONS:
            $dbQuery = $db->simple_select(
                'mysupport',
                'mid, name, description',
                "type = 'deniedreason'",
                ['order_by' => 'name']
            );

            $newCachedData['deniedReasons'] = [];

            while ($deniedReasonData = $db->fetch_array($dbQuery)) {
                $newCachedData['deniedReasons'][(int)$deniedReasonData['mid']] = [
                    'name' => $deniedReasonData['name'],
                    'description' => $deniedReasonData['description'],
                ];
            }
    }

    $cache->update('mysupport', $newCachedData);
}

/**
 * Get the count of technical or assigned threads.
 *
 * @param string $type The FID we're in.
 * @param int $fid
 * @return int The number of technical or assigned threads in this forum.
 */
function _get_count(string $type, int $fid = 0): int
{
    global $mybb, $cache;

    $count = 0;

    $forums = $cache->read('forums');

    if ($type == 'technical') {
        // there's no FID given, so this is loading the total number of technical threads
        if ($fid == 0) {
            foreach ($forums as $forum => $info) {
                $count += $info['technicalthreads'];
            }
        } // we have an FID, so count the number of technical threads in this specific forum and all it's parents
        else {
            $forums_list = [];

            foreach ($forums as $forum => $info) {
                $parentlist = $info['parentlist'];
                if (strpos(',' . $parentlist . ',', ',' . $fid . ',') !== false) {
                    $forums_list[] = $forum;
                }
            }

            foreach ($forums_list as $forum) {
                $count += $forums[$forum]['technicalthreads'];
            }
        }
    } elseif ($type == 'assigned') {
        if (empty($mybb->user['assignedthreads'])) {
            return $count;
        }

        $assigned = unserialize($mybb->user['assignedthreads']);

        if (!is_array($assigned)) {
            return 0;
        }

        // there's no FID given, so this is loading the total number of assigned threads
        if ($fid == 0) {
            foreach ($assigned as $fid => $threads) {
                $count += $threads;
            }
        } // we have an FID, so count the number of assigned threads in this specific forum
        else {
            $forums_list = [];

            foreach ($forums as $forum => $info) {
                $parentlist = $info['parentlist'];
                if (strpos(',' . $parentlist . ',', ',' . $fid . ',') !== false) {
                    $forums_list[] = $forum;
                }
            }

            foreach ($forums_list as $forum) {
                $count += $assigned[$forum];
            }
        }
    }

    return $count;
}

/**
 * Generates a list of all forums that have MySupport enabled.
 *
 * @return array Array of forums that have MySupport enabled.
 **/
function enabledForums(): array
{
    global $cache;

    static $mysupport_forums = null;

    if ($mysupport_forums === null) {
        $mysupport_forums = [];

        $forums = $cache->read('forums');

        foreach ($forums as $forum) {
            // if this forum/category has MySupport enabled, add it to the array
            if (!empty($forum['mysupport'])) {
                $mysupport_forums[(int)$forum['fid']] = (int)$forum['fid'];
            } // if this forum/category hasn't got MySupport enabled...
            else {
                // ... go through the parent list...

                foreach (explode(',', $forum['parentlist']) as $parent) {
                    // ... if this parent has MySupport enabled...
                    if (!empty($forums[$parent]['mysupport'])) {
                        // ... add the original forum we're looking at to the list
                        $mysupport_forums[(int)$forum['fid']] = (int)$forum['fid'];
                        // this is for if we enable MySupport for a whole category; this will pick up all the forums inside that category and add them to the array
                    }
                }
            }
        }
    }

    return $mysupport_forums;
}

/**
 * Get the text version of the status of a thread.
 *
 * @param int $status The status of the thread.
 * @return string The text version of the status of the thread.
 **/
function _get_friendly_status(int $status = 0): string
{
    global $lang;

    $lang->load('mysupport');

    switch ($status) {
        // has it been marked as not technical?
        case 4:
            $friendlystatus = $lang->not_technical;
            break;
        // is it a technical thread?
        case 2:
            $friendlystatus = $lang->technical;
            break;
        // no, is it a solved thread?
        case 3:
        case 1:
            $friendlystatus = $lang->solved;
            break;
        // must be not solved then
        default:
            $friendlystatus = $lang->not_solved;
    }

    return $friendlystatus;
}

/**
 * Show the status of a thread.
 *
 * @param int $status The status of the thread.
 * @param int $onhold The time the thread was solved.
 * @param int $statustime The TID of the thread.
 **/
function _get_display_status(int $status, int $onhold = 0, int $statustime = 0, int $thread_author = 0): string
{
    global $mybb, $lang, $templates, $theme, $mysupport_status, $thread;

    $currentUserID = (int)$mybb->user['uid'];

    // if this user is logged in, we want to override the global setting for display with their own setting
    if ($currentUserID && $mybb->settings['mysupport_displaytypeuserchange']) {
        if ($mybb->user['mysupportdisplayastext'] == 1) {
            $mybb->settings['mysupport_displaytype'] = 'text';
        } else {
            $mybb->settings['mysupport_displaytype'] = 'image';
        }
    }

    // big check to see if either the status is to be show to everybody, only to people who can mark as solved, or to people who can mark as solved or who authored the thread
    if ($mybb->settings['mysupport_displayto'] == 'all' || ($mybb->settings['mysupport_displayto'] == 'canmas' && user_group(
                'canmarksolved'
            )) || ($mybb->settings['mysupport_displayto'] == 'canmasauthor' && (user_group(
                    'canmarksolved'
                ) || $currentUserID === $thread_author))) {
        $text = $mybb->settings['mysupport_displaytype'] == 'text';

        if ($mybb->settings['mysupport_relativetime']) {
            $date_time_technical = 0;

            $date_time = my_date('relative', $statustime);

            if (!$text) {
                $date_time = strip_tags($date_time);
            }

            $status_title = $lang->sprintf($lang->technical_time, $date_time_technical);
        } else {
            $date_time = my_date('normal', $statustime);
        }

        // if this user cannot mark a thread as technical and people who can't mark as technical can't see that a technical thread is technical, don't execute this
        // I used the word technical 4 times in that sentence didn't I? sorry about that
        if ($status == 2 && !($mybb->settings['mysupport_hidetechnical'] || ($mybb->usergroup['canseetechnotice'] || is_moderator(
                        $thread['fid'],
                        'canmarktechnical'
                    )))) {
            $status_class = $status_img = 'technical';
            $status_title = htmlspecialchars_uni($lang->sprintf($lang->technical_time, $date_time));

            if ($text) {
                $status_text = $lang->technical;
            }
        } elseif ($status == 1) {
            $status_class = $status_img = 'solved';
            $status_text = $lang->solved;
            $status_title = htmlspecialchars_uni($lang->sprintf($lang->solved_time, $date_time));

            if ($text) {
                $status_text = $lang->solved;
            }
        } else {
            $status_class = $status_img = 'notsolved';
            $status_text = $status_title = $lang->not_solved;
        }

        if ($onhold == 1) {
            $status_class = $status_img = 'onhold';
            $status_text = $lang->onhold;
            $status_title = $lang->onhold . ' - ' . $status_title;
        }

        if ($text) {
            $mysupport_status = eval($templates->render('mysupport_status_text'));
        } else {
            $mysupport_status = eval($templates->render('mysupport_status_image'));
        }

        return $mysupport_status;
    }

    return '';
}

function get_usergroup_permissions(int $uid, array $user = []): array
{
    if (empty($user)) {
        $user = get_user($uid);
    }

    if (empty($user['uid'])) {
        $usergroup = [];
    } else {
        $usergroup = usergroup_permissions(
            !$user['additionalgroups'] ? $user['usergroup'] : $user['usergroup'] . ',' . $user['additionalgroups']
        );

        if ($user['displaygroup']) {
            $mydisplaygroup = usergroup_displaygroup($user['displaygroup']);

            if (is_array($mydisplaygroup)) {
                $usergroup = array_merge($usergroup, $mydisplaygroup);
            }
        }
    }

    return $usergroup;
}

/**
 * Check if a points system is enabled for points system integration.
 *
 * @return bool Whether or not your chosen points system is enabled.
 **/
function _points_system_enabled(): bool
{
    global $mybb, $cache;

    $plugins = $cache->read('plugins');

    if ($mybb->settings['mysupport_pointssystem'] != 'none') {
        if ($mybb->settings['mysupport_pointssystem'] == 'other') {
            $mybb->settings['mysupportpointssystem'] = $mybb->settings['mysupportpointssystemname'];
        }

        return in_array($mybb->settings['mysupport_pointssystem'], $plugins['active']);
    }

    return false;
}

/**
 * Change the status of a thread.
 *
 * @param array $thread_info Information about the thread.
 * @param int $status The new status.
 * @param bool $multiple If this is changing the status of multiple threads.
 **/
function _change_status(array $thread_info, int $status = 0, bool $multiple = false): void
{
    global $mybb, $db, $lang;

    if ($status == 3) {
        // if it's 3, we're solving and closing, but we'll just check for regular solving in the list of things to log
        // saves needing to have a 3, for the solving and closing option, in the setting of what to log
        // then below it'll check if 1 is in the list of things to log; 1 is normal solving, so if that's in the list, it'll log this too
        $log_status = 1;
    } else {
        $log_status = $status;
    }

    if ($multiple) {
        $tid = -1;
        $old_status = -1;
    } else {
        $tid = intval($thread_info['tid']);
        $old_status = intval($thread_info['status']);
    }

    $move_fid = '';
    /*
    $forums = $cache->read("forums");
    foreach($forums as $forum)
    {
        if(!empty($forum['mysupportmove']) && $forum['mysupportmove'] != 0)
        {
            $move_fid = intval($forum['fid']);
            break;
        }
    }
    */
    // are we marking it as solved and is it being moved?
    if (!empty($move_fid) && ($status == 1 || $status == 3)) {
        if ($mybb->settings['mysupport_moveredirect'] == 'none') {
            $move_type = 'move';
            $redirect_time = 0;
        } else {
            $move_type = 'redirect';
            if ($mybb->settings['mysupport_moveredirect'] == 'forever') {
                $redirect_time = 0;
            } else {
                $redirect_time = intval($mybb->settings['mysupport_moveredirect']);
            }
        }
        if ($multiple) {
            $move_tids = $thread_info;
        } else {
            $move_tids = [$thread_info['tid']];
        }
        require_once MYBB_ROOT . 'inc/class_moderation.php';
        $moderation = new Moderation();
        // the reason it loops through using move_thread is because move_threads doesn't give the option for a redirect
        // if it's not a multiple thread it will just loop through once as there'd only be one value in the array
        foreach ($move_tids as $move_tid) {
            $moderation->move_thread($move_tid, $move_fid, $move_type, $redirect_time);
        }
    }

    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));
        $where_sql = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $where_sql = "tid = '" . $tid . "'";
    }
    $assign_users = [];

    // we need to build an array of users who have been assigned threads before the assignment is removed
    if ($status == 1 || $status == 3) {
        $query = $db->simple_select('threads', 'DISTINCT assign', $where_sql . " AND assign != '0'");
        while ($user = $db->fetch_field($query, 'assign')) {
            $assign_users[] = $user;
        }
    }

    $currentUserID = (int)$mybb->user['uid'];

    if ($status == 3 || ($status == 1 && $mybb->settings['mysupport_closewhensolved'] == 'always')) {
        // the bit after || here is for if we're marking as solved via marking a post as the best answer, it will close if it's set to always close
        // the incoming status would be 1, but we need to close it if necessary
        $status_update = [
            'closed' => 1,
            'status' => 1,
            'statusuid' => $currentUserID,
            'statustime' => TIME_NOW,
            'assign' => 0,
            'assignuid' => 0,
            'priority' => 0,
            'closedbymysupport' => 1,
            'onhold' => 0
        ];
    } elseif ($status == 0) {
        // if we're marking it as unsolved, a post may have been marked as the best answer when it was originally solved, best remove it, as well as rest everything else
        $status_update = [
            'status' => 0,
            'statusuid' => 0,
            'statustime' => 0,
            'bestanswer' => 0
        ];
    } elseif ($status == 4) {
        /** if it's 4, it's because it was marked as being not technical after being marked technical
         ** basically put back to the original status of not solved (0)
         ** however it needs to be 4 so we can differentiate between this action (technical => not technical), and a user marking it as not solved
         ** because both of these options eventually set it back to 0
         ** so the mod log entry will say the correct action as the status was 4 and it used that
         ** now that the log has been inserted we can set it to 0 again for the thread update query so it's marked as unsolved **/
        $status_update = [
            'status' => 0,
            'statusuid' => 0,
            'statustime' => 0
        ];
    } elseif ($status == 2) {
        $status_update = [
            'status' => 2,
            'statusuid' => $currentUserID,
            'statustime' => TIME_NOW
        ];
    } // if not, it's being marked as solved
    else {
        $status_update = [
            'status' => 1,
            'statusuid' => $currentUserID,
            'statustime' => TIME_NOW,
            'assign' => 0,
            'assignuid' => 0,
            'priority' => 0,
            'onhold' => 0
        ];
    }

    $db->update_query('threads', $status_update, $where_sql);

    // if the thread is being marked as technical, being marked as something else after being marked technical, or we're changing the status of multiple threads, recount the number of technical threads
    if ($status == 2 || $old_status == 2 || $multiple) {
        recount_technical_threads();
    }
    // if the thread is being marked as solved, recount the number of assigned threads for any users who were assigned threads that are now being marked as solved
    if ($status == 1 || $status == 3) {
        foreach ($assign_users as $user) {
            recount_assigned_threads($user);
        }
    }
    if ($status == 0) {
        // if we're marking a thread(s) as unsolved, re-open any threads that were closed when they were marked as solved, but not any that were closed by denying support
        $update = [
            'closed' => 0,
            'closedbymysupport' => 0
        ];
        $db->update_query('threads', $update, $where_sql . " AND closed = '1' AND closedbymysupport = '1'");
    }

    // get the friendly version of the status for the redirect message and mod log
    $friendly_old_status = "'" . _get_friendly_status($old_status) . "'";
    $friendly_new_status = "'" . _get_friendly_status($status) . "'";

    if ($multiple) {
        mod_log_action(
            $log_status,
            $lang->sprintf($lang->status_change_mod_log_multi, count($thread_info), $friendly_new_status)
        );
        redirect_message(
            $lang->sprintf(
                $lang->status_change_success_multi,
                count($thread_info),
                htmlspecialchars_uni($friendly_new_status)
            )
        );
    } else {
        mod_log_action($log_status, $lang->sprintf($lang->status_change_mod_log, $friendly_new_status));
        redirect_message(
            $lang->sprintf(
                $lang->status_change_success,
                htmlspecialchars_uni($friendly_old_status),
                htmlspecialchars_uni($friendly_new_status)
            )
        );
    }
}

// loads the dropdown menu for inline thread moderation
function inline_thread_moderation(): void
{
    global $mybb, $cache, $lang, $templates, $foruminfo, $mysupport_inline_thread_moderation;

    $lang->load('mysupport');

    $mysupport_solved = $mysupport_not_solved = $mysupport_solved_and_close = $mysupport_technical = $mysupport_not_technical = '';
    if (is_moderator($foruminfo['fid'], 'canmarksolved')) {
        $mysupport_solved = "<option value=\"mysupport_status_1\">-- " . $lang->solved . '</option>';
        $mysupport_not_solved = "<option value=\"mysupport_status_0\">-- " . $lang->not_solved . '</option>';
        if ($mybb->settings['mysupport_closewhensolved'] != 'never') {
            $mysupport_solved_and_close = "<option value=\"mysupport_status_3\">-- " . $lang->solved_close . '</option>';
        }
    }
    if ($mybb->settings['mysupport_enabletechnical']) {
        if (is_moderator($foruminfo['fid'], 'canmarktechnical')) {
            $mysupport_technical = "<option value=\"mysupport_status_2\">-- " . $lang->technical . '</option>';
            $mysupport_not_technical = "<option value=\"mysupport_status_4\">-- " . $lang->not_technical . '</option>';
        }
    }

    $mysupport_onhold = $mysupport_offhold = '';
    if ($mybb->settings['mysupport_enableonhold']) {
        if (is_moderator($foruminfo['fid'], 'canmarksolved')) {
            $mysupport_onhold = "<option value=\"mysupport_onhold_1\">-- " . $lang->hold_status_onhold . '</option>';
            $mysupport_offhold = "<option value=\"mysupport_onhold_0\">-- " . $lang->hold_status_offhold . '</option>';
        }
    }

    if ($mybb->settings['mysupport_enableassign']) {
        $mysupport_assign = '';
        $assign_users = get_assign_users();
        // only continue if there are one or more users that can be assigned threads
        $mysupport_assign .= "<option value=\"mysupport_assign_find\">-- <i>{$lang->my_support_inline_find}</i></option>\n";
        if (!empty($assign_users)) {
            foreach ($assign_users as $assign_userid => $assign_username) {
                $mysupport_assign .= "<option value=\"mysupport_assign_" . intval(
                        $assign_userid
                    ) . "\">-- " . htmlspecialchars_uni($assign_username) . "</option>\n";
            }
        }
    }

    if ($mybb->settings['mysupport_enablepriorities']) {
        $mysupport_cache = $cache->read('mysupport');
        $mysupport_priorities = '';
        // only continue if there are any priorities
        if (!empty($mysupport_cache['priorities'])) {
            foreach ($mysupport_cache['priorities'] as $priority) {
                $mysupport_priorities .= "<option value=\"mysupport_priority_" . intval(
                        $priority['mid']
                    ) . "\">-- " . htmlspecialchars_uni($priority['name']) . "</option>\n";
            }
        }
    }

    $mysupport_categories = '';
    $categories_users = get_categories($foruminfo);
    // only continue if there are any priorities
    if (!empty($categories_users)) {
        foreach ($categories_users as $category_id => $category_name) {
            $mysupport_categories .= "<option value=\"mysupport_priority_" . intval(
                    $category_id
                ) . "\">-- " . htmlspecialchars_uni($category_name) . "</option>\n";
        }
    }

    $mysupport_inline_thread_moderation = eval($templates->render('mysupport_inline_thread_moderation'));
}

/**
 * Build an array of whom can be assigned threads. Used to build the dropdown menus and also check a valid user has been chosen.
 *
 * @return array Array of available categories.
 **/
function get_assign_users(): array
{
    global $db, $cache;

    // who can be assigned threads?
    $groups = $cache->read('usergroups');
    $assign_groups = [];
    foreach ($groups as $group) {
        if ($group['canbeassigned'] == 1) {
            $assign_groups[] = intval($group['gid']);
        }
    }

    $assign_users = [];

    // only continue if there are one or more groups that can be assigned threads
    if (!empty($assign_groups)) {
        $assigngroups = '';
        $assigngroups = implode(',', array_map('intval', $assign_groups));
        $assign_concat_sql = '';
        foreach ($assign_groups as $assign_group) {
            if (!empty($assign_concat_sql)) {
                $assign_concat_sql .= ' OR ';
            }
            $assign_concat_sql .= "CONCAT(',',additionalgroups,',') LIKE '%,{$assign_group},%'";
        }

        $query = $db->simple_select(
            'users',
            'uid, username',
            'usergroup IN (' . $db->escape_string($assigngroups) . ') OR displaygroup IN (' . $db->escape_string(
                $assigngroups
            ) . ") OR {$assign_concat_sql}",
            [
                'order_by' => 'username, uid'
            ]
        );
        while ($assigned = $db->fetch_array($query)) {
            $assign_users[$assigned['uid']] = $assigned['username'];
        }
    }
    return $assign_users;
}

/**
 * Build an array of available categories (thread prefixes). Used to build the dropdown menus, and also check a valid category has been chosen.
 *
 * @param array $forum Info on the forum.
 * @return array Array of available categories.
 **/
function get_categories(array $forum): array
{
    global $mybb, $db;

    $forums_concat_sql = $groups_concat_sql = '';

    $parent_list = explode(',', $forum['parentlist']);

    foreach ($parent_list as $parent) {
        if (!empty($forums_concat_sql)) {
            $forums_concat_sql .= ' OR ';
        }
        $forums_concat_sql .= "CONCAT(',',allowed_forums,',') LIKE '%," . intval($parent) . ",%'";
    }
    $forums_concat_sql = '(' . $forums_concat_sql . " OR allowed_forums = '-1')";

    $usergroup_list = $mybb->user['usergroup'];
    if (!empty($mybb->user['additionalgroups'])) {
        $usergroup_list .= ',' . $mybb->user['additionalgroups'];
    }
    $usergroup_list = explode(',', $usergroup_list);
    foreach ($usergroup_list as $usergroup) {
        if (!empty($groups_concat_sql)) {
            $groups_concat_sql .= ' OR ';
        }
        $groups_concat_sql .= "CONCAT(',',allowed_groups,',') LIKE '%," . intval($usergroup) . ",%'";
    }
    $groups_concat_sql = '(' . $groups_concat_sql . " OR allowed_groups = '-1')";

    $query = $db->simple_select('threadprefixes', 'pid, prefix', "{$forums_concat_sql} AND {$groups_concat_sql}");
    $categories = [];
    while ($category = $db->fetch_array($query)) {
        $categories[$category['pid']] = $category['prefix'];
    }
    return $categories;
}

/**
 * Check is MySupport is enabled in this forum.
 *
 * @param int $fid The FID of the thread.
 * @return bool Whether or not this is a MySupport forum.
 **/
function forum(int $fid): bool
{
    global $cache;

    $forum_info = get_forum($fid);

    // the parent list includes the ID of the forum itself, so this will quickly check the forum and all it's parents
    // only slight issue is that the ID of this forum would be at the end of this list, so it'd check the parents first, but if it returns true, it returns true, doesn't really matter
    $forum_ids = explode(',', $forum_info['parentlist']);

    // load the forum cache
    $forums = $cache->read('forums');
    foreach ($forums as $forum) {
        // if this forum is in the parent list
        if (in_array($forum['fid'], $forum_ids)) {
            // if this is a MySupport forum, return true
            if ($forum['mysupport'] == 1) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Check the usergroups for MySupport permissions.
 *
 * @param string $perm What permission we're checking.
 * @param int $usergroups Usergroup of the user we're checking.
 **/
function user_group(string $perm, array $usergroups = []): bool
{
    global $mybb, $cache;

    // does this key even exist? Check here if it does
    if (!array_key_exists($perm, $mybb->usergroup)) {
        return false;
    }

    // if no usergroups are specified, we're checking our own usergroups
    if (empty($usergroups)) {
        $usergroups = array_merge([$mybb->user['usergroup']], explode(',', $mybb->user['additionalgroups']));
    }

    // load the usergroups cache
    $groups = $cache->read('usergroups');
    foreach ($groups as $group) {
        // if this user is in this group
        if (in_array($group['gid'], $usergroups)) {
            // if this group can perform this action, return true
            if ($group[$perm] == 1) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Change the hold status of a thread.
 *
 * @param array $thread_info Information about the thread.
 * @param int $onhold The new hold status.
 * @param bool $multiple If this is changing the hold status of multiple threads.
 **/
function _change_hold(array $thread_info, int $onhold = 0, bool $multiple = false): void
{
    global $db, $lang;

    $tid = intval($thread_info['tid']);

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));
        $where_sql = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $where_sql = "tid = '" . $tid . "'";
    }

    if ($onhold == 0) {
        $update = [
            'onhold' => 0
        ];
        $db->update_query('threads', $update, $where_sql);

        if ($multiple) {
            mod_log_action(12, $lang->sprintf($lang->hold_off_success_multi, count($thread_info)));
            redirect_message($lang->sprintf($lang->hold_off_success_multi, count($thread_info)));
        } else {
            mod_log_action(12, $lang->hold_off_success);
            redirect_message($lang->hold_off_success);
        }
    } else {
        $update = [
            'onhold' => 1
        ];
        if ($multiple) {
            // when changing the hold status via the form in a thread, you can't you can't change the hold status if the thread's solved
            // here, it's not as easy to check for that; instead, only change the hold status if the thread isn't solved
            $where_sql .= " AND status != '1'";
        }
        $db->update_query('threads', $update, $where_sql);

        if ($multiple) {
            mod_log_action(12, $lang->sprintf($lang->hold_on_success_multi, count($thread_info)));
            redirect_message($lang->sprintf($lang->hold_on_success_multi, count($thread_info)));
        } else {
            mod_log_action(12, $lang->hold_on_success);
            redirect_message($lang->hold_on_success);
        }
    }
}

/**
 * Change who a thread is assigned to.
 *
 * @param array $thread_info Information about the thread.
 * @param int $assign The UID of who we're assigning it to now.
 * @param bool $multiple If this is changing the assigned user of multiple threads.
 **/
function change_assign(array $thread_info, int $assign, bool $multiple = false): void
{
    global $mybb, $db, $lang;

    if ($multiple) {
        $fid = -1;
        $tid = -1;
        $old_assign = -1;
    } else {
        $fid = intval($thread_info['fid']);
        $tid = intval($thread_info['tid']);
        $old_assign = intval($thread_info['assign']);
    }

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));
        $where_sql = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $where_sql = "tid = '" . $tid . "'";
    }

    // because we can assign a thread to somebody if it's already assigned to somebody else, we need to get a list of all the users who have been assigned the threads we're dealing with, so we can recount the number of assigned threads for all these users after the assignment has been chnaged
    $query = $db->simple_select('threads', 'DISTINCT assign', $where_sql . " AND assign != '0'");
    $assign_users = [
        $assign => $assign
    ];
    while ($user = $db->fetch_field($query, 'assign')) {
        $assign_users[$user] = $user;
    }

    $currentUserID = (int)$mybb->user['uid'];

    // if we're unassigning it
    if ($assign == '-1') {
        $update = [
            'assign' => 0,
            'assignuid' => 0
        ];
        // remove the assignment on the thread
        $db->update_query('threads', $update, $where_sql);

        // get information on who it was assigned to
        $user = get_user($old_assign);

        if ($multiple) {
            mod_log_action(6, $lang->sprintf($lang->unassigned_from_success_multi, count($thread_info)));
            redirect_message($lang->sprintf($lang->unassigned_from_success_multi, count($thread_info)));
        } else {
            mod_log_action(6, $lang->sprintf($lang->unassigned_from_success, $user['username']));
            redirect_message(
                $lang->sprintf($lang->unassigned_from_success, htmlspecialchars_uni($user['username']))
            );
        }
    } // if we're assigning it or changing the assignment
    else {
        $update = [
            'assign' => $assign,
            'assignuid' => $currentUserID
        ];
        if ($multiple) {
            // when assigning via the form in a thread, you can't assign a thread if it's solved
            // here, it's not as easy to check for that; instead, only assign a thread if it isn't solved
            $where_sql .= " AND status != '1'";
        }
        // assign the thread
        $db->update_query('threads', $update, $where_sql);

        $user = get_user($assign);
        $username = $db->escape_string($user['username']);

        if ($mybb->settings['mysupport_assignpm']) {
            // send the PM
            send_assign_pm($assign, $fid, $tid);
        }

        if ($mybb->settings['mysupport_assignsubscribe']) {
            if ($multiple) {
                $tids = $thread_info;
            } else {
                $tids = [$thread_info['tid']];
            }
            foreach ($tids as $tid) {
                $query = $db->simple_select('threadsubscriptions', 'sid', "uid = '{$assign}' AND tid = '{$tid}'");
                // only do this if they're not already subscribed
                if ($db->num_rows($query) == 0) {
                    if ($user['subscriptionmethod'] == 2) {
                        $subscription_method = 2;
                    }
                    // this is if their subscription method is 1 OR 0
                    // done like this because this setting forces a subscription, but we'll only subscribe them via email if the user wants it
                    else {
                        $subscription_method = 1;
                    }
                    require_once MYBB_ROOT . 'inc/functions_user.php';
                    add_subscribed_thread($tid, $subscription_method, $assign);
                }
            }
        }

        if ($multiple) {
            mod_log_action(
                5,
                $lang->sprintf($lang->assigned_to_success_multi, count($thread_info), $user['username'])
            );
            redirect_message(
                $lang->sprintf(
                    $lang->assigned_to_success_multi,
                    count($thread_info),
                    htmlspecialchars_uni($user['username'])
                )
            );
        } else {
            mod_log_action(5, $lang->sprintf($lang->assigned_to_success, $username));
            redirect_message($lang->sprintf($lang->assigned_to_success, htmlspecialchars_uni($username)));
        }
    }

    foreach ($assign_users as $user) {
        recount_assigned_threads($user);
    }
}

/**
 * Change the priority of a thread
 *
 * @param array $thread_info Information about the thread.
 * @param int $priority The ID of the new priority.
 * @param bool $multiple
 * @return void If this is changing the priority of multiple threads.
 */
function change_priority(array $thread_info, int $priority, bool $multiple = false): void
{
    global $db, $cache, $lang;

    $tid = intval($thread_info['tid']);
    $priority = $db->escape_string($priority);

    $mysupport_cache = $cache->read('mysupport');
    $priorities = [];
    if (!empty($mysupport_cache['priorities'])) {
        foreach ($mysupport_cache['priorities'] as $priority_info) {
            $priorities[$priority_info['mid']] = $priority_info['name'];
        }
    }

    $new_priority = $priorities[$priority];
    $old_priority = $priorities[$thread_info['priority']];

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));
        $where_sql = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $where_sql = "tid = '" . $tid . "'";
    }

    if ($priority == '-1') {
        $update = [
            'priority' => 0
        ];
        $db->update_query('threads', $update, $where_sql);

        if ($multiple) {
            mod_log_action(8, $lang->sprintf($lang->priority_remove_success_multi, count($thread_info)));
            redirect_message($lang->sprintf($lang->priority_remove_success_multi, count($thread_info)));
        } else {
            mod_log_action(8, $lang->sprintf($lang->priority_remove_success, $old_priority));
            redirect_message(
                $lang->sprintf($lang->priority_remove_success, htmlspecialchars_uni($old_priority))
            );
        }
    } else {
        $update = [
            'priority' => intval($priority)
        ];
        if ($multiple) {
            // when setting a priority via the form in a thread, you can't give a thread a priority if it's solved
            // here, it's not as easy to check for that; instead, only set the priority if the thread isn't solved
            $where_sql .= " AND status != '1'";
        }
        $db->update_query('threads', $update, $where_sql);

        if ($multiple) {
            mod_log_action(
                6,
                $lang->sprintf($lang->priority_change_success_to_multi, count($thread_info), $new_priority)
            );
            redirect_message(
                $lang->sprintf($lang->priority_change_success_to_multi, count($thread_info), $new_priority)
            );
        } elseif ($thread_info['priority'] == 0) {
            mod_log_action(7, $lang->sprintf($lang->priority_change_success_to, $new_priority));
            redirect_message(
                $lang->sprintf($lang->priority_change_success_to, htmlspecialchars_uni($new_priority))
            );
        } else {
            mod_log_action(
                7,
                $lang->sprintf($lang->priority_change_success_fromto, $old_priority, $new_priority)
            );
            redirect_message(
                $lang->sprintf(
                    $lang->priority_change_success_fromto,
                    htmlspecialchars_uni($old_priority),
                    htmlspecialchars_uni($new_priority)
                )
            );
        }
    }
}

/**
 * Change the category of a thread
 *
 * @param array $thread_info Information about the thread.
 * @param int $category The ID of the new category.
 * @param bool $multiple If this is changing the priority of multiple threads.
 **/
function change_category(array $thread_info, int $category, bool $multiple = false): void
{
    global $db, $lang;

    $tid = intval($thread_info['tid']);
    $category = $db->escape_string($category);

    $query = $db->simple_select('threadprefixes', 'pid, prefix');
    $categories = [];
    while ($category_info = $db->fetch_array($query)) {
        $categories[$category_info['pid']] = htmlspecialchars_uni($category_info['prefix']);
    }

    $new_category = $categories[$category];
    $old_category = $categories[$thread_info['prefix']];

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));
        $where_sql = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $where_sql = "tid = '" . $tid . "'";
    }

    if ($category == '-1') {
        $update = [
            'prefix' => 0
        ];
        $db->update_query('threads', $update, $where_sql);

        if ($multiple) {
            mod_log_action(10, $lang->sprintf($lang->category_remove_success_multi, count($thread_info)));
            redirect_message($lang->sprintf($lang->category_remove_success_multi, count($thread_info)));
        } else {
            mod_log_action(10, $lang->sprintf($lang->category_remove_success, $old_category));
            redirect_message(
                $lang->sprintf($lang->category_remove_success, htmlspecialchars_uni($old_category))
            );
        }
    } else {
        $update = [
            'prefix' => $category
        ];
        $db->update_query('threads', $update, $where_sql);

        if ($multiple) {
            mod_log_action(
                9,
                $lang->sprintf($lang->category_change_success_to_multi, count($thread_info), $new_category)
            );
            redirect_message(
                $lang->sprintf(
                    $lang->category_change_success_to_multi,
                    count($thread_info),
                    htmlspecialchars_uni($new_category)
                )
            );
        } elseif ($thread_info['prefix'] == 0) {
            mod_log_action(9, $lang->sprintf($lang->category_change_success_to, $new_category));
            redirect_message(
                $lang->sprintf($lang->category_change_success_to, htmlspecialchars_uni($new_category))
            );
        } else {
            mod_log_action(
                9,
                $lang->sprintf($lang->category_change_success_fromto, $old_category, $new_category)
            );
            redirect_message(
                $lang->sprintf(
                    $lang->category_change_success_fromto,
                    htmlspecialchars_uni($old_category),
                    htmlspecialchars_uni($new_category)
                )
            );
        }
    }
}

/**
 * Change whether a thread is a support thread
 *
 * @param array $thread_info Information about the thread.
 * @param int $issupportthread If this thread is a support thread or not (1/0)
 * @param bool $multiple If this is changing the priority of multiple threads.
 **/
function change_issupportthread(array $thread_info, int $issupportthread, bool $multiple = false): void
{
    global $db, $lang;

    $tid = intval($thread_info['tid']);

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));
        $where_sql = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $where_sql = "tid = '" . $tid . "'";
    }

    if ($issupportthread == 1) {
        $update = [
            'issupportthread' => 1
        ];
        $db->update_query('threads', $update, $where_sql);

        if ($multiple) {
            mod_log_action(13, $lang->sprintf($lang->issupportthread_1_multi, count($thread_info)));
            redirect_message($lang->sprintf($lang->issupportthread_1_multi, count($thread_info)));
        } else {
            mod_log_action(13, $lang->issupportthread_1);
            redirect_message($lang->issupportthread_1);
        }
    } else {
        $update = [
            'issupportthread' => 0
        ];
        $db->update_query('threads', $update, $where_sql);

        if ($multiple) {
            mod_log_action(13, $lang->sprintf($lang->issupportthread_0_multi, count($thread_info)));
            redirect_message($lang->sprintf($lang->issupportthread_0_multi, count($thread_info)));
        } else {
            mod_log_action(13, $lang->issupportthread_0);
            redirect_message($lang->issupportthread_0);
        }
    }
}

/**
 * Add to the moderator log message.
 *
 * @param int $id The ID of the log action.
 * @param string $message The message to add.
 **/
function mod_log_action(int $id, string $message): void
{
    global $mybb, $mod_log_action;

    $mysupportmodlog = explode(',', $mybb->settings['mysupport_modlog']);
    // if this action shouldn't be logged, return false
    if (!in_array($id, $mysupportmodlog)) {
        return;
    }
    // if the message isn't empty, add a space
    if (!empty($mod_log_action)) {
        $mod_log_action .= ' ';
    }
    $mod_log_action .= $message;
}

/**
 * Add to the redirect message.
 *
 * @param string $message The message to add.
 **/
function redirect_message(string $message): void
{
    global $redirect;

    // if the message isn't empty, add a new line
    if (!empty($redirect)) {
        $redirect .= '<br /><br />';
    }
    $redirect .= $message;
}

/**
 * Send a PM about a new assignment
 *
 * @param int $uid The UID of who we're assigning it to now.
 * @param int $fid The FID the thread is in.
 * @param int $tid The TID of the thread.
 **/
function send_assign_pm(int $uid, int $fid, int $tid): void
{
    global $mybb, $lang;

    $currentUserID = (int)$mybb->user['uid'];

    if ($uid === $currentUserID) {
        return;
    }

    $user_info = get_user($uid);
    $username = $user_info['username'];

    $forum_url = $mybb->settings['bburl'] . '/' . get_forum_link($fid);
    $forum_info = get_forum($fid);
    $forum_name = $forum_info['name'];

    $thread_url = $mybb->settings['bburl'] . '/' . get_thread_link($tid);
    $thread_info = get_thread($tid);
    $thread_name = $thread_info['subject'];

    $recipients_to = [$uid];
    $recipients_bcc = [];

    $assigned_by_user_url = $mybb->settings['bburl'] . '/' . get_profile_link($currentUserID);
    $assigned_by = $lang->sprintf(
        $lang->assigned_by,
        $assigned_by_user_url,
        htmlspecialchars_uni($mybb->user['username'])
    );

    $message = $lang->sprintf(
        $lang->assign_pm_message,
        htmlspecialchars_uni($username),
        $forum_url,
        htmlspecialchars_uni($forum_name),
        $thread_url,
        htmlspecialchars_uni($thread_name),
        $assigned_by,
        $mybb->settings['bburl']
    );

    $pm = [
        'subject' => $lang->assign_pm_subject,
        'message' => $message,
        'icon' => -1,
        'fromid' => 0,
        'toid' => $recipients_to,
        'bccid' => $recipients_bcc,
        'do' => '',
        'pmid' => '',
        'saveasdraft' => 0,
        'options' => [
            'signature' => 1,
            'disablesmilies' => 0,
            'savecopy' => 0,
            'readreceipt' => 0
        ]
    ];

    require_once MYBB_ROOT . 'inc/datahandlers/pm.php';
    $pmhandler = new PMDataHandler();

    $pmhandler->admin_override = 1;
    $pmhandler->set_data($pm);

    if ($pmhandler->validate_pm()) {
        $pmhandler->insert_pm();
    }
}

/**
 * Recount how many technical threads there are in each forum.
 *
 **/
function recount_technical_threads(): void
{
    global $db, $cache;

    $update = [
        'technicalthreads' => 0
    ];
    $db->update_query('forums', $update);

    $query = $db->simple_select('threads', 'fid', "status = '2'");
    $techthreads = [];
    while ($fid = $db->fetch_field($query, 'fid')) {
        if (empty($techthreads[$fid])) {
            $techthreads[$fid] = 0;
        }
        $techthreads[$fid]++;
    }

    foreach ($techthreads as $forum => $count) {
        $update = [
            'technicalthreads' => intval($count)
        ];
        $db->update_query('forums', $update, "fid = '" . intval($forum) . "'");
    }

    $cache->update_forums();
}

/**
 * Recount how many threads a user has been assigned.
 **/
function recount_assigned_threads(int $uid): void
{
    global $db;

    $query = $db->simple_select('threads', 'fid', "assign = '{$uid}' AND status != '1'");
    $assigned = [];
    while ($fid = $db->fetch_field($query, 'fid')) {
        if (!$assigned[$fid]) {
            $assigned[$fid] = 0;
        }
        $assigned[$fid]++;
    }
    $assigned = serialize($assigned);

    $update = [
        'assignedthreads' => $db->escape_string($assigned)
    ];
    $db->update_query('users', $update, "uid = '{$uid}'");
}

/**
 * Update points for certain MySupport actions.
 *
 * @param float $points The number of points to add/remove.
 * @param int $uid The UID of the user we're adding/removing points to/from.
 * @param bool $removing Is this removing points? Defaults to false as we'd be adding them most of the time.
 */
function update_points(float $points, int $uid, bool $removing = false): void
{
    global $mybb, $db;

    $points = intval($points);

    switch ($mybb->settings['mysupport_pointssystem']) {
        case 'myps':
            $column = 'myps';
            break;
        case 'newpoints':
            $column = 'newpoints';
            break;
        case 'other':
            $column = $db->escape_string($mybb->settings['mysupport_pointssystemcolumn']);
            break;
        default:
            $column = '';
    }

    // if it somehow had to resort to the default option above or 'other' was selected but no custom column name was specified, don't run the query because it's going to create an SQL error, no column to update
    if (!empty($column)) {
        if ($removing) {
            $operator = '-';
        } else {
            $operator = '+';
        }

        $query = $db->write_query(
            'UPDATE ' . TABLE_PREFIX . "users SET {$column} = {$column} {$operator} '{$points}' WHERE uid = '{$uid}'"
        );
    }
}

function isTechnicalStatusEnabled(): bool
{
    $isEnabled = null;

    if ($isEnabled === null) {
        $isEnabled = false;

        $forumsCache = cache_forums();

        foreach ($forumsCache as $forum) {
            if (!empty($forum['allowtechnicalstatus'])) {
                $isEnabled = true;

                break;
            }
        }
    }

    return $isEnabled;
}

function priority_insert(array $priorityData, int $priorityID = 0, bool $updatePriority = false): int
{
    global $db;

    $insert_data = [];

    if (isset($priorityData['type'])) {
        $insert_data['type'] = $db->escape_string($priorityData['type']);
    }

    if (isset($priorityData['name'])) {
        $insert_data['name'] = $db->escape_string($priorityData['name']);
    }

    if (isset($priorityData['description'])) {
        $insert_data['description'] = $db->escape_string($priorityData['description']);
    }

    if (isset($priorityData['extra'])) {
        $insert_data['extra'] = $db->escape_string($priorityData['extra']);
    }

    if (isset($priorityData['allowed_groups'])) {
        $insert_data['allowed_groups'] = $db->escape_string($priorityData['allowed_groups']);
    }

    if (isset($priorityData['allowed_forums'])) {
        $insert_data['allowed_forums'] = $db->escape_string($priorityData['allowed_forums']);
    }

    if ($updatePriority) {
        $db->update_query('mysupport', $insert_data, "mid='{$priorityID}'");

        return $priorityID;
    }

    return (int)$db->insert_query('mysupport', $insert_data);
}

function priorityUpdate(array $priorityData, int $priorityID = 0, bool $updatePriority = false): int
{
    return priority_insert($priorityData, $priorityID, true);
}
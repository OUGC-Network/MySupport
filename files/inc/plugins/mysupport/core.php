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

use Exception;
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

const DATABASE_ROW_TYPE_DENIED_REASON = 2;

const DATABASE_ROW_TYPE_BACKUP = 3;

const REVOKE_DENIED_SUPPORT = -1;

const THREAD_STATUS_NOT_SOLVED = 0;

const THREAD_STATUS_SOLVED = 1;

const THREAD_STATUS_NOT_TECHNICAL = 4;

const THREAD_STATUS_TECHNICAL = 2;

const THREAD_STATUS_NOT_ONHOLD = 0;

const THREAD_STATUS_ONHOLD = 1;

function languageLoad(): void
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

function getTemplateName(string $templateName = ''): string
{
    $templatePrefix = '';

    if ($templateName) {
        $templatePrefix = '_';
    }

    return "mysupport{$templatePrefix}{$templateName}";
}

function getTemplate(string $templateName = '', bool $enableHTMLComments = true): string
{
    global $templates;

    if (DEBUG) {
        $filePath = ROOT . "/templates/{$templateName}.html";

        $templateContents = file_get_contents($filePath);

        $templates->cache[getTemplateName($templateName)] = $templateContents;
    } elseif (my_strpos($templateName, '/') !== false) {
        $templateName = substr($templateName, strpos($templateName, '/') + 1);
    }

    return $templates->render(getTemplateName($templateName), true, $enableHTMLComments);
}

function get_setting(string $setting_key = '')
{
    global $mybb;

    return $mybb->settings['mysupport_' . $setting_key] ?? false;
}

function send_alert(int $tid, int $uid, int $author = 0): void
{
    global $lang, $mybb, $alertType, $db;

    languageLoad();

    if (!(get_setting('notifications') && class_exists('MybbStuff_MyAlerts_AlertTypeManager'))) {
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

            $priorityObjects = priorityGet(
                queryFields: ['name', 'description', 'extra'],
                queryOptions: ['order_by' => 'name']
            );

            $newCachedData['priorities'] = [];

            foreach ($priorityObjects as $priorityData) {
                $newCachedData['priorities'][(int)$priorityData['mid']] = [
                    'name' => $priorityData['name'],
                    'description' => $priorityData['description'],
                    'extra' => $priorityData['extra'],
                ];
            }

        case CACHE_TYPE_ALL:
        case CACHE_TYPE_DENIED_REASONS:
            $deniedReasonObjects = deniedReasonGet(
                queryFields: ['name', 'description'],
                queryOptions: ['order_by' => 'name']
            );

            $newCachedData['deniedReasons'] = [];

            foreach ($deniedReasonObjects as $deniedReasonData) {
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
                if (str_contains(',' . $parentlist . ',', ',' . $fid . ',')) {
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
                if (str_contains(',' . $parentlist . ',', ',' . $fid . ',')) {
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

    static $enabledForums = null;

    if ($enabledForums === null) {
        $enabledForums = [];

        $forums = $cache->read('forums');

        foreach ($forums as $forum) {
            // if this forum/category has MySupport enabled, add it to the array
            if (!empty($forum['mysupport'])) {
                $enabledForums[(int)$forum['fid']] = (int)$forum['fid'];
            } // if this forum/category hasn't got MySupport enabled...
            else {
                // ... go through the parent list...

                foreach (explode(',', $forum['parentlist']) as $parent) {
                    // ... if this parent has MySupport enabled...
                    if (!empty($forums[$parent]['mysupport'])) {
                        // ... add the original forum we're looking at to the list
                        $enabledForums[(int)$forum['fid']] = (int)$forum['fid'];
                        // this is for if we enable MySupport for a whole category; this will pick up all the forums inside that category and add them to the array
                    }
                }
            }
        }
    }

    return $enabledForums;
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

    languageLoad();

    return match ($status) {
        4 => $lang->not_technical,
        2 => $lang->technical,
        3, 1 => $lang->solved,
        default => $lang->not_solved,
    };
}

/**
 * Show the status of a thread.
 *
 * @param array $threadData
 * @return string
 */
function displayStatusGet(array $threadData): string
{
    $status = (int)$threadData['status'];

    $onhold = (int)$threadData['onhold'];

    $statusTime = (int)$threadData['statustime'];

    $theadUserID = (int)$threadData['uid'];

    global $mybb, $lang, $templates, $theme, $mysupport_status, $thread;

    $currentUserID = (int)$mybb->user['uid'];

    // big check to see if either the status is to be shown to everybody, only to people who can mark as solved, or to people who can mark as solved or who authored the thread
    if ($mybb->settings['mysupport_displayto'] == 'all' || ($mybb->settings['mysupport_displayto'] == 'canmas' && user_group(
                'canmarksolved'
            )) || ($mybb->settings['mysupport_displayto'] == 'canmasauthor' && (user_group(
                    'canmarksolved'
                ) || $currentUserID === $theadUserID))) {
        if ($mybb->settings['mysupport_relativetime']) {
            $date_time_technical = 0;

            $date_time = my_date('relative', $statusTime);

            if ($mybb->settings['mysupport_displaytype'] !== 'text') {
                $date_time = strip_tags($date_time);
            }

            $status_title = $lang->sprintf($lang->technical_time, $date_time_technical);
        } else {
            $date_time = my_date('normal', $statusTime);
        }

        // if this user cannot mark a thread as technical and people who can't mark as technical can't see that a technical thread is technical, don't execute this,
        // I used the word technical 4 times in that sentence didn't I? sorry about that
        if ($status === THREAD_STATUS_TECHNICAL && !($mybb->settings['mysupport_hidetechnical'] || ($mybb->usergroup['canseetechnotice'] || is_moderator(
                        $thread['fid'],
                        'canmarktechnical'
                    )))) {
            $status_class = $status_img = 'technical';
            $status_title = htmlspecialchars_uni($lang->sprintf($lang->technical_time, $date_time));

            if ($mybb->settings['mysupport_displaytype'] === 'text') {
                $status_text = $lang->technical;
            }
        } elseif ($status === THREAD_STATUS_SOLVED) {
            $status_class = $status_img = 'solved';
            $status_text = $lang->solved;
            $status_title = htmlspecialchars_uni($lang->sprintf($lang->solved_time, $date_time));

            if ($mybb->settings['mysupport_displaytype'] !== 'text') {
                $status_text = $lang->solved;
            }
        } else {
            $status_class = $status_img = 'notsolved';
            $status_text = $status_title = $lang->not_solved;
        }

        if ($onhold == THREAD_STATUS_ONHOLD) {
            $status_class = $status_img = 'onhold';
            $status_text = $lang->onhold;
            $status_title = $lang->onhold . ' - ' . $status_title;
        }

        if ($mybb->settings['mysupport_displaytype'] === 'text') {
            $mysupport_status = eval(getTemplate('status_text'));
        } else {
            $mysupport_status = eval(getTemplate('status_image'));
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
 * @return bool Whether your chosen points system is enabled.
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
function threadStatusUpdate(array $thread_info, int $status = 0, bool $multiple = false): void
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
        // if it's not a multiple thread, it will just loop through once as there'd only be one value in the array
        foreach ($move_tids as $move_tid) {
            $moderation->move_thread($move_tid, $move_fid, $move_type, $redirect_time);
        }
    }

    $whereClauses = [];

    if ($multiple) {
        $tids = implode("','", array_map('intval', $thread_info));

        $whereClauses[] = "tid IN ('{$tids}')";
    } else {
        $whereClauses[] = "tid='{$tid}'";
    }
    $assign_users = [];

    // we need to build an array of users who have been assigned threads before the assignment is removed
    if ($status == THREAD_STATUS_SOLVED || $status == 3) {
        foreach (threadsGet(array_merge(["assign!='0'",], $whereClauses), ['DISTINCT assign']) as $user) {
            $assign_users[] = (int)$user['assign'];
        }
    }

    $currentUserID = (int)$mybb->user['uid'];

    if ($status == 3 || ($status == THREAD_STATUS_SOLVED && $mybb->settings['mysupport_closewhensolved'] == 'always')) {
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
    } elseif ($status == THREAD_STATUS_NOT_SOLVED) {
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

    foreach (
        threadsGet(
            $whereClauses,
            ['tid']
        ) as $threadID => $threadData
    ) {
        threadUpdate($status_update, $threadID);
    }

    // if the thread is being marked as technical, being marked as something else after being marked technical, or we're changing the status of multiple threads, recount the number of technical threads
    if ($status == 2 || $old_status == 2 || $multiple) {
        recount_technical_threads();
    }
    // if the thread is being marked as solved, recount the number of assigned threads for any users who were assigned threads that are now being marked as solved
    if ($status == THREAD_STATUS_SOLVED || $status == 3) {
        foreach ($assign_users as $user) {
            recount_assigned_threads($user);
        }
    }
    if ($status === THREAD_STATUS_NOT_SOLVED) {
        // if we're marking a thread(s) as unsolved, re-open any threads that were closed when they were marked as solved, but not any that were closed by denying support
        $update = [
            'closed' => 0,
            'closedbymysupport' => 0
        ];

        foreach (
            threadsGet(
                array_merge(["closed='1'", "closedbymysupport='1'"], $whereClauses),
                ['tid']
            ) as $threadID => $threadData
        ) {
            threadUpdate($update, $threadID);
        }
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
function inline_thread_moderation(int|array $forumIDs, ?array $threadData = null): string
{
    global $mybb, $cache, $lang;

    languageLoad();

    $solveGroup = $technicalGroup = $holdGroup = $assignGroup = $prioritiesGroup = $categoriesGroup = '';

    $threadStatusOnhold = (int)$threadData['onhold'];

    $currentUserID = (int)$mybb->user['uid'];

    $theadUserID = (int)$threadData['uid'];

    if (!empty($mybb->settings['mysupport_enablenotsupportthread']) && (
            $threadData === null ||
            (is_int($forumIDs) && is_moderator($forumIDs, 'canmarksolved')) ||
            ($mybb->settings['mysupport_author'] && $theadUserID === $currentUserID)
        ) && (empty($threadData['issupportthread']) || $threadStatusOnhold !== THREAD_STATUS_ONHOLD)) {
        $isSupportOption = '';

        if ($threadData === null || empty($threadData['issupportthread'])) {
            $isSupportOption = eval(getTemplate('inline_thread_moderation_is_support'));
        }

        if ($threadData === null || !empty($threadData['issupportthread'])) {
            $isSupportOption = eval(getTemplate('inline_thread_moderation_is_not_support'));
        }
    }

    if (($threadData === null || (is_int($forumIDs) && is_moderator($forumIDs, 'canmarksolved'))) &&
        $threadStatusOnhold !== THREAD_STATUS_ONHOLD) {
        $solved = $notSolvedOption = '';

        if ($threadData === null || (int)$threadData['status'] !== THREAD_STATUS_SOLVED) {
            $solved = eval(getTemplate('inline_thread_moderation_solved'));
        }

        if ($threadData === null || (int)$threadData['status'] !== THREAD_STATUS_NOT_SOLVED) {
            $notSolvedOption = eval(getTemplate('inline_thread_moderation_not_solved'));
        }

        $solveGroup = eval(getTemplate('inline_thread_moderation_group_solved'));
    }

    $threadStatus = (int)$threadData['status'];

    if (!empty($mybb->settings['mysupport_enabletechnical']) && (
            $threadData === null || (is_int($forumIDs) && is_moderator($forumIDs, 'canmarktechnical'))
        ) && $threadStatusOnhold !== THREAD_STATUS_ONHOLD) {
        if ($threadData === null || $threadStatus !== THREAD_STATUS_TECHNICAL) {
            $technicalOption = eval(getTemplate('inline_thread_moderation_technical'));
        }

        if ($threadData === null || $threadStatus !== THREAD_STATUS_NOT_TECHNICAL) {
            $notTechnicalOption = eval(getTemplate('inline_thread_moderation_not_technical'));
        }

        $technicalGroup = eval(getTemplate('inline_thread_moderation_group_technical'));
    }

    if (!empty($mybb->settings['mysupport_enableonhold']) && (
            $threadData === null || (is_int($forumIDs) && is_moderator($forumIDs, 'canmarkonhold'))
        )) {
        if ($threadData === null || $threadStatusOnhold !== THREAD_STATUS_ONHOLD) {
            $onholdOption = eval(getTemplate('inline_thread_moderation_onhold'));
        }

        if ($threadData === null || $threadStatusOnhold !== THREAD_STATUS_NOT_ONHOLD) {
            $notOnholdOption = eval(getTemplate('inline_thread_moderation_not_onhold'));
        }

        $holdGroup = eval(getTemplate('inline_thread_moderation_group_onhold'));
    }

    if ($mybb->settings['mysupport_enableassign']) {
        $usersOptions = '';

        $assign_users = get_assign_users();

        // only continue if there are one or more users that can be assigned threads
        if (!empty($assign_users)) {
            foreach ($assign_users as $userID => $assign_username) {
                $userName = htmlspecialchars_uni($assign_username);

                $usersOptions = eval(getTemplate('inline_thread_moderation_assign_user'));
            }
        }

        $assignGroup = eval(getTemplate('inline_thread_moderation_group_assign'));
    }

    if ($mybb->settings['mysupport_enablepriorities'] && (
            $threadData === null ||
            $threadStatusOnhold !== THREAD_STATUS_ONHOLD
        )) {
        $mysupport_cache = $cache->read('mysupport');

        $prioritiesList = '';

        // only continue if there are any priorities
        if (!empty($mysupport_cache['priorities'])) {
            foreach ($mysupport_cache['priorities'] as $priorityID => $priorityData) {
                $priorityName = htmlspecialchars_uni($priorityData['name']);

                $prioritiesList .= eval(getTemplate('inline_thread_moderation_priority'));
            }
        }

        if ($prioritiesList) {
            $prioritiesGroup = eval(getTemplate('inline_thread_moderation_group_priority'));
        }
    }

    if (is_int($forumIDs)) {
        $categoriesCache = get_categories($forumIDs);
    } else {
        $categoriesCache = [];

        foreach ($forumIDs as $forumID) {
            $categoriesCache = array_merge($categoriesCache, get_categories((int)$forumID));
        }
    }

    // only continue if there are any priorities
    if (!empty($categoriesCache) && (
            $threadData === null ||
            $threadStatusOnhold !== THREAD_STATUS_ONHOLD
        )) {
        $currentCategoryID = $threadData === null ? 0 : (int)$threadData['prefix'];

        $categoryList = '';

        foreach ($categoriesCache as $categoryID => $categoryName) {
            if ($threadData !== null && $currentCategoryID && $currentCategoryID === $categoryID) {
                continue;
            }

            $categoryName = htmlspecialchars_uni($categoryName);

            $categoryList .= eval(getTemplate('inline_thread_moderation_category'));
        }

        $categoryNone = '';

        if ($threadData === null || $currentCategoryID) {
            $categoryNone = eval(getTemplate('inline_thread_moderation_category_none'));
        }

        if ($categoryList || $categoryNone) {
            $categoriesGroup = eval(getTemplate('inline_thread_moderation_group_categories'));
        }
    }

    if ($solveGroup || $technicalGroup || $holdGroup || $assignGroup || $prioritiesGroup || $categoriesGroup) {
        return eval(getTemplate('inline_thread_moderation'));
    }

    return '';
}

function priorityClassGetName(int $priorityID): string
{
    global $cache;

    $prioritiesCache = $cache->read('mysupport');

    if (!empty($prioritiesCache['priorities']) &&
        !empty($prioritiesCache['priorities'][$priorityID]) &&
        !empty($prioritiesCache['priorities'][$priorityID]['name'])) {
        return strtolower(
            htmlspecialchars_uni(
                preg_replace(
                    '/[^A-Za-z0-9 ]/',
                    '_',
                    $prioritiesCache['priorities'][$priorityID]['name']
                ),
            )
        );
    }

    return '';
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
        if (!empty($group['canbeassigned'])) {
            $assign_groups[] = intval($group['gid']);
        }
    }

    $assign_users = [];

    // only continue if there are one or more groups that can be assigned threads
    if (!empty($assign_groups)) {
        $assigngroups = implode("','", $assign_groups);

        $whereClauses = ["usergroup IN ('{$assigngroups}')", "displaygroup IN ('{$assigngroups}')"];

        foreach ($assign_groups as $assign_group) {
            $whereClauses[] = match ($db->type) {
                'pgsql', 'sqlite' => "','||additionalgroups||',' LIKE '%,{$assign_group},%'",
                default => "CONCAT(',',additionalgroups,',') LIKE '%,{$assign_group},%'",
            };
        }

        foreach (
            usersGet(
                [implode(' OR ', $whereClauses)],
                ['uid', 'username'],
                [
                    'order_by' => 'username',
                ]
            ) as $assigned
        ) {
            $assign_users[(int)$assigned['uid']] = $assigned['username'];
        }
    }
    return $assign_users;
}

/**
 * Build an array of available categories (thread prefixes). Used to build the dropdown menus, and also check a valid category has been chosen.
 *
 * @param int $forumID
 * @return array Array of available categories.
 */
function get_categories(int $forumID): array
{
    global $mybb;

    $forumData = get_forum($forumID);

    $prefixesCache = (array)$mybb->cache->read('threadprefixes');

    $categories = [];

    foreach ($prefixesCache as $category) {
        if (is_member($category['groups']) &&
            is_member(
                $category['forums'],
                ['usergroup' => $forumData['fid'], 'additionalgroups' => $forumData['parentlist']]
            )) {
            $categories[(int)$category['pid']] = $category['prefix'];
        }
    }

    return $categories;
}

/**
 * Check is MySupport is enabled in this forum.
 *
 * @param int $fid The FID of the thread.
 * @return bool Whether this is a MySupport forum.
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
 * @param array $usergroups Usergroup of the user we're checking.
 * @return bool
 */
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
function threadOnholdStatusUpdate(array $thread_info, int $onhold = 0, bool $multiple = false): void
{
    global $db, $lang;

    $tid = intval($thread_info['tid']);

    $whereClauses = [];

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));
        $whereClauses[] = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $whereClauses[] = "tid = '" . $tid . "'";
    }

    if ($onhold == 0) {
        $update = [
            'onhold' => 0
        ];

        foreach (
            threadsGet(
                $whereClauses,
                ['tid']
            ) as $threadID => $threadData
        ) {
            threadUpdate($update, $threadID);
        }

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
            $whereClauses[] = "status != '1'";
        }

        foreach (
            threadsGet(
                $whereClauses,
                ['tid']
            ) as $threadID => $threadData
        ) {
            threadUpdate($update, $threadID);
        }

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

    $whereClauses = [];

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));

        $whereClauses[] = "tid IN (' . $db->escape_string($tids) . ')";
    } else {
        $whereClauses[] = "tid='{$tid}'";
    }

    // because we can assign a thread to somebody if it's already assigned to somebody else, we need to get a list of all the users who have been assigned the threads we're dealing with, so we can recount the number of assigned threads for all these users after the assignment has been changed

    foreach (threadsGet(array_merge(["assign!='0'",], $whereClauses), ['DISTINCT assign']) as $user) {
        $assign_users[(int)$user['assign']] = (int)$user['assign'];
    }

    $currentUserID = (int)$mybb->user['uid'];

    // if we're unassigning it
    if ($assign == '-1') {
        $update = [
            'assign' => 0,
            'assignuid' => 0
        ];

        // remove the assignment on the thread
        foreach (
            threadsGet(
                $whereClauses,
                ['tid']
            ) as $threadID => $threadData
        ) {
            threadUpdate($update, $threadID);
        }

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
            $whereClauses[] = "status != '1'";
        }

        // assign the thread
        foreach (
            threadsGet(
                $whereClauses,
                ['tid']
            ) as $threadID => $threadData
        ) {
            threadUpdate($update, $threadID);
        }

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
 * @param int $threadID The ID of the thread.
 * @param int $priorityID The ID of the new priority.
 * @return void
 */
function threadPriorityUpdate(int $threadID, int $priorityID): void
{
    if ($priorityID === -1) {
        $priorityID = 0;
    }

    threadUpdate(['priority' => $priorityID], $threadID);
}

/**
 * Change the category of a thread
 *
 * @param int $threadID The thread ID.
 * @param int $categoryID
 * @param bool $multiple
 */
function threadCategoryUpdate(int $threadID, int $categoryID, bool $multiple = false): void
{
    if ($categoryID === -1) {
        $categoryID = 0;
    }

    threadUpdate(['prefix' => $categoryID], $threadID);


    ///
    ///
    ///
    ///
    ///

    global $mybb, $db, $lang;

    $tid = intval($threadData['tid']);

    $prefixesCache = (array)$mybb->cache->read('threadprefixes');

    $categories = [];

    foreach ($prefixesCache as $categoryData) {
        $categories[(int)$categoryData['pid']] = $categoryData['prefix'];
    }

    $new_category = $categories[$categoryID];
    $old_category = $categories[$threadData['prefix']];

    $whereClauses = [];

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $threadData));
        $whereClauses[] = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $whereClauses[] = "tid = '" . $tid . "'";
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

    $whereClauses = [];

    // this'll be the same wherever so set this here
    if ($multiple) {
        $tids = implode(',', array_map('intval', $thread_info));
        $whereClauses[] = 'tid IN (' . $db->escape_string($tids) . ')';
    } else {
        $whereClauses[] = "tid = '" . $tid . "'";
    }

    if ($issupportthread == 1) {
        $update = [
            'issupportthread' => 1
        ];

        foreach (
            threadsGet(
                $whereClauses,
                ['tid']
            ) as $threadID => $threadData
        ) {
            threadUpdate($update, $threadID);
        }

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

        foreach (
            threadsGet(
                $whereClauses,
                ['tid']
            ) as $threadID => $threadData
        ) {
            threadUpdate($update, $threadID);
        }

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
    global $mySupportRedirectMessages;

    isset($mySupportRedirectMessages) || $mySupportRedirectMessages = '';

    // if the message isn't empty, add a new line
    if ($mySupportRedirectMessages !== '') {
        $mySupportRedirectMessages .= '<br /><br />';
    }

    $mySupportRedirectMessages .= $message;
}

/**
 * Send a PM about a new assignment
 *
 * @param int $uid The UID of whom we're assigning it to now.
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

    $techthreads = [];

    foreach (threadsGet(["status='2'"], ['fid']) as $threadData) {
        if (empty($techthreads[(int)$threadData['fid']])) {
            $techthreads[(int)$threadData['fid']] = 0;
        }

        ++$techthreads[(int)$threadData['fid']];
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

    $assigned = [];

    foreach (threadsGet(["assign='{$uid}'", "status!='1'"], ['fid']) as $threadData) {
        if (!$assigned[(int)$threadData['fid']]) {
            $assigned[(int)$threadData['fid']] = 0;
        }

        ++$assigned[(int)$threadData['fid']];
    }
    $assigned = serialize($assigned);

    $update = [
        'assignedthreads' => $db->escape_string($assigned)
    ];

    userUpdate($update, $uid);
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

    $column = match ($mybb->settings['mysupport_pointssystem']) {
        'myps' => 'myps',
        'newpoints' => 'newpoints',
        'other' => $db->escape_string($mybb->settings['mysupport_pointssystemcolumn']),
        default => '',
    };

    // if it somehow had to resort to the default option above or 'other' was selected but no custom column name was specified, don't run the query because it's going to create an SQL error, no column to update
    if (!empty($column)) {
        if ($removing) {
            $operator = '-';
        } else {
            $operator = '+';
        }

        userUpdate([$column => "{$column}{$operator}'{$points}'"], $uid, true);
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

function usersGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array|false
{
    global $db;

    $query = $db->simple_select(
        'users',
        implode(',', $queryFields),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (!$db->num_rows($query)) {
        return false;
    }

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $objects = [];

    while ($userData = $db->fetch_array($query)) {
        if (isset($userData['tid'])) {
            $objects[(int)$userData['tid']] = $userData;
        } else {
            $objects[] = $userData;
        }
    }

    return $objects;
}

function userUpdate(array $userData, int $userID, bool $noQuote = false): bool
{
    global $db;

    try {
        $db->update_query('users', $userData, "uid='{$userID}'", no_quote: $noQuote);
    } catch (Exception $e) {
        return false;
    }

    return true;
}

function threadsGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array|false
{
    global $db;

    if (empty($queryFields)) {
        $queryFields[] = 'tid';
    }

    $query = $db->simple_select(
        'threads',
        implode(',', $queryFields),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (!$db->num_rows($query)) {
        return false;
    }

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $objects = [];

    while ($threadData = $db->fetch_array($query)) {
        if (isset($threadData['tid'])) {
            $objects[(int)$threadData['tid']] = $threadData;
        } else {
            $objects[] = $threadData;
        }
    }

    return $objects;
}

function threadUpdate(array $threadData, int $threadID): bool
{
    global $db;

    //onhold

    try {
        $db->update_query('threads', $threadData, "tid='{$threadID}'");
    } catch (Exception $e) {
        return false;
    }

    return true;
}

function contentGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array|false
{
    global $db;

    $queryFields[] = 'mid';

    $query = $db->simple_select(
        'mysupport',
        implode(',', $queryFields),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (!$db->num_rows($query)) {
        return false;
    }

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $objects = [];

    while ($priorityData = $db->fetch_array($query)) {
        $objects[(int)$priorityData['mid']] = $priorityData;
    }

    return $objects;
}

function priorityInsert(array $priorityData, int $priorityID = 0, bool $isUpdate = false): int
{
    global $db;

    $insert_data = [];

    $insert_data['type'] = DATABASE_ROW_TYPE_PRIORITY;

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

    if ($isUpdate) {
        $db->update_query('mysupport', $insert_data, "mid='{$priorityID}'");

        return $priorityID;
    }

    return (int)$db->insert_query('mysupport', $insert_data);
}

function priorityUpdate(array $priorityData, int $priorityID): int
{
    return priorityInsert($priorityData, $priorityID, true);
}

function priorityGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array|false
{
    $priorityType = DATABASE_ROW_TYPE_PRIORITY;

    $whereClauses[] = "type='{$priorityType}'";

    return contentGet($whereClauses, $queryFields, $queryOptions);
}

function priorityDelete(int $priorityID): bool
{
    global $db;

    $priorityType = DATABASE_ROW_TYPE_PRIORITY;

    try {
        $db->delete_query('mysupport', "type='{$priorityType}' AND mid='{$priorityID}'");
    } catch (Exception $e) {
        return false;
    }

    return true;
}

function deniedReasonInsert(array $deniedReasonData, int $deniedReasonID = 0, bool $isUpdate = false): int
{
    global $db;

    $insert_data = [];

    $insert_data['type'] = DATABASE_ROW_TYPE_DENIED_REASON;

    if (isset($deniedReasonData['name'])) {
        $insert_data['name'] = $db->escape_string($deniedReasonData['name']);
    }

    if (isset($deniedReasonData['description'])) {
        $insert_data['description'] = $db->escape_string($deniedReasonData['description']);
    }

    if ($isUpdate) {
        $db->update_query('mysupport', $insert_data, "mid='{$deniedReasonID}'");

        return $deniedReasonID;
    }

    return (int)$db->insert_query('mysupport', $insert_data);
}

function deniedReasonUpdate(array $deniedReasonData, int $deniedReasonID): int
{
    return deniedReasonInsert($deniedReasonData, $deniedReasonID, true);
}

function deniedReasonGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array|false
{
    $deniedReasonType = DATABASE_ROW_TYPE_DENIED_REASON;

    $whereClauses[] = "type='{$deniedReasonType}'";

    return contentGet($whereClauses, $queryFields, $queryOptions);
}

function deniedReasonDelete(int $deniedReasonID): bool
{
    global $db;

    $deniedReasonType = DATABASE_ROW_TYPE_DENIED_REASON;

    try {
        $db->delete_query('mysupport', "type='{$deniedReasonType}' AND mid='{$deniedReasonID}'");
    } catch (Exception $e) {
        return false;
    }

    return true;
}

function backupInsert(array $backupData, int $backupID = 0, bool $isUpdate = false): int
{
    global $db;

    $insert_data = [];

    $insert_data['type'] = DATABASE_ROW_TYPE_BACKUP;

    if (isset($backupData['name'])) {
        $insert_data['name'] = $db->escape_string($backupData['name']);
    }

    if (isset($backupData['description'])) {
        $insert_data['description'] = $db->escape_string($backupData['description']);
    }

    if (isset($priorityData['extra'])) {
        $insert_data['extra'] = $db->escape_string($priorityData['extra']);
    }

    if ($isUpdate) {
        $db->update_query('mysupport', $insert_data, "mid='{$backupID}'");

        return $backupID;
    }

    return (int)$db->insert_query('mysupport', $insert_data);
}

function backupUpdate(array $backupData, int $backupID): int
{
    return backupInsert($backupData, $backupID, true);
}

function backupGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array|false
{
    $backupType = DATABASE_ROW_TYPE_BACKUP;

    $whereClauses[] = "type='{$backupType}'";

    return contentGet($whereClauses, $queryFields, $queryOptions);
}

function backupDelete(int $backupID): bool
{
    global $db;

    $backupType = DATABASE_ROW_TYPE_BACKUP;

    try {
        $db->delete_query('mysupport', "type='{$backupType}' AND mid='{$backupID}'");
    } catch (Exception $e) {
        return false;
    }

    return true;
}
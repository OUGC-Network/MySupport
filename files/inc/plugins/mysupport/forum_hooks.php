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

namespace MySupport\ForumHooks;

use MyBB;
use MySupport\Core\AssignStatus;
use PostDataHandler;
use MySupport\Core\Url;
use MySupport\Core\ThreadStatus;

use function MySupport\Core\assignGet;
use function MySupport\Core\generatePrioritiesStyleCode;
use function MySupport\Core\moderationToolUpdateOnholdStatus;
use function MySupport\Core\moderationToolUpdateSolveStatus;
use function MySupport\Core\moderationToolUpdateSupportStatus;
use function MySupport\Core\moderationToolUpdateTechnicalStatus;
use function MySupport\Core\threadOnholdStatusUpdate;
use function MySupport\Core\threadSolveStatusUpdate;
use function MySupport\Core\deniedReasonGet;
use function MySupport\Core\enabledForums;
use function MySupport\Core\assignedThreadsGetTotal;
use function MySupport\Core\technicalThreadsGetTotal;
use function MySupport\Core\displayStatusGet;
use function MySupport\Core\threadSolvedFriendlyStatusGet;
use function MySupport\Core\_points_system_enabled;
use function MySupport\Core\change_issupportthread;
use function MySupport\Core\get_assign_users;
use function MySupport\Core\cacheGetCategories;
use function MySupport\Core\settingsGet;
use function MySupport\Core\getTemplate;
use function MySupport\Core\inlineModerationBuild;
use function MySupport\Core\isTechnicalStatusEnabled;
use function MySupport\Core\languageLoad;
use function MySupport\Core\mod_log_action;
use function MySupport\Core\threadAssignmentUpdate;
use function MySupport\Core\threadCategoryUpdate;
use function MySupport\Core\threadPriorityUpdate;
use function MySupport\Core\priorityClassGetName;
use function MySupport\Core\redirect_message;
use function MySupport\Core\threadsGet;
use function MySupport\Core\threadUpdate;
use function MySupport\Core\update_points;
use function MySupport\Core\usersGet;
use function MySupport\Core\usersUpdateAssignCount;
use function MySupport\Core\userUpdate;

use const MySupport\Core\REVOKE_DENIED_SUPPORT;

function global_start(): void
{
    global $templatelist, $mybb;

    if (isset($templatelist)) {
        $templatelist .= ',';
    } else {
        $templatelist = '';
    }

    $templatelist .= ',';

    if (defined('THIS_SCRIPT')) {
        if (THIS_SCRIPT === 'showthread.php') {
            $templatelist .= ', ';
        }

        if (THIS_SCRIPT === 'editpost.php' || THIS_SCRIPT === 'newthread.php') {
            $templatelist .= ', ';
        }

        if (THIS_SCRIPT === 'forumdisplay.php') {
            $templatelist .= ', ';
        }
    }
    /*if(\MySupport\MyAlerts\myalertsIsIntegrable())
	{
		if($currentUserID)
		{
            \MySupport\MyAlerts\registerMyalertsFormatters();
        }
    }*/
}

function build_friendly_wol_location_end(array &$plugin_array): array
{
    global $lang;

    if ($plugin_array['user_activity']['activity'] === 'modcp_techthreads') {
        $plugin_array['location_name'] = $lang->mysupport_wol_technical;
    } elseif ($plugin_array['user_activity']['activity'] === 'usercp_supportthreads') {
        $plugin_array['location_name'] = $lang->mysupport_wol_support;
    } elseif ($plugin_array['user_activity']['activity'] === 'modcp_supportdenial') {
        $plugin_array['location_name'] = $lang->mysupport_wol_support_denial;
    } elseif ($plugin_array['user_activity']['activity'] === 'modcp_supportdenial_deny') {
        $plugin_array['location_name'] = $lang->mysupport_wol_support_denial_deny;
    }

    return $plugin_array;
}

function datahandler_post_validate_post(PostDataHandler $data): PostDataHandler
{
    global $db, $posthandler;

    if (count($posthandler->get_errors()) > 0) {
        return $data;
    }

    $pid = 0;

    // if we're editing a post, see if it's the last post in the thread and was written by the thread poster
    if ($posthandler->method === 'update') {
        $post = get_post($posthandler->data['pid']);
        $thread_tid = (int)$post['tid'];
        $thread_uid = $post['uid'];

        $query = $db->simple_select(
            'posts',
            'pid',
            "tid = '" . intval($thread_tid) . "'",
            ['order_by' => 'dateline', 'order_dir' => 'DESC', 'limit' => 1]
        );
        $pid = (int)$db->fetch_field($query, 'pid');
        $posthandler->data['uid'] = $posthandler->data['edit_uid'];
    } else {
        $thread = get_thread($posthandler->data['tid']);
        $thread_tid = (int)$posthandler->data['tid'];
        $thread_uid = $thread['uid'];
    }

    // The user submitting this data is the author of the thread
    // . They're either making a new reply
    // or they're editing the last post in the thread, which is theirs
    // take the thread off hold, as they've made an update
    if ($posthandler->data['uid'] == $thread_uid && ($posthandler->method === 'insert' || ($posthandler->method === 'update' && $posthandler->data['pid'] === $pid))) {
        $update = [
            'onhold' => 0
        ];
    } else {
        $update = [
            'onhold' => 1
        ];
    }

    threadUpdate($update, $thread_tid);

    return $data;
}

function fetch_wol_activity_end(array &$user_activity): array
{
    if (str_contains($user_activity['location'], 'modcp.php?action=technicalthreads')) {
        $user_activity['activity'] = 'modcp_techthreads';
    } elseif (str_contains($user_activity['location'], 'usercp.php?action=supportthreads')) {
        $user_activity['activity'] = 'usercp_supportthreads';
    } elseif (str_contains($user_activity['location'], 'modcp.php?action=supportdenial')) {
        if (my_strpos($user_activity['location'], 'do=denysupport') !== false || my_strpos(
                $user_activity['location'],
                'do=do_denysupport'
            ) !== false) {
            $user_activity['activity'] = 'modcp_supportdenial_deny';
        } else {
            $user_activity['activity'] = 'modcp_supportdenial';
        }
    }

    return $user_activity;
}

function forumdisplay_start(): void
{
    // generate the CSS classes
    global $headerinclude;

    $headerinclude .= generatePrioritiesStyleCode();
}

function forumdisplay_threadlist(): void
{
    global $foruminfo;
    global $inlinemod;

    if (empty($inlinemod) ||
        !str_contains($inlinemod, '<!--MySupportInlineModerationOptions-->') ||
        empty($foruminfo['mysupport']) ||
        empty($foruminfo['allowbestanswerstatus'])) {
        return;
    }

    $inlinemod = str_replace(
        '<!--MySupportInlineModerationOptions-->',
        inlineModerationBuild((int)$foruminfo['fid']),
        $inlinemod
    );
}

function search_results_start(): void
{
    // generate the CSS classes
    global $headerinclude;

    $headerinclude .= generatePrioritiesStyleCode();
}

function search_results_end(): void
{
    global $thread_cache;
    global $inlinemod;

    $resultForumIDs = array_column($thread_cache, 'fid');

    if (empty($inlinemod) ||
        !str_contains($inlinemod, '<!--MySupportInlineModerationOptions-->') ||
        !is_member(
            enabledForums(),
            ['usergroup' => '', 'additionalgroups' => implode(',', $resultForumIDs)]
        )) {
        return;
    }

    $inlinemod = str_replace(
        '<!--MySupportInlineModerationOptions-->',
        inlineModerationBuild($resultForumIDs),
        $inlinemod
    );
}

function forumdisplay_before_thread(array $hookArguments = []): void
{
    usercp_thread_subscriptions_thread($hookArguments['tids']);
}

// show the status of a thread for each thread on the forum display or a list of search results
function forumdisplay_thread(): void
{
    usercp_thread_subscriptions_thread10();

    return;
    global $mybb, $lang, $theme;
    global $fid, $thread, $inline_mod_checkbox, $bgcolor;

    // need to reset these outside of the check for if it's a MySupport forum, otherwise they don't get unset in search results where the forum of the next thread may not be a MySupport forum

    $forumData = get_forum($fid);

    if (empty($forumData['mysupport']) ||
        empty($thread['issupportthread']) ||
        str_contains($thread['closed'], 'moved')) {
        return;
    }

    $currentUserID = (int)$mybb->user['uid'];

    $prioritiesCache = $mybb->cache->read('mysupport');

    $priorityID = (int)$thread['priority'];

    $priorityName = $prioritiesCache['priorities'][$priorityID]['name'] ?? '';

    // the only thing we might want to do with sticky threads is to give them a priority, to highlight them; they're not going to have a status or be assigned to anybody
    // after we've done the priority, we can exit
    if ($thread['sticky'] == 1) {
        //return;
    }

    $threadAssignUserID = (int)$thread['assign'];

    $imageUrl = $mybb->get_asset_url('images/mysupport_assigned.png');

    $url = (new Url('usercp.php'))->build([
        'action' => 'assignedthreads',
        'fid' => $fid,
    ]);
}


function search_results_thread(): void
{
    static $done;

    if (empty($done)) {
        $done = true;

        global $thread_cache;

        usercp_thread_subscriptions_thread(array_column($thread_cache, 'tid'));
    }

    usercp_thread_subscriptions_thread10();
}

// show a notice for technical and/or assigned threads
function global_intermediate(): void
{
    global $mybb, $lang, $theme;
    global $mySupportGlobalNoticeTechnical, $mySupportGlobalNoticeAssigned;

    $mySupportGlobalNoticeTechnical = $mySupportGlobalNoticeAssigned = '';

    // this function does both the technical threads alert and the assigned threads alert,
    // both similar enough to keep in one function but different enough to be separated into two chunks

    // some code used in both works out now

    $forumID = 0;

    // check for THIS_SCRIPT so it doesn't execute if we're viewing the technical threads list in the MCP or support threads in the UCP with an FID
    if (defined('THIS_SCRIPT')) {
        global $db;

        switch (THIS_SCRIPT) {
            case 'announcements.php':
                $announcementID = $mybb->get_input('aid', MyBB::INPUT_INT);

                $query = $db->simple_select('announcements', 'fid', "aid='{$announcementID}'");

                $announcementData = $db->fetch_array($query);

                $forumID = (int)$announcementData['fid'];
                break;
            case 'editpost.php':
                $postID = $mybb->get_input('pid', MyBB::INPUT_INT);

                $postData = get_post($postID);

                $forumID = (int)$postData['fid'];
                break;
            case 'forumdisplay.php':
            case 'newthread.php':
            case 'misc.php':
                $forumID = $mybb->get_input('fid', MyBB::INPUT_INT);

                break;
            case 'newreply.php':
            case 'printthread.php':
            case 'sendthread.php':
            case 'showthread.php':
                $threadID = $mybb->get_input('tid', MyBB::INPUT_INT);

                $threadData = get_thread($threadID);

                $forumID = (int)$threadData['fid'];
                break;
            case 'polls.php':
                if ($mybb->get_input('action') === 'newpoll') {
                    $threadID = $mybb->get_input('tid', MyBB::INPUT_INT);

                    $threadData = get_thread($threadID);

                    $forumID = (int)$threadData['fid'];
                }

                if ($mybb->get_input('action') === 'editpoll' || $mybb->get_input('action') === 'showresults') {
                    $pollID = $mybb->get_input('pid', MyBB::INPUT_INT);

                    $query = $db->simple_select('polls', 'tid', "pid='{$pollID}'");

                    $threadID = (int)$db->fetch_field($query, 'tid');

                    $threadData = get_thread($threadID);

                    $forumID = (int)$threadData['fid'];
                }
                break;
        }
    }

    // this user is in an allowed usergroup?
    if (settingsGet('enableassign') && !empty($mybb->usergroup['canbeassigned'])) {
        $totalAssignedThreads = assignedThreadsGetTotal();

        if ($totalAssignedThreads) {
            languageLoad();

            $totalAssignedThreadsForum = 0;

            if ($forumID) {
                $totalAssignedThreadsForum = assignedThreadsGetTotal($forumID);
            }

            if ($totalAssignedThreadsForum) {
                $message = $lang->sprintf(
                    $lang->assign_forum,
                    $totalAssignedThreads,
                    $totalAssignedThreads === 1 ? $lang->mysupport_thread : $lang->mysupport_threads,
                    $totalAssignedThreadsForum
                );

                $url = (new Url('usercp.php'))->build([
                    'action' => 'assignedthreads',
                    'fid' => $forumID,
                ]);
            } else {
                $message = $lang->sprintf(
                    $lang->assign_global,
                    $totalAssignedThreads,
                    $totalAssignedThreads === 1 ? $lang->mysupport_thread : $lang->mysupport_threads
                );

                $url = (new Url('usercp.php'))->build([
                    'action' => 'assignedthreads',
                ]);
            }

            $mySupportGlobalNoticeAssigned = eval(getTemplate('global_notice'));
        }
    }

    if (empty($mybb->usergroup['canseetechnotice']) ||
        !isTechnicalStatusEnabled() ||
        settingsGet('technicalnotice') === 'off') {
        return;
    }

    languageLoad();

    // this user is in an allowed usergroup?
    $totalTechnicalThreadsGlobal = 0;

    // the notice is showing on all pages
    if (settingsGet('technicalnotice') === 'global') {
        // count for the entire forum
        $totalTechnicalThreadsGlobal = technicalThreadsGetTotal();
    }

    $totalTechnicalThreadsForum = 0;

    // if the notice is enabled, it'll at least show in the forums containing technical threads
    if ($forumID) {
        // count for the forum we're in now
        $totalTechnicalThreadsForum = technicalThreadsGetTotal($forumID);
    }

    if ($totalTechnicalThreadsForum) {
        $url = (new Url('modcp.php'))->build([
            'action' => 'technicalthreads',
            'fid' => $forumID,
        ]);
    } else {
        $url = (new Url('modcp.php'))->build([
            'action' => 'technicalthreads',
        ]);
    }

    // now, to show the notice itself,
    // it's showing globally
    if (settingsGet('technicalnotice') === 'global') {
        // we're in a forum/thread, and the count for this forum, generated above, is more than 0, show the global count and forum count
        if ($forumID && $totalTechnicalThreadsForum) {
            $message = $lang->sprintf(
                $lang->technical_global_forum,
                $totalTechnicalThreadsGlobal,
                $totalTechnicalThreadsGlobal === 1 ? $lang->mysupport_thread : $lang->mysupport_threads,
                $totalTechnicalThreadsForum
            );
        } // either there's no forum/thread, or there is but there are no tech threads in this forum, just show the global count
        else {
            $message = $lang->sprintf(
                $lang->technical_global,
                $totalTechnicalThreadsGlobal,
                $totalTechnicalThreadsGlobal === 1 ? $lang->mysupport_thread : $lang->mysupport_threads
            );
        }

        if ($totalTechnicalThreadsGlobal) {
            $mySupportGlobalNoticeTechnical = eval(getTemplate('global_notice'));
        }
    } // it's only showing in the relevant forums, if necessary
    elseif (settingsGet('technicalnotice') === 'specific') {
        if ($totalTechnicalThreadsForum == 1) {
            $totalTechnicalThreadsGlobal === 1 ? $lang->mysupport_thread : $lang->mysupport_threads = $lang->mysupport_thread;
        } else {
            $totalTechnicalThreadsGlobal === 1 ? $lang->mysupport_thread : $lang->mysupport_threads = $lang->mysupport_threads;
        }

        // we're inside a forum/thread, and the count for this forum, generated above, is more than 0, show the forum count
        if ($forumID && $totalTechnicalThreadsForum) {
            $message = $lang->sprintf(
                $lang->technical_forum,
                $totalTechnicalThreadsForum,
                $totalTechnicalThreadsGlobal === 1 ? $lang->mysupport_thread : $lang->mysupport_threads
            );

            $mySupportGlobalNoticeTechnical = eval(getTemplate('global_notice'));
        }
    }
}

// show MySupport information on a user's profile
function member_profile_end(): void
{
    global $mybb, $db, $lang;
    global $theme, $memprofile, $mySupportProfileDetails;

    languageLoad();

    $mySupportProfileDetails = '';

    $profileUserID = (int)$memprofile['uid'];

    $bestAnswersForumIDs = [];

    foreach (enabledForums() as $forumID) {
        $forumData = get_forum($forumID);

        if (!empty($forumData['allowbestanswerstatus'])) {
            $bestAnswersForumIDs[] = $forumID;
        }
    }

    $bestAnswersRow = $deniedSupportRow = '';

    if ($bestAnswersForumIDs) {
        $bestAnswersForumIDs = implode("','", $bestAnswersForumIDs);

        $enabledForums = implode("','", enabledForums());

        $query = $db->simple_select(
            "threads thread LEFT JOIN {$db->table_prefix}posts post ON (thread.bestanswer=post.pid)",
            'COUNT(thread.tid) AS total_best_answers',
            "thread.fid IN ('{$bestAnswersForumIDs}}') AND thread.fid IN ('{$enabledForums}}') AND post.uid = '{$profileUserID}'"
        );

        $bestAnswersCount = my_number_format(
            $db->fetch_field($query, 'total_best_answers')
        );

        $bestAnswersRow = eval(getTemplate('member_profile_best_answers'));
    }

    if (settingsGet('enablesupportdenial') && !empty($memprofile['deniedsupport'])) {
        $deniedSupportText = $lang->mySupportProfileDeniedSupportNotice;

        if (!empty($mybb->usergroup['canmanagesupportdenial'])) {
            $deniedReasonsCache = $mybb->cache->read('mysupport');

            if (array_key_exists($memprofile['deniedsupportreason'], $deniedReasonsCache['deniedReasons'])) {
                $deniedSupportText .= ' ' . $lang->sprintf(
                        $lang->deniedsupport_reason,
                        htmlspecialchars_uni(
                            $deniedReasonsCache['deniedReasons'][$memprofile['deniedsupportreason']]['name']
                        )
                    );
            }

            $url = (new Url('modcp.php'))->build([
                'action' => 'supportdenial',
                'do' => 'denysupport',
                'uid' => $profileUserID,
            ]);

            $deniedSupportText = eval(getTemplate('member_profile_denied_support_link'));
        }

        $deniedSupportRow = eval(getTemplate('member_profile_denied_support'));
    }

    if ($bestAnswersRow || $deniedSupportRow) {
        $mySupportProfileDetails = eval(getTemplate('member_profile'));
    }
}

function modcp_start(): void
{
    global $mybb;

    global $db, $cache, $lang, $theme, $templates, $headerinclude, $header, $footer, $modcp_nav, $mod_log_action, $mySupportRedirectMessages;

    languageLoad();

    if ($mybb->get_input('action') === 'supportdenial') {
        if (!$mybb->usergroup['canmanagesupportdenial']) {
            error_no_permission();
        }

        add_breadcrumb($lang->nav_modcp, 'modcp.php');
        add_breadcrumb($lang->support_denial, 'modcp.php?action=supportdenial');

        $currentUserID = (int)$mybb->user['uid'];

        if ($mybb->get_input('do') === 'do_denysupport') {
            verify_post_check($mybb->get_input('my_post_key'));

            if (!settingsGet('enablesupportdenial')) {
                error($lang->support_denial_not_enabled);
            }

            $uid = 0;

            // get username from UID
            // this is if we're revoking via the list of denied users, we specify a UID here
            if ($mybb->get_input('uid', MyBB::INPUT_INT)) {
                $user = get_user($mybb->get_input('uid', MyBB::INPUT_INT));

                if (!empty($user['username'])) {
                    $uid = (int)$user['uid'];

                    $username = $user['username'];
                }
            }
            // get UID from username
            // this is if we're denying support via the form, where we give a username
            elseif ($mybb->get_input('username')) {
                $user = get_user_by_username($mybb->get_input('username'), ['fields' => ['username']]);

                if (!empty($user['username'])) {
                    $uid = (int)$user['uid'];

                    $username = $user['username'];
                }
            }

            if (!$uid || !isset($username)) {
                error($lang->support_denial_reason_invalid_user);

                exit;
            }

            $deniedsupportreason = 0;

            if ($mybb->get_input('deniedsupportreason', MyBB::INPUT_INT)) {
                $deniedsupportreason = $mybb->get_input('deniedsupportreason', MyBB::INPUT_INT);
            }

            $fid = $tid = 0;

            if ($mybb->get_input('tid', MyBB::INPUT_INT)) {
                $tid = $mybb->get_input('tid', MyBB::INPUT_INT);
                $thread_info = get_thread($tid);
                $fid = (int)$thread_info['fid'];

                $redirect_url = get_thread_link($tid);
            } else {
                $redirect_url = 'modcp.php?action=supportdenial';
            }

            $mod_log_action = '';

            isset($mySupportRedirectMessages) || $mySupportRedirectMessages = '';

            $mysupport_cache = $mybb->cache->read('mysupport');
            // -1 is if we're revoking and 0 is no reason, so those are exempt
            if (!array_key_exists(
                    $deniedsupportreason,
                    $mysupport_cache['deniedReasons']
                ) && $deniedsupportreason !== REVOKE_DENIED_SUPPORT && $deniedsupportreason) {
                error($lang->support_denial_reason_invalid_reason);
            } elseif ($deniedsupportreason === REVOKE_DENIED_SUPPORT) {
                $update = [
                    'deniedsupport' => 0,
                    'deniedsupportreason' => 0,
                    'deniedsupportuid' => 0
                ];

                userUpdate($update, $uid);

                $update = [
                    'closed' => 0,
                    'closedbymysupport' => 0
                ];

                $enabledForums = implode("','", enabledForums());

                foreach (
                    threadsGet(
                        ["uid='{$uid}'", "fid IN ('{$enabledForums}')", "closed='1'", "closedbymysupport='2'"],
                        ['tid']
                    ) as $threadID => $threadData
                ) {
                    threadUpdate($update, $threadID);
                }

                mod_log_action(
                    11,
                    $lang->sprintf($lang->deny_support_revoke_mod_log, $username)
                );

                redirect_message(
                    $lang->sprintf($lang->deny_support_revoke_success, htmlspecialchars_uni($username))
                );
            } else {
                $update = [
                    'deniedsupport' => 1,
                    'deniedsupportreason' => $deniedsupportreason,
                    'deniedsupportuid' => $currentUserID
                ];

                userUpdate($update, $uid);

                if (settingsGet('closewhendenied')) {
                    $update = [
                        'closed' => 1,
                        'closedbymysupport' => 2
                    ];

                    $enabledForums = implode("','", enabledForums());

                    foreach (
                        threadsGet(
                            ["uid='{$uid}'", "fid IN ('{$enabledForums}')", "closed='0'"],
                            ['tid']
                        ) as $threadID => $threadData
                    ) {
                        threadUpdate($update, $threadID);
                    }
                }

                if (!empty($mysupport_cache['deniedReasons'][$deniedsupportreason])) {
                    $deniedsupportreason = $mysupport_cache['deniedReasons'][$deniedsupportreason];

                    mod_log_action(
                        11,
                        $lang->sprintf($lang->deny_support_mod_log_reason, $username, $deniedsupportreason)
                    );
                } else {
                    mod_log_action(
                        11,
                        $lang->sprintf($lang->deny_support_mod_log, $username)
                    );
                }
                redirect_message(
                    $lang->sprintf($lang->deny_support_success, htmlspecialchars_uni($username))
                );
            }
            if (!empty($mod_log_action)) {
                $mod_log_data = [
                    'fid' => $fid,
                    'tid' => $tid
                ];
                log_moderator_action($mod_log_data, $mod_log_action);
            }
            redirect($redirect_url, $mySupportRedirectMessages);
        } elseif ($mybb->get_input('do') === 'denysupport') {
            if (!settingsGet('enablesupportdenial')) {
                error($lang->support_denial_not_enabled);
            }

            $uid = $mybb->get_input('uid', MyBB::INPUT_INT);
            $tid = $mybb->get_input('tid', MyBB::INPUT_INT);

            $user = get_user($uid);
            $username = $user['username'];
            $user_link = build_profile_link(htmlspecialchars_uni($username), intval($uid), 'blank');

            if ($mybb->get_input('uid', MyBB::INPUT_INT)) {
                $deny_support_to = $lang->sprintf($lang->deny_support_to, htmlspecialchars_uni($username));
            } else {
                $deny_support_to = $lang->deny_support_to_user;
            }

            add_breadcrumb($deny_support_to);

            $options = '';

            // if they've not been denied support yet or no reason was given, show an empty option that will be selected
            if ($user['deniedsupport'] == 0 || $user['deniedsupportreason'] == 0) {
                $value = 0;
                $text = $selected = '';

                $options .= eval(getTemplate('modcp_deniedreasons_option'));
            }

            $mysupport_cache = $mybb->cache->read('mysupport');
            if (!empty($mysupport_cache['deniedReasons'])) {
                // if there are one or more reasons set, show them in a dropdown
                foreach ($mysupport_cache['deniedReasons'] as $deniedreason) {
                    $value = (int)$deniedreason['mid'];

                    $text = htmlspecialchars_uni($deniedreason['name']);

                    $selected = '';

                    // if a reason has been given, we'd be editing it, so this would select the current one
                    if ($user['deniedsupport'] == 1 && $user['deniedsupportreason'] == $deniedreason['mid']) {
                        $selected = ' selected="selected"';
                    }

                    $options .= eval(getTemplate('modcp_deniedreasons_option'));
                }
            }

            $value = 0;

            $text = $lang->support_denial_reasons_none;

            $selected = '';

            $options .= eval(getTemplate('modcp_deniedreasons_option'));

            // if they've been denied support, give an option to revoke it
            if ($user['deniedsupport'] == 1) {
                $value = 0;

                $text = '-----';

                $selected = '';

                $options .= eval(getTemplate('modcp_deniedreasons_option'));

                $value = -1;

                $text = $lang->revoke;

                $selected = '';

                $options .= eval(getTemplate('modcp_deniedreasons_option'));
            }
            $deniedreasons = eval(getTemplate('modcp_deniedreasons'));

            $deny_support = eval(getTemplate('deny_support_deny'));
            $deny_support_page = eval(getTemplate('deny_support'));
            output_page($deny_support_page);
        } else {
            $url = 'modcp.php?action=supportdenial';

            $limit = (int)$mybb->settings['threadsperpage'];
            if ($mybb->get_input('limit', 1)) {
                $limit = $mybb->get_input('limit', 1);
                $url .= '&amp;limit=' . $limit;
            }
            $limit = $limit > 100 ? 100 : ($limit < 1 ? 1 : $limit);

            $userscount = (int)(usersGet(
                ["deniedsupport='1'"],
                ['COUNT(uid) AS users']
            )['users'] ?? 0);

            if ($mybb->get_input('page', 1) > 0) {
                $start = ($mybb->get_input('page', 1) - 1) * $limit;
                $pages = ceil($userscount / $limit);
                if ($mybb->get_input('page', 1) > $pages) {
                    $start = 0;
                    $mybb->input['page'] = 1;
                }
            } else {
                $start = 0;
                $mybb->input['page'] = 1;
            }

            $query = $db->simple_select(
                "users u
                LEFT JOIN {$db->table_prefix}mysupport m ON (u.deniedsupportreason = m.mid)
				LEFT JOIN  {$db->table_prefix}users u1 ON (u1.uid = u.uid)
				LEFT JOIN  {$db->table_prefix}users u2 ON (u2.uid = u.deniedsupportuid)",
                'u1.username AS support_denied_username, u1.uid AS support_denied_uid, u2.username AS support_denier_username, u2.uid AS support_denier_uid, m.name AS support_denied_reason',
                "u.deniedsupport='1'",
                ['order_by' => 'u1.username', 'order_dir' => 'asc', 'limit_start' => $start, 'limit' => $limit]
            );

            $multipage = (string)multipage($userscount, $limit, $mybb->get_input('page', 1), $url);

            if ($db->num_rows($query) > 0) {
                $denied_users = '';

                while ($denieduser = $db->fetch_array($query)) {
                    $bgcolor = alt_trow();

                    $support_denied_user = build_profile_link(
                        htmlspecialchars_uni($denieduser['support_denied_username']),
                        intval($denieduser['support_denied_uid'])
                    );
                    $support_denier_user = build_profile_link(
                        htmlspecialchars_uni($denieduser['support_denier_username']),
                        intval($denieduser['support_denier_uid'])
                    );
                    if (empty($denieduser['support_denied_reason'])) {
                        $support_denial_reason = $lang->support_denial_no_reason;
                    } else {
                        $support_denial_reason = $denieduser['support_denied_reason'];
                    }
                    $denied_users .= eval(getTemplate('deny_support_list_user'));
                }
            } else {
                $denied_users = eval(getTemplate('modcp_deniedusers'));
            }

            $deny_support = eval(getTemplate('deny_support_list'));
            $deny_support_page = eval(getTemplate('deny_support'));
            output_page($deny_support_page);
        }
    }
}

function modcp_nav(): void
{
    global $mybb, $lang;
    global $mySupportModeratorNavigationItems;

    languageLoad();

    $mySupportModeratorNavigationItems = '';

    // is the technical threads feature enabled?
    if (isTechnicalStatusEnabled()) {
        $url = (new Url('modcp.php'))->build(['action' => 'technicalthreads']);

        // we need to eval this template now to generate the nav row with the correct details in it
        $mySupportModeratorNavigationItems .= eval(getTemplate('moderation_panel_nav_technical'));
    }

    // is support denial enabled?
    if (settingsGet('enablesupportdenial')) {
        $url = (new Url('modcp.php'))->build(['action' => 'supportdenial']);

        // we need to eval this template now to generate the nav row with the correct details in it
        $mySupportModeratorNavigationItems .= eval(getTemplate('moderation_panel_nav_deny_support'));
    }
}

function usercp_menu_built(): void
{
    global $mybb, $lang;
    global $usercpnav;

    languageLoad();

    // need to check for private.php too, so it shows in the PM system - the usercp_menu_built hook is run after $mysupport_nav_option has been made so this will work for both
    $mySupportModeratorNavigationItems = [];

    // is the list of support threads enabled?
    if (settingsGet('threadlist')) {
        $url = (new Url('usercp.php'))->build(['action' => 'supportthreads']);

        // add to the code for the option
        $mySupportModeratorNavigationItems[] = eval(getTemplate('user_panel_nav_list'));
    }

    // is assigning threads enabled?
    if (settingsGet('enableassign') && !empty($mybb->usergroup['canbeassigned'])) {
        $url = (new Url('usercp.php'))->build(['action' => 'assignedthreads']);

        // add to the code for the option
        $mySupportModeratorNavigationItems[] = eval(getTemplate('user_panel_nav_assigned'));
    }

    if ($mySupportModeratorNavigationItems) {
        // if we added either or both of the nav options above, do a str_replace on the nav to display it
        // need to do a string replacement as the hook we're using here is after $usercpnav has been eval'd
        $usercpnav = str_replace(
            '<!--MySupportUserPanelNavigation-->',
            implode('', $mySupportModeratorNavigationItems),
            $usercpnav
        );
    }
}

// show a list of threads requiring technical attention, assigned threads, or support threads
function modcp_start20(): void
{
    return;
    global $mybb;
    global $lang, $theme, $forum, $headerinclude, $header, $footer, $usercpnav, $modcp_nav, $threads_list;

    languageLoad();

    $currentUserLastVisitTime = (int)$mybb->user['lastvisit'];

    // checks if we're in the Mod CP, technical threads are enabled, and we're viewing the technical threads list...
    // ... or we're in the User CP, the ability to view a list of support threads is enabled, and we're viewing that list
    if ((THIS_SCRIPT === 'modcp.php' &&
            isTechnicalStatusEnabled() &&
            $mybb->get_input('action') === 'technicalthreads') || (THIS_SCRIPT === 'usercp.php' && (
                (settingsGet('threadlist') && (
                        $mybb->get_input('action') === 'supportthreads' ||
                        !$mybb->get_input('action'))
                ) || (
                    settingsGet('enableassign') &&
                    $mybb->get_input('action') === 'assignedthreads')
            ))) {
        $currentUserID = (int)$mybb->user['uid'];

        // add to navigation
        if (THIS_SCRIPT === 'modcp.php') {
            add_breadcrumb($lang->nav_modcp, 'modcp.php');
            add_breadcrumb($lang->thread_list_title_tech, 'modcp.php?action=technicalthreads');
        } elseif (THIS_SCRIPT === 'usercp.php') {
            add_breadcrumb($lang->nav_usercp, 'usercp.php');
            if ($mybb->get_input('action') === 'assignedthreads') {
                add_breadcrumb($lang->thread_list_title_assign, 'usercp.php?action=assignedthreads');
            } elseif ($mybb->get_input('action') === 'supportthreads') {
                add_breadcrumb($lang->thread_list_title_solved, 'usercp.php?action=supportthreads');
            }
        }

        // generate the CSS classes
        global $headerinclude;

        $headerinclude .= generatePrioritiesStyleCode();

        // what forums is this allowed in?
        $mysupport_forums = implode("','", enabledForums());

        $whereClauses = ["fid IN ('{$mysupport_forums}')"];

        // if we have a forum in the URL, we're only dealing with threads in that forum
        // set some stuff for this forum that will be used in various places in this function
        if ($mybb->get_input('fid', MyBB::INPUT_INT)) {
            // https://github.com/JordanMussi/MySupport/commit/7c9dcbb0143d89fe61e3e553c2fc5997f7f0f6f1
            $fid = $mybb->get_input('fid', MyBB::INPUT_INT);
            $forumpermissions = forum_permissions();
            $fpermissions = $forumpermissions[$fid];

            if ($fpermissions['canview'] != 1) {
                error_no_permission();
            }

            $forum_info = get_forum($fid);
            $whereClauses[] = "fid='{$fid}'";
            // if we're viewing threads from a specific forum, add that to the nav too
            if (THIS_SCRIPT === 'modcp.php') {
                add_breadcrumb(
                    $lang->sprintf($lang->thread_list_heading_tech_forum, htmlspecialchars_uni($forum_info['name'])),
                    "modcp.php?action=technicalthreads&fid={$fid}"
                );
            } elseif (THIS_SCRIPT === 'usercp.php') {
                if ($mybb->get_input('action') == 'assignedthreads') {
                    add_breadcrumb(
                        $lang->sprintf(
                            $lang->thread_list_heading_assign_forum,
                            htmlspecialchars_uni($forum_info['name'])
                        ),
                        "usercp.php?action=supportthreads&fid={$fid}"
                    );
                } elseif ($mybb->get_input('action') == 'supportthreads') {
                    add_breadcrumb(
                        $lang->sprintf(
                            $lang->thread_list_heading_solved_forum,
                            htmlspecialchars_uni($forum_info['name'])
                        ),
                        "usercp.php?action=supportthreads&fid={$fid}"
                    );
                }
            }
        }

        if (settingsGet('stats')) {
            // only want to do this if we're viewing the list of support threads or technical threads
            if ((THIS_SCRIPT == 'usercp.php' && $mybb->get_input(
                        'action'
                    ) == 'supportthreads') || (THIS_SCRIPT == 'modcp.php' && $mybb->get_input(
                        'action'
                    ) == 'technicalthreads')) {
                // show a small stats section
                $threadObjects = [];

                if (THIS_SCRIPT == 'modcp.php') {
                    $threadObjects = threadsGet(
                        $whereClauses,
                        ['status'],
                    );
                } elseif (THIS_SCRIPT == 'usercp.php') {
                    $threadObjects = threadsGet(
                        array_merge(["uid='{$currentUserID}'"], $whereClauses),
                        ['status'],
                    );
                }
                if ($threadObjects) {
                    $solved_row = $notsolved_row = $technical_row = '';
                    $total_count = $solved_count = $notsolved_count = $technical_count = 0;
                    foreach ($threadObjects as $threads) {
                        switch ($threads['status']) {
                            case 2:
                                // we have a technical thread, count it
                                ++$technical_count;
                                break;
                            case 1:
                                // we have a solved thread, count it
                                ++$solved_count;
                                break;
                            // we have an unsolved thread, count it
                            default:
                                ++$notsolved_count;
                        }
                        // count the total
                        ++$total_count;
                    }
                    // if the total count is 0, set all the percentages to 0
                    // otherwise we'd get 'division by zero' errors as it would try to divide by zero, and dividing by zero would cause the universe to implode
                    if ($total_count == 0) {
                        $solved_percentage = $notsolved_percentage = $technical_percentage = 0;
                    } // work out the percentages, so we know how big to make each bar
                    else {
                        $solved_percentage = round(($solved_count / $total_count) * 100);
                        if ($solved_percentage > 0) {
                            $solved_row = eval(getTemplate('modcp_solved_row'));
                        }

                        $notsolved_percentage = round(($notsolved_count / $total_count) * 100);
                        if ($notsolved_percentage > 0) {
                            $notsolved_row = eval(getTemplate('modcp_notsolved_row'));
                        }

                        $technical_percentage = round(($technical_count / $total_count) * 100);
                        if ($technical_percentage > 0) {
                            $technical_row = eval(getTemplate('modcp_technical_row'));
                        }
                    }

                    // get the title for the stat table
                    if (THIS_SCRIPT == 'modcp.php') {
                        if (isset($forum_info['name'])) {
                            $title_text = $lang->sprintf(
                                $lang->thread_list_stats_overview_heading_tech_forum,
                                htmlspecialchars_uni($forum_info['name'])
                            );
                        } else {
                            $title_text = $lang->thread_list_stats_overview_heading_tech;
                        }
                    } elseif (THIS_SCRIPT == 'usercp.php') {
                        if (isset($forum_info['name'])) {
                            $title_text = $lang->sprintf(
                                $lang->thread_list_stats_overview_heading_solved_forum,
                                htmlspecialchars_uni($forum_info['name'])
                            );
                        } else {
                            $title_text = $lang->thread_list_stats_overview_heading_solved;
                        }
                    }

                    // fill out the counts of the statuses of threads
                    $overview_text = $lang->sprintf(
                        $lang->thread_list_stats_overview,
                        $total_count,
                        $solved_count,
                        $notsolved_count,
                        $technical_count
                    );

                    if (THIS_SCRIPT == 'usercp.php') {
                        $newthreads = (int)(threadsGet(
                            [
                                "lastpost>'{$currentUserLastVisitTime}'",
                                "statustime>'{$currentUserLastVisitTime}'",
                            ],
                            ['COUNT(tid) AS new_count'],
                            ['limit' => 1]
                        )['new_count'] ?? 0);

                        // there are 'new' support threads (reply or action since last visit) so show a link to give a list of just those
                        if ($newthreads != 0) {
                            $newthreads_text = $lang->sprintf($lang->thread_list_newthreads, intval($newthreads));
                            $newthreads = eval(getTemplate('modcp_newthreads'));
                        } else {
                            $newthreads = '';
                        }
                    }

                    $stats = eval(getTemplate('threadlist_stats'));
                }
            }
        }

        $threadObjects = [];

        // now get the relevant threads
        // the query for if we're in the Mod CP, getting all technical threads
        if (THIS_SCRIPT == 'modcp.php') {
            $threadObjects = threadsGet(
                array_merge(["status='2'"], $whereClauses),
                [
                    'tid',
                    'subject',
                    'fid',
                    'uid',
                    'username',
                    'lastpost',
                    'lastposter',
                    'lastposteruid',
                    'status',
                    'statusuid',
                    'statustime',
                    'priority',
                    'statustime',
                ],
                ['order_by' => 'lastpost', 'order_dir' => 'DESC']
            );
        } // the query for if we're in the User CP, getting all support threads
        elseif (THIS_SCRIPT == 'usercp.php') {
            $queryOptions = [];

            if ($mybb->get_input('action') == 'assignedthreads') {
                // viewing assigned threads
                $whereClauses[] = "assign='{$currentUserID}'";
            } elseif ($mybb->get_input('action') == 'supportthreads') {
                // viewing support threads
                $whereClauses[] = "uid='{$currentUserID}'";

                $whereClauses[] = "visible='1'";

                if ($mybb->get_input('do') == 'new') {
                    $whereClauses[] = "(lastpost>'{$currentUserLastVisitTime}' OR statustime>'{$currentUserLastVisitTime}')";
                }
            } else {
                $whereClauses[] = "uid='{$currentUserID}'";

                $whereClauses[] = "visible='1'";

                $queryOptions['limit'] = 5;
            }

            $threadObjects = threadsGet(
                $whereClauses,
                [
                    'tid',
                    'subject',
                    'fid',
                    'uid',
                    'username',
                    'lastpost',
                    'lastposter',
                    'lastposteruid',
                    'status',
                    'statusuid',
                    'statustime',
                    'priority',
                ],
                $queryOptions
            );
        }

        $threadcount = 0; // TODO: has pagination always been missing here ?

        // sort out multipage
        if (!$mybb->settings['postsperpage']) {
            $mybb->settings['postperpage'] = 20;
        }
        $perpage = $mybb->settings['postsperpage'];
        if ($mybb->get_input('page', MyBB::INPUT_INT) > 0) {
            $page = $mybb->get_input('page', MyBB::INPUT_INT);
            $start = ($page - 1) * $perpage;
            $pages = $threadcount / $perpage;
            $pages = ceil($pages);
            if ($page > $pages || $page <= 0) {
                $start = 0;
                $page = 1;
            }
        } else {
            $start = 0;
            $page = 1;
        }
        $end = $start + $perpage;
        $lower = $start + 1;
        $upper = $end;
        if ($upper > $threadcount) {
            $upper = $threadcount;
        }

        $threads = '';
        if (!$threadObjects) {
            $threads = eval(getTemplate('threadlist_empty'));
        } else {
            $bgcolor = alt_trow(true);

            foreach ($threadObjects as $threadID => $thread) {
                $forumName = htmlspecialchars_uni(get_forum($thread['fid'])['name']);

                $thread['mySupportPriorityClass'] = ' mySupportPriority_' . priorityClassGetName(
                        (int)$thread['priority']
                    );

                $thread['subject'] = htmlspecialchars_uni($thread['subject']);
                $thread['threadlink'] = get_thread_link($threadID);

                $forumUrl = get_forum_link($thread['fid']);

                $thread['profilelink'] = build_profile_link(
                    htmlspecialchars_uni($thread['username']),
                    intval($thread['uid'])
                );

                // if we're in the Mod CP, we only need the date and time it was marked technical, don't need the status on every line
                if (THIS_SCRIPT == 'modcp.php') {
                    if (settingsGet('relativetime')) {
                        $status_time = my_date('relative', $thread['statustime']);
                    } else {
                        $status_time = my_date('normal', $thread['statustime']);
                    }
                    // we're viewing technical threads, show who marked it as technical
                    $status_uid = intval($thread['statusuid']);
                    $status_user = get_user($status_uid);
                    $status_username = $status_user['username'];
                    $status_user_link = build_profile_link(htmlspecialchars_uni($status_username), intval($status_uid));
                    $status_time .= ', ' . $lang->sprintf($lang->mysupport_by, $status_user_link);

                    $view_all_forum_text = $lang->sprintf(
                        $lang->thread_list_link_tech,
                        htmlspecialchars_uni($thread['name'])
                    );
                    $view_all_forum_link = 'modcp.php?action=technicalthreads&amp;fid=' . intval($thread['fid']);
                } // if we're in the User CP, we want to get the status...
                elseif (THIS_SCRIPT == 'usercp.php') {
                    $status = threadSolvedFriendlyStatusGet(intval($thread['status']));

                    $class = match ($thread['status']) {
                        2 => 'technical',
                        1 => 'solved',
                        default => 'notsolved',
                    };

                    $status = eval(gettemplate('threadlist_thread_status'));

                    // ... but we only want to show the time if the status is something other than Not Solved...
                    if ($thread['status'] != 0) {
                        if (settingsGet('relativetime')) {
                            $status_time = $status . ' - ' . my_date('relative', $thread['statustime']);
                        } else {
                            $status_time = $status . ' - ' . my_date('normal', $thread['statustime']);
                        }
                    } // ... otherwise, if it is not solved, just show that
                    else {
                        $status_time = $status;
                    }
                    //if(!($mybb->get_input('action') == "supportthreads" && $thread['status'] == 0))
                    // we wouldn't want to do this if a thread was unsolved
                    if ((($mybb->get_input('action') == 'supportthreads' || !$mybb->get_input(
                                    'action'
                                )) && $thread['status'] != 0) || $mybb->get_input('action') == 'assignedthreads') {
                        if ($mybb->get_input('action') == 'supportthreads' || !$mybb->get_input('action')) {
                            // we're viewing support threads, show who marked it as solved or technical
                            $status_uid = intval($thread['statusuid']);
                            $by_lang = 'mysupport_by';
                        } else {
                            $assignData = assignGet(
                                [
                                    "thread_id='{$threadID}'",
                                    "user_id='{$currentUserID}'",
                                    "status='" . AssignStatus::Active . "'"
                                ],
                                ['assign_id', 'assigner_user_id']
                            );
                            // todo ? may be getting incorrect assign data

                            // we're viewing assigned threads, show who assigned this thread to you
                            $status_uid = (int)$assignData['assigner_user_id'];
                            $by_lang = 'mysupport_assigned_by';
                        }
                        if ($status_uid) {
                            $status_user = get_user($status_uid);
                            $status_user_link = build_profile_link(
                                htmlspecialchars_uni($status_user['username']),
                                $status_uid
                            );
                            $status_time .= ', ' . $lang->sprintf($lang->$by_lang, $status_user_link);
                        }
                    }

                    if ($mybb->get_input('action') == 'assignedthreads') {
                        $view_all_forum_text = $lang->sprintf(
                            $lang->thread_list_link_assign,
                            htmlspecialchars_uni($thread['name'])
                        );
                        $view_all_forum_link = 'usercp.php?action=assignedthreads&amp;fid=' . intval($thread['fid']);
                    } else {
                        $view_all_forum_text = $lang->sprintf(
                            $lang->thread_list_link_solved,
                            htmlspecialchars_uni($thread['name'])
                        );
                        $view_all_forum_link = 'usercp.php?action=supportthreads&amp;fid=' . intval($thread['fid']);
                    }
                }

                $thread['lastpostlink'] = get_thread_link($threadID, 0, 'lastpost');

                if (settingsGet('relativetime')) {
                    $lastpostdate = $lastposttime = my_date('relative', $thread['lastpost']);
                } else {
                    $lastpostdate = $lastposttime = my_date('normal', $thread['lastpost']);
                }

                $lastposterlink = build_profile_link(
                    htmlspecialchars_uni($thread['lastposter']),
                    intval($thread['lastposteruid'])
                );

                $threads .= eval(getTemplate('threadlist_thread'));

                $bgcolor = alt_trow();
            }
        }

        $view_all = '';

        // if we have a forum in the URL, add a table footer with a link to all the threads
        if (isset($forum_info['name']) || (THIS_SCRIPT == 'usercp.php' && !$mybb->get_input('action'))) {
            if (THIS_SCRIPT == 'modcp.php') {
                $thread_list_heading = $lang->sprintf(
                    $lang->thread_list_heading_tech_forum,
                    htmlspecialchars_uni($forum_info['name'])
                );
                $view_all = $lang->thread_list_view_all_tech;
                $view_all_url = 'modcp.php?action=technicalthreads';
            } elseif (THIS_SCRIPT == 'usercp.php') {
                if ($mybb->get_input('action') == 'assignedthreads') {
                    $thread_list_heading = $lang->sprintf(
                        $lang->thread_list_heading_assign_forum,
                        htmlspecialchars_uni($forum_info['name'])
                    );
                    $view_all = $lang->thread_list_view_all_assign;
                    $view_all_url = 'usercp.php?action=assignedthreads';
                } else {
                    if ($mybb->get_input('action') == 'supportthreads') {
                        $thread_list_heading = $lang->sprintf(
                            $lang->thread_list_heading_solved_forum,
                            htmlspecialchars_uni($forum_info['name'])
                        );
                    } elseif (!$mybb->get_input('action')) {
                        $thread_list_heading = $lang->thread_list_heading_solved_latest;
                    }
                    $view_all = $lang->thread_list_view_all_solved;
                    $view_all_url = 'usercp.php?action=supportthreads';
                }
            }
            $view_all = eval(getTemplate('threadlist_footer'));
        } // if there's no forum in the URL, just get the standard table heading
        elseif (THIS_SCRIPT == 'modcp.php') {
            $thread_list_heading = $lang->thread_list_heading_tech;
        } elseif (THIS_SCRIPT == 'usercp.php') {
            if ($mybb->get_input('action') == 'assignedthreads') {
                $thread_list_heading = $lang->thread_list_heading_assign;
            } elseif ($mybb->get_input('do') == 'new') {
                $thread_list_heading = $lang->thread_list_heading_solved_new;
            } else {
                $thread_list_heading = $lang->thread_list_heading_solved;
            }
        }

        //get the page title, heading for the status of the thread column, and the relevant sidebar navigation
        if (THIS_SCRIPT == 'modcp.php') {
            $thread_list_title = $lang->thread_list_title_tech;
            $status_heading = $lang->thread_list_time_tech;
            $navigation = "$modcp_nav";
        } elseif (THIS_SCRIPT == 'usercp.php') {
            if ($mybb->get_input('action') == 'assignedthreads') {
                $thread_list_title = $lang->thread_list_title_assign;
                $status_heading = $lang->thread_list_time_solved;
            } else {
                $thread_list_title = $lang->thread_list_title_solved;
                $status_heading = $lang->thread_list_time_assign;
            }
            $navigation = "$usercpnav";
        }

        $action = htmlspecialchars_uni($mybb->get_input('action'));

        $threadlist_filter_form = eval(getTemplate('modcp_threadlist_filter_form'));

        $threads_list = eval(getTemplate('threadlist_list'));
        // we only want to output the page if we've got an action; i.e., we're not viewing the list on the User CP home page
        if ($mybb->get_input('action')) {
            $threads_page = eval(getTemplate('threadlist'));
            output_page($threads_page);
        }
    }
}

function usercp_start(): void
{
    modcp_start20();
}

// perform inline thread moderation on multiple threads
function moderation_start(): void
{
    global $mybb, $lang;

    $threadID = $mybb->get_input('tid', MyBB::INPUT_INT);

    $forumID = $mybb->get_input('fid', MyBB::INPUT_INT);

    if ($forumID) {
        $redirectUrl = get_forum_link($forumID);
    }

    $currentUserID = (int)$mybb->user['uid'];

    if (!$currentUserID) {
        return;
    }

    $threadIDs = [];

    if ($threadID) {
        $threadData = get_thread($threadID);

        if (!$threadData) {
            error($lang->error_invalidthread, $lang->error);
        }

        $forumID = (int)$threadData['fid'];

        $threadIDs[] = $threadID;

        $redirectUrl = get_thread_link($threadID);
    }

    $totalThreads = count($threadIDs);

    $forumIDs = [$forumID];

    if ($totalThreads > 1) {
        (function () use ($threadIDs, &$forumIDs): void {
            $threadIDs = implode("','", $threadIDs);

            foreach (
                threadsGet(
                    [
                        "tid IN ('{$threadIDs}')",
                    ],
                    ['fid'],
                ) as $threadData
            ) {
                $forumIDs[] = (int)$threadData['fid'];
            }
        })();
    }

    //_dump($forumIDs);

    $moderationLogData = [];

    if ($forumID) {
        $moderationLogData['fid'] = $forumID;

        $forumData = get_forum($forumID);

        build_forum_breadcrumb($forumID);

        $permissions = forum_permissions($forumID);
        //$permissions['mySupportCanMarkBestAnswer'] // todo,
    }

    $multiModeration = true;

    if (isset($threadData)) {
        global $parser;

        $threadData['subject'] = htmlspecialchars_uni($parser->parse_badwords($threadData['subject']));

        add_breadcrumb($threadData['subject'], get_thread_link($threadData['tid']));

        $moderationLogData['tid'] = $threadData['tid'];

        $multiModeration = false;
    }

    if (isset($forumData)) {
        check_forum_password($forumData['fid']);
    }

    $log_multithreads_actions = array(
        'do_multideletethreads',
        'multiclosethreads',
        'multiopenthreads',
        'multiapprovethreads',
        'multiunapprovethreads',
        'multirestorethreads',
        'multisoftdeletethreads',
        'multistickthreads',
        'multiunstickthreads',
        'do_multimovethreads'
    );

    if (!$threadIDs && (
            in_array($mybb->get_input('action'), $log_multithreads_actions) ||
            str_starts_with($mybb->get_input('action'), 'mysupport_priority_') ||
            str_starts_with($mybb->get_input('action'), 'mysupport_category_')
        )) {
        if (!empty($mybb->input['searchid'])) {
            $threadIDs = getids($mybb->get_input('searchid'), 'search');

            $redirectUrl = 'search.php?action=results&sid=' . rawurlencode($mybb->get_input('searchid'));

            $clearInlineID = $mybb->get_input('searchid');
        } else {
            $threadIDs = getids($forumID, 'forum');

            $redirectUrl = get_forum_link($forumID);

            $clearInlineID = $forumID;
        }

        $moderationLogData['tids'] = (array)$threadIDs;
    }

    $mybb->user['username'] = htmlspecialchars_uni($mybb->user['username']);

    $allowable_moderation_actions = array(
        'getip',
        'getpmip',
        'cancel_delayedmoderation',
        'delayedmoderation',
        'threadnotes',
        'purgespammer',
        'viewthreadnotes'
    );

    if (empty($redirectUrl) || (
            $mybb->request_method !== 'post' && (
                !in_array($mybb->get_input('action'), $allowable_moderation_actions) ||
                str_starts_with($mybb->get_input('action'), 'mysupport_priority_') ||
                str_starts_with($mybb->get_input('action'), 'mysupport_category_')
            )
        )) {
        return;
    }

    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///

    if (in_array(
        $mybb->get_input('action'),
        [
            'mysupport_is_support',
            'mysupport_is_not_support',
            'mysupport_solve',
            'mysupport_not_solved',
            'mysupport_technical',
            'mysupport_not_technical',
            'mysupport_onhold',
            'mysupport_not_onhold'
        ]
    )) {
        verify_post_check($mybb->get_input('my_post_key'));

        if ((
            empty($forumData['mysupport']) ||
            (
                $threadData !== null &&
                empty($threadData['issupportthread']) &&
                $mybb->get_input('action') !== 'mysupport_is_support'
            )/* ||
            !is_moderator($forumID, 'canmanagethreads')*/
        )) {
            error_no_permission();
        }
    }

    global $lang;

    languageLoad();

    $threadStatus = (int)($threadData['status'] ?? 0);

    $threadStatusOnhold = (int)($threadData['onhold'] ?? 0);

    if (isset($threadData) && $mybb->get_input('action') === 'mysupport_is_support') {
        if (!is_moderator($forumID, 'canmarksolved') ||
            !empty($threadData['issupportthread']) ||
            $threadStatusOnhold === ThreadStatus::Onhold) {
            error_no_permission();
        }

        moderationToolUpdateSupportStatus($threadID, ThreadStatus::IsSupport);

        log_moderator_action($moderationLogData, 'mysupport_is_support');

        redirect($redirectUrl, $lang->mySupportModerationThreadIsSupport);
    }

    if (isset($threadData) && $mybb->get_input('action') === 'mysupport_is_not_support') {
        if (!is_moderator($forumID, 'canmarksolved') ||
            empty($threadData['issupportthread']) ||
            $threadStatusOnhold === ThreadStatus::Onhold) {
            error_no_permission();
        }

        moderationToolUpdateSupportStatus($threadID, ThreadStatus::IsNotSupport);

        log_moderator_action($moderationLogData, 'mysupport_is_not_support');

        redirect($redirectUrl, $lang->mySupportModerationThreadIsNotSupport);
    }

    if (isset($threadData) && $mybb->get_input('action') === 'mysupport_solve') {
        if (!is_moderator($forumID, 'canmarksolved') ||
            $threadStatus === ThreadStatus::Solved ||
            $threadStatusOnhold === ThreadStatus::Onhold) {
            error($lang->no_permission_mark_solved_multi);
        }

        moderationToolUpdateSolveStatus($threadIDs, ThreadStatus::Solved);

        log_moderator_action($moderationLogData, 'mysupport_solve');

        redirect($redirectUrl, $lang->mySupportModerationThreadSolved);
    }

    if (isset($threadData) && $mybb->get_input('action') === 'mysupport_not_solved') {
        if (!is_moderator($forumID, 'canmarksolved') ||
            $threadStatus === ThreadStatus::NotSolved ||
            $threadStatusOnhold === ThreadStatus::Onhold) {
            error($lang->no_permission_mark_notsolved_multi);
        }

        moderationToolUpdateSolveStatus($threadIDs, ThreadStatus::NotSolved);

        log_moderator_action($moderationLogData, 'mysupport_not_solved');

        redirect($redirectUrl, $lang->mySupportModerationThreadNotSolved);
    }

    $threadTechnicalStatus = (int)($threadData['mysupport_is_technical'] ?? 0);

    if (isset($threadData) &&
        settingsGet('enabletechnical') &&
        $mybb->get_input('action') === 'mysupport_technical') {
        if (!is_moderator($forumID, 'canmarktechnical') ||
            $threadTechnicalStatus === ThreadStatus::Technical ||
            $threadStatusOnhold === ThreadStatus::Onhold) {
            error($lang->no_permission_mark_technical_multi);
        }

        moderationToolUpdateTechnicalStatus($threadIDs, ThreadStatus::Technical);

        log_moderator_action($moderationLogData, 'mysupport_technical');

        redirect($redirectUrl, $lang->mySupportModerationThreadNotSolved);
    }

    if (isset($threadData) &&
        settingsGet('enabletechnical') &&
        $mybb->get_input('action') === 'mysupport_not_technical') {
        if (!is_moderator($forumID, 'canmarktechnical') ||
            $threadTechnicalStatus === ThreadStatus::NotTechnical ||
            $threadStatusOnhold === ThreadStatus::Onhold) {
            error($lang->no_permission_mark_nottechnical_multi);
        }

        moderationToolUpdateTechnicalStatus($threadIDs, ThreadStatus::NotTechnical);

        log_moderator_action($moderationLogData, 'mysupport_not_technical');

        redirect($redirectUrl, $lang->mySupportModerationThreadNotSolved);
    }

    if (isset($threadData) &&
        settingsGet('enableonhold') &&
        $mybb->get_input('action') === 'mysupport_onhold') {
        if (!is_moderator($forumID, 'canmarkonhold') ||
            $threadStatusOnhold === ThreadStatus::Onhold) {
            error_no_permission();
        }

        moderationToolUpdateOnholdStatus($threadIDs, ThreadStatus::Onhold);

        log_moderator_action($moderationLogData, 'mysupport_onhold');

        redirect($redirectUrl, $lang->mySupportModerationThreadOnhold);
    }

    if (isset($threadData) &&
        settingsGet('enableonhold') &&
        $mybb->get_input('action') === 'mysupport_not_onhold') {
        if (!is_moderator($forumID, 'canmarkonhold') ||
            $threadStatusOnhold === ThreadStatus::NotOnhold) {
            error_no_permission();
        }

        moderationToolUpdateOnholdStatus($threadIDs, ThreadStatus::NotOnhold);

        log_moderator_action($moderationLogData, 'mysupport_not_onhold');

        redirect($redirectUrl, $lang->mySupportModerationThreadNotOnhold);
    }


    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///


    // we're hooking into the start of moderation.php, so if we're not submitting a MySupport action, exit now
    if (!str_contains($mybb->get_input('action'), 'mysupport')) {
        return;
    }

    verify_post_check($mybb->get_input('my_post_key'));

    global $cache, $lang, $mod_log_action, $mySupportRedirectMessages;

    if (!is_moderator($forumID, 'canmanagethreads')) {
        error_no_permission();
    }

    if ($totalThreads < 1) {
        error($lang->error_inline_nothreadsselected);
    }

    // todo
    #clearinline($clearInlineID, !empty($mybb->input['searchid']) ? 'search' : 'forum');

    $mysupport_threads = [];
    // In a list of search results, you could see threads that aren't from a MySupport forum. However, the MySupport options will always show in the inline moderation options regardless of this
    //  is a way of determining which of the selected threads from a list of search results are in a MySupport forum
    // this isn't necessary for inline moderation via the forum display, as the options only show in MySupport forums to begin with
    if (!empty($mybb->input['searchid'])) {
        // the list of MySupport forums
        // queries all the threads that are in the list of TIDs and where the FID is also in the list of MySupport forums, and where the thread is set to be a support thread
        // this will knock out the non-MySupport threads
        $mysupport_forums = implode("','", enabledForums());

        (function () use ($mysupport_forums, $threadIDs, &$mysupport_threads) {
            $threadIDs = implode("','", array_map('intval', $threadIDs));

            foreach (
                threadsGet(
                    [
                        "fid IN ('{$mysupport_forums}')",
                        "tid IN ('{$threadIDs}')",
                        "issupportthread='1'",
                    ],
                    ['tid'],
                ) as $threadID => $threadData
            ) {
                $mysupport_threads[] = $threadID;
            }
        })();

        $threadIDs = $mysupport_threads;
        // if the new list of threads is empty, no MySupport threads have been selected
        if ($totalThreads < 1) {
            error($lang->no_mysupport_threads_selected);
        }
    } // make sure we only have threads that are set to be support threads
    elseif (empty($mybb->input['searchid'])) {
        (function () use ($threadIDs, &$mysupport_threads) {
            $threadIDs = implode("','", array_map('intval', $threadIDs));

            foreach (
                threadsGet(
                    [
                        "tid IN ('{$threadIDs}')",
                        "issupportthread='1'",
                    ],
                    ['tid'],
                ) as $threadID => $threadData
            ) {
                $mysupport_threads[] = $threadID;
            }
        })();

        $threadIDs = $mysupport_threads;
        // if the new list of threads is empty, no MySupport threads have been selected
        if ($totalThreads < 1) {
            error($lang->no_mysupport_threads_selected);
        }
    }

    $mod_log_action = '';

    isset($mySupportRedirectMessages) || $mySupportRedirectMessages = '';

    if (str_contains($mybb->get_input('action'), 'onhold')) {
        $hold = str_replace('mysupport_onhold_', '', $mybb->get_input('action'));

        if (!$mybb->usergroup['canmarksolved']) {
            error($lang->no_permission_thread_hold_multi);
        }

        threadOnholdStatusUpdate($threadIDs, $hold, true);
    } elseif (str_contains($mybb->get_input('action'), 'assign')) {
        if (!settingsGet('enableassign') || is_moderator($forumID, 'canassign')) {
            error($lang->assign_no_perms);
        }

        $assignUserID = (int)str_replace('mysupport_assign_', '', $mybb->get_input('action'));

        if (!$assignUserID) {
            // in the function to change the assigned user, -1 means removing; 0 is easier to put into the form than -1, so change it back here
            $assignUserID = -1;
        } else {
            $assign_users = get_assign_users();

            // -1 is what's used to unassign a thread, so we need to exclude that
            if (!array_key_exists($assignUserID, $assign_users)) {
                error($lang->assign_invalid);
            }
        }

        $oldAssigneeName = '';

        if (!empty($threadData['assign'])) {
            $oldAssigneeName = get_user($threadData['assign'])['username'] ?? $lang->na;
        }

        $newAssigneeName = get_user($assignUserID)['username'];

        (function () use ($threadIDs, $assignUserID): void {
            $threadIDs = implode("','", $threadIDs);

            // when assigning via the form in a thread, you can't assign a thread if it's solved
            foreach (
                threadsGet(
                    ["tid IN ('{$threadIDs}')", "assign!='{$assignUserID}'", "onhold='0'", "status!='1'"],
                    ['tid', 'assign']
                ) as $threadID => $threadData
            ) {
                threadAssignmentUpdate($threadID, $assignUserID);

                usersUpdateAssignCount((int)$threadData['assign']);
            }
        })();

        usersUpdateAssignCount($assignUserID);

        if ($assignUserID === -1) {
            if ($multiModeration) {
                mod_log_action(6, $lang->sprintf($lang->unassigned_from_success_multi, $totalThreads));

                redirect_message($lang->sprintf($lang->unassigned_from_success_multi, $totalThreads));
            } else {
                mod_log_action(6, $lang->sprintf($lang->unassigned_from_success, $oldAssigneeName));

                redirect_message(
                    $lang->sprintf($lang->unassigned_from_success, htmlspecialchars_uni($oldAssigneeName))
                );
            }
        } elseif ($multiModeration) {
            mod_log_action(
                5,
                $lang->sprintf($lang->assigned_to_success_multi, $totalThreads, $newAssigneeName)
            );

            redirect_message(
                $lang->sprintf(
                    $lang->assigned_to_success_multi,
                    $totalThreads,
                    htmlspecialchars_uni($newAssigneeName)
                )
            );
        } else {
            mod_log_action(5, $lang->sprintf($lang->assigned_to_success, $newAssigneeName));

            redirect_message(
                $lang->sprintf($lang->assigned_to_success, htmlspecialchars_uni($newAssigneeName))
            );
        }
    } elseif (str_starts_with($mybb->get_input('action'), 'mysupport_priority_')) {
        if (!settingsGet('enablepriorities') || is_moderator($forumID, 'cansetpriorities')) {
            error($lang->priority_no_perms);
        }

        if ($threadStatusOnhold === ThreadStatus::Onhold) {
            error_no_permission();
        }

        $priorityID = (int)str_replace('mysupport_priority_', '', $mybb->get_input('action'));

        $prioritiesCache = $mybb->cache->read('mysupport');

        if (!$priorityID) {
            // in the function to change the priority, -1 means removing; 0 is easier to put into the form than -1, so change it back here
            $priorityID = -1;
        } elseif (empty($prioritiesCache['priorities']) || empty($prioritiesCache['priorities'][$priorityID])) {
            error($lang->priority_invalid);
        }

        $oldPriorityName = '';

        if (!empty($threadData['priority'])) {
            $oldPriorityName = $prioritiesCache['priorities'][(int)$threadData['priority']]['name'];
        }

        $newPriorityName = $prioritiesCache['priorities'][$priorityID]['name'];

        (function () use ($threadIDs, $priorityID): void {
            $threadIDs = implode("','", $threadIDs);

            foreach (
                threadsGet(
                    ["tid IN ('{$threadIDs}')", "priority!='{$priorityID}'", "onhold='0'"],
                ) as $threadID => $threadData
            ) {
                threadPriorityUpdate($threadID, $priorityID);
            }
        })();

        if ($priorityID === -1) {
            if ($multiModeration) {
                mod_log_action(8, $lang->sprintf($lang->priority_remove_success_multi, $totalThreads));

                redirect_message($lang->sprintf($lang->priority_remove_success_multi, $totalThreads));
            } else {
                mod_log_action(8, $lang->sprintf($lang->priority_remove_success, $oldPriorityName));

                redirect_message(
                    $lang->sprintf($lang->priority_remove_success, htmlspecialchars_uni($oldPriorityName))
                );
            }
        } elseif ($multiModeration) {
            mod_log_action(
                7,
                $lang->sprintf($lang->priority_change_success_to_multi, $totalThreads, $newPriorityName)
            );

            redirect_message(
                $lang->sprintf($lang->priority_change_success_to_multi, $totalThreads, $newPriorityName)
            );
        } else {
            mod_log_action(7, $lang->sprintf($lang->priority_change_success_to, $newPriorityName));

            redirect_message(
                $lang->sprintf($lang->priority_change_success_to, htmlspecialchars_uni($newPriorityName))
            );
        }
    } elseif (str_starts_with($mybb->get_input('action'), 'mysupport_category_')) {
        if ($threadStatusOnhold === ThreadStatus::Onhold) {
            error_no_permission();
        }

        $categoryID = (int)str_replace('mysupport_category_', '', $mybb->get_input('action'));

        $categoriesCache = cacheGetCategories($forumID);

        if (!$categoryID) {
            // in the function to change the category, -1 means removing; 0 is easier to put into the form than -1, so change it back here
            $categoryID = -1;
        } elseif (empty($categoriesCache) ||
            empty($categoriesCache[$categoryID])) {
            error($lang->category_invalid);
        }

        $oldCategoryName = '';

        if (!empty($threadData['mysupport_category_id'])) {
            $oldCategoryName = $categoriesCache[(int)$threadData['mysupport_category_id']]['name'];
        }

        $newCategoryName = $categoriesCache[$categoryID];

        (function () use ($threadIDs, $categoryID): void {
            $threadIDs = implode("','", $threadIDs);

            foreach (
                threadsGet(
                    ["tid IN ('{$threadIDs}')", "mysupport_category_id!='{$categoryID}'", "onhold='0'"],
                ) as $threadID => $threadData
            ) {
                threadCategoryUpdate($threadID, $categoryID);
            }
        })();

        if ($categoryID === -1) {
            if ($multiModeration) {
                mod_log_action(10, $lang->sprintf($lang->category_remove_success_multi, $totalThreads));

                redirect_message($lang->sprintf($lang->category_remove_success_multi, $totalThreads));
            } else {
                mod_log_action(10, $lang->sprintf($lang->category_remove_success, $oldCategoryName));

                redirect_message($lang->category_remove_success);
            }
        } elseif ($multiModeration) {
            mod_log_action(
                9,
                $lang->sprintf($lang->category_change_success_to_multi, $totalThreads, $newCategoryName)
            );

            redirect_message(
                $lang->sprintf(
                    $lang->category_change_success_to_multi,
                    $totalThreads,
                    htmlspecialchars_uni($newCategoryName)
                )
            );
        } else {
            mod_log_action(9, $lang->sprintf($lang->category_change_success_to, $newCategoryName));

            redirect_message(
                $lang->sprintf($lang->category_change_success_to, htmlspecialchars_uni($newCategoryName))
            );
        }
    }

    log_moderator_action($moderationLogData, $mod_log_action);

    redirect($mybb->settings['bburl'] . '/' . $redirectUrl, $mySupportRedirectMessages);
}

// show a message if someone is going to bump a thread that is solved and isn't their thread
function newreply_end(): void
{
    global $mybb, $templates, $lang;
    global $thread, $forum;
    global $moderation_notice, $quickreply;

    if (empty($forum['mysupport']) || !settingsGet('bumpnotice')) {
        return;
    }

    $currentUserID = (int)$mybb->user['uid'];

    $threadUserID = (int)$thread['uid'];

    if ((int)$thread['status'] === ThreadStatus::Solved &&
        $threadUserID !== $currentUserID &&
        empty($mybb->usergroup['canmarksolved']) &&
        is_moderator($forum['fid'])) {
        $moderation_text = $lang->mysupport_solved_bump_message;

        $moderation_notice = eval($templates->render('global_moderation_notice'));
    }

    if (!empty($quickreply) && str_contains($quickreply, '<!--MySupportQuickReplyModerationNotice-->')) {
        $quickreply = str_replace('<!--MySupportQuickReplyModerationNotice-->', $moderation_notice, $quickreply);
    }
}

function showthread_start(): void
{
    global $thread;

    usercp_thread_subscriptions_thread([$thread['tid']]);

    usercp_thread_subscriptions_thread10(true);
}

function showthread_start20(): void
{
    // generate the CSS classes
    global $headerinclude;

    $headerinclude .= generatePrioritiesStyleCode();
}

function usercp_end(): void
{
    showthread_start20();
}

function usercp_thread_subscriptions_thread($threadIDs = null): void
{
    global $db;
    global $subscriptions;
    global $mySupportBestAnswerCache;

    if (!is_array($threadIDs)) {
        $threadIDs = array_column($subscriptions, 'tid');
    }

    /*$threadIDs = array_filter(
        array_map(
            'intval',
            $hookArguments['tids'] ?? array_column($thread_cache ?? ($subscriptions ?? []), 'tid')
        )
    );*/

    $threadIDs = array_filter(
        array_map(
            'intval',
            $threadIDs ?? array_column($subscriptions ?? [], 'tid')
        )
    );

    if (empty($threadIDs)) {
        return;
    }

    $mySupportBestAnswerCache = [];

    $whereClauses = ["thread.tid IN ('" . implode(',', $threadIDs) . "')", "thread.bestanswer!='0'"];

    $query = $db->simple_select(
        "threads thread LEFT JOIN {$db->table_prefix}posts post ON (post.pid=thread.bestanswer)",
        'post.pid, post.visible',
        implode(' AND ', $whereClauses),
    );

    while ($threadData = $db->fetch_array($query)) {
        $mySupportBestAnswerCache[(int)$threadData['pid']] = $threadData;
    }
}

function usercp_thread_subscriptions_thread10($replaceNavigationItem = false): void
{
    global $mybb, $lang;
    global $thread;

    languageLoad();

    $currentUserID = (int)$mybb->user['uid'];

    $forumID = (int)$thread['fid'];

    $threadID = (int)$thread['tid'];

    $thread['mySupportStatus'] = $thread['mySupportPriorityClass'] = $thread['mySupportAssignedNotice'] = $thread['mySupportBestAnswerNotice'] = $thread['mySupportCategory'] = $thread['mySupportCategoryFormatted'] = '';

    if (empty($thread['issupportthread'])) {
        return;
    }

    $thread['mySupportStatus'] = displayStatusGet($threadID);

    $priorityID = (int)$thread['priority'];

    if ($priorityID && $thread['visible'] == 1) {
        $thread['mySupportPriorityClass'] = ' mySupportPriority_' . priorityClassGetName($priorityID) . ' ';
    }

    global $inline_mod_checkbox;

    if (!empty($inline_mod_checkbox) && str_contains($inline_mod_checkbox, '<!--MySupportPriorityClass-->')) {
        $inline_mod_checkbox = str_replace(
            '<!--MySupportPriorityClass-->',
            $thread['mySupportPriorityClass'],
            $inline_mod_checkbox
        );
    }

    if (!empty($inline_mod_checkbox) && str_contains($inline_mod_checkbox, '<!--MySupportPriorityClass-->')) {
        $inline_mod_checkbox = str_replace(
            '<!--MySupportPriorityClass-->',
            '',
            $inline_mod_checkbox
        );
    }

    $threadAssignUserID = (int)$thread['assign'];

    $imageUrl = $mybb->get_asset_url('images/mysupport_assigned.png');

    if ($threadAssignUserID && $threadAssignUserID === $currentUserID) {
        $url = (new Url('usercp.php'))->build([
            'action' => 'assignedthreads',
            'fid' => $forumID,
        ]);

        $thread['mySupportAssignedNotice'] = eval(getTemplate('assigned_to_you'));
    } elseif ($threadAssignUserID) {
        $thread['mySupportAssignedNotice'] = eval(getTemplate('assigned'));
    }

    global $mySupportBestAnswerCache;

    if (!empty($thread['bestanswer']) &&
        !empty($mySupportBestAnswerCache[$thread['bestanswer']]) &&
        !((int)$mySupportBestAnswerCache[$thread['bestanswer']]['visible'] !== 1 && !is_moderator($forumID))) {
        $postID = (int)$thread['bestanswer'];

        $imageUrl = $mybb->get_asset_url('images/mysupport_bestanswer.png');

        $url = get_post_link($postID, $threadID) . '#pid' . $postID;

        $thread['mySupportBestAnswerNotice'] = eval(getTemplate('forum_jump_to_best_answer'));
    }

    if (!empty($thread['mysupport_category_id'])) {
        $categoryID = (int)$thread['mysupport_category_id'];

        $categoriesCache = $mybb->cache->read('mysupport')['categories'];

        if (!empty($categoriesCache[$categoryID]['name'])) {
            $thread['mySupportCategory'] = htmlspecialchars_uni($categoriesCache[$categoryID]['name']) . '&nbsp;';

            $thread['mySupportCategoryFormatted'] = ($categoriesCache[$categoryID]['display_style'] ?? $categoriesCache[$categoryID]['name']) . '&nbsp;';

            if ($replaceNavigationItem === true) {
                global $navbits;

                array_pop($navbits);

                add_breadcrumb(
                    $thread['mySupportCategoryFormatted'] . $thread['displayprefix'] . $thread['subject'],
                    get_thread_link($threadID)
                );
            }
        }
    }
}

function usercp_latest_threads_thread(): void
{
    static $done;

    if (empty($done)) {
        $done = true;

        global $threadcache;

        usercp_thread_subscriptions_thread(array_column($threadcache, 'tid'));
    }
}

function usercp_latest_threads_thread10(): void
{
    usercp_thread_subscriptions_thread10();
}

function newthread_do_newthread_end(): void
{
    global $thread_info, $forum;

    if (empty($forum['mysupport']) || (int)settingsGet('enablenotsupportthread') !== 2) {
        return;
    }

    threadUpdate(['issupportthread' => 0], (int)$thread_info['tid']);
}

// check if a user is denied support when they're trying to make a new thread
function newthread_start(): void
{
    global $mybb;
    global $lang, $forum;

    // this is a MySupport forum and this user has been denied support
    if ($forum['mysupport'] && $forum['mysupportdenial'] == 1 && $mybb->user['deniedsupport'] == 1) {
        // start the standard error message to show
        $deniedsupport_message = $lang->deniedsupport;
        // if a reason has been set for this user
        $userDeniedReasonID = (int)$mybb->user['deniedsupportreason'];

        if ($userDeniedReasonID) {
            $deniedsupportreason = deniedReasonGet(
                ["mid='{$userDeniedReasonID}'"],
                ['name', 'description'],
                ['limit' => 1]
            );

            $deniedsupport_message .= '<br /><br />' . $lang->sprintf(
                    $lang->deniedsupport_reason,
                    htmlspecialchars_uni($deniedsupportreason['name'])
                );
            if ($deniedsupportreason['description'] !== '') {
                $deniedsupport_message .= '<br />' . $lang->sprintf(
                        $lang->deniedsupport_reason_extra,
                        htmlspecialchars_uni($deniedsupportreason['description'])
                    );
            }
        }
        error($deniedsupport_message);
    }
}

function newthread_end(): void
{
    global $mybb;
    global $forum, $message;

    if ($mybb->request_method !== 'get' || empty($forum['mysupport']) || !empty($message)) {
        return;
    }

    $message = htmlspecialchars_uni($forum['mysupport_message_placeholder']);
}

// highlight the best answer from the thread and show the status of the thread in each post
function postbit(array &$post): array
{
    global $mybb;
    global $lang, $theme, $thread, $forum;

    languageLoad();

    $post['mySupportBestAnswer'] = $post['mySupportBestAnswerHighlight'] = $post['mySupportStaffHighlight'] = $post['mySupportQuickButtons'] = $post['mySupportPriorityClass'] = $post['mySupportDenySupport'] = '';

    if (empty($forum['mysupport'])) {
        return $post;
    }

    $forumID = (int)$post['fid'];

    $threadID = (int)$thread['tid'];

    $threadFirstPostID = (int)$thread['firstpost'];

    $postID = (int)$post['pid'];

    $threadUserID = (int)$thread['uid'];

    $bestAnswerPostID = (int)$thread['bestanswer'];

    $isFirstPost = $threadFirstPostID === $postID;

    if ($post['visible'] == 1 && $forum['allowbestanswerstatus']) {
        if ($bestAnswerPostID === $postID) {
            $post['mySupportBestAnswerHighlight'] = ' mySupportBestAnswerHighlight ';
        }

        $currentUserID = (int)$mybb->user['uid'];

        if ((!$isFirstPost || settingsGet('bestAnswerAllowFirstPost')) && (
                ($currentUserID === $threadUserID && $mybb->usergroup['canmarkbestanswer']) ||
                is_moderator($forum['fid'], 'canmarkbestanswer')
            )) {
            if ($bestAnswerPostID === $postID) {
                $imageUrl = $mybb->get_asset_url('images/mysupport_bestanswer.png');

                $imageAlt = $lang->unbestanswer_img_alt;

                $imageTitle = $lang->unbestanswer_img_title;

                $description = $lang->unbestanswer_img_alt;
            } else {
                $imageUrl = $mybb->get_asset_url('images/mysupport_unbestanswer.png');

                $imageAlt = $lang->bestanswer_img_alt;

                $imageTitle = $lang->bestanswer_img_title;

                $description = $lang->bestanswer_img_alt;
            }

            $url = (new Url(get_thread_link($thread['tid'], 0, 'best_answer')))->build([
                'pid' => $postID,
                'my_post_key' => $mybb->post_code
            ]);

            $post['mySupportBestAnswer'] = eval(getTemplate('post_best_answer'));
        }
    }

    // we only want to do this if it's not been highlighted as the best answer; that takes priority over this
    if ($post['visible'] == 1 && empty($post['mySupportBestAnswerHighlight'])) {
        if (settingsGet('highlightstaffposts')) {
            $userPermissions = user_permissions($post['uid']);

            // various checks to see if they should be considered staff or not
            if ($userPermissions['canmarksolved'] || is_moderator($forum['fid'], '', $post['uid'])) {
                //$post['mySupportStaffHighlight'] = ' mySupportStaffHighlight ';
            }
        }
    }

    if (settingsGet('enablesupportdenial') && $forum['mysupportdenial']) {
        $additional = $description = '';

        $url = (new Url('modcp.php'))->build([
            'action' => 'supportdenial',
            'do' => 'denysupport',
            'tid' => $threadID,
            'uid' => $post['uid'],
        ]);

        $imageUrl = $mybb->get_asset_url('images/mysupport_no_support.png');

        if ($post['deniedsupport'] == 1) {
            $additional = $lang->denied_support;

            if ($mybb->usergroup['canmanagesupportdenial']) {
                $imageAlt = $imageTitle = $lang->sprintf($lang->revoke_from, htmlspecialchars_uni($post['username']));

                static $deniedReasonsCache = null;

                if ($deniedReasonsCache === null) {
                    $deniedReasonsCache = $mybb->cache->read('mysupport')['deniedReasons'] ?? [];
                }

                if (!empty($deniedReasonsCache['deniedReasons'])) {
                    foreach ($deniedReasonsCache['deniedReasons'] as $denyReasonID => $deniedReasonsData) {
                        $deniedReasonsCache[$denyReasonID] = htmlspecialchars_uni($deniedReasonsData['name']);
                    }
                }

                if (array_key_exists($post['deniedsupportreason'], $deniedReasonsCache)) {
                    $additional .= ': ' . htmlspecialchars_uni(
                            $deniedReasonsCache[$post['deniedsupportreason']]['name']
                        );
                }

                $post['mySupportDenySupport'] = eval(getTemplate('deny_support_post_linked'));
            } else {
                $imageAlt = $imageTitle = $lang->denied_support;

                $post['mySupportDenySupport'] = eval(getTemplate('deny_support_post'));
            }
        } elseif ($mybb->usergroup['canmanagesupportdenial']) {
            $userPermissions = user_permissions($post['uid']);

            // various checks to see if they should be considered staff or not - if they are, don't show this for this user
            if (!($userPermissions['canmarksolved'] || is_moderator($forum['fid'], '', $post['uid']))) {
                $description = $imageAlt = $imageTitle = $lang->sprintf(
                    $lang->deny_support_to,
                    htmlspecialchars_uni($post['username'])
                );

                $post['mySupportDenySupport'] = eval(getTemplate('deny_support_post_linked'));
            }
        }
    }

    $threadStatus = (int)$thread['status'];

    $threadStatusOnhold = (int)$thread['onhold'];

    if ($isFirstPost &&
        !empty($thread['issupportthread']) &&
        $threadStatusOnhold !== ThreadStatus::Onhold &&
        is_moderator($forumID, 'canmarksolved')) {
        if ($threadStatus !== ThreadStatus::Solved) {
            $post['mySupportQuickButtons'] .= eval(getTemplate('post_button_solve'));
        }

        if ($threadStatus === ThreadStatus::Solved) {
            $post['mySupportQuickButtons'] .= eval(getTemplate('post_button_not_solved'));
        }
    }

    $threadTechnicalStatus = (int)$thread['mysupport_is_technical'];

    if ($isFirstPost &&
        !empty($thread['issupportthread']) &&
        $threadStatusOnhold !== ThreadStatus::Onhold &&
        settingsGet('enabletechnical') &&
        is_moderator($forumID, 'canmarktechnical')) {
        if ($threadTechnicalStatus !== ThreadStatus::Technical) {
            $post['mySupportQuickButtons'] .= eval(getTemplate('post_button_technical'));
        }

        if ($threadTechnicalStatus === ThreadStatus::Technical) {
            $post['mySupportQuickButtons'] .= eval(getTemplate('post_button_not_technical'));
        }
    }

    $threadStatusOnhold = (int)$thread['onhold'];

    if ($isFirstPost &&
        !empty($thread['issupportthread']) &&
        settingsGet('enableonhold') &&
        is_moderator($forumID, 'canmarkonhold')) {
        if ($threadStatusOnhold !== ThreadStatus::Onhold) {
            $post['mySupportQuickButtons'] .= eval(getTemplate('post_button_onhold'));
        }

        if ($threadStatusOnhold === ThreadStatus::Onhold) {
            $post['mySupportQuickButtons'] .= eval(getTemplate('post_button_not_onhold'));
        }
    }

    $priorityID = (int)$thread['priority'];

    if (!empty($thread['issupportthread']) && $priorityID && $thread['visible'] == 1) {
        $post['mySupportPriorityClass'] = ' mySupportPriority_' . priorityClassGetName($priorityID) . ' ';
    }

    return $post;
}

// show the form in the thread to change the status of the thread
function showthread_start30(): void
{
    global $mybb, $lang, $theme, $thread, $forum, $mysupport_options, $mod_log_action, $mySupportRedirectMessages;

    if (empty($forum['mysupport'])) {
        return;
        ///error($lang->bestanswer_invalid_forum);
    }

    languageLoad();

    $tid = intval($thread['tid']);
    $fid = $forumID = intval($thread['fid']);

    $currentUserID = (int)$mybb->user['uid'];

    if ($mybb->get_input('action') !== 'mysupport' && $mybb->get_input('action') !== 'best_answer') {
        $onclick = '';

        $count = 0;

        if (!empty($thread['issupportthread'])) {
            $mysupport_options = '';
            $mysupport_solved = $mysupport_solved_and_close = $mysupport_technical = $mysupport_not_solved = $on_hold = $assigned_list = $priorities_list = $categories_list = $is_support_thread = '';
            // if it's not already solved
            if ($thread['status'] != 1) {
                // can they mark as solved?
                if ($mybb->usergroup['canmarksolved'] || (settingsGet('author') && $thread['uid'] == $currentUserID)) {
                    // closing when solved is either optional or off
                    if (settingsGet('closewhensolved') !== 'always') {
                        $onclick = '';

                        if ($mybb->get_input('mysupport_full')) {
                            $selected = '';
                            $value = 1;
                            $label = $lang->solved;
                            $mysupport_solved = eval(getTemplate('form_select_option'));
                        }
                        ++$count;
                    }

                    // is the ability to close turned on?
                    if (settingsGet('closewhensolved') !== 'never' && !$thread['closed']) {
                        // if the close setting isn't, this option would show regardless of whether it's set to always or optional
                        if ($mybb->get_input('mysupport_full')) {
                            $selected = '';
                            $value = 3;
                            $label = $lang->solved_close;
                            $mysupport_solved_and_close = eval(getTemplate('form_select_option'));
                        }
                        ++$count;
                    }
                }

                // is the technical threads feature on?
                if (settingsGet('enabletechnical')) {
                    // can they mark as technical?
                    if (is_moderator($fid, 'canmarktechnical')) {
                        if ($thread['status'] != 2) {
                            // if it's not marked as technical, give an option to mark it as such
                            if ($mybb->get_input('mysupport_full')) {
                                $selected = '';
                                $value = 2;
                                $label = $lang->technical;
                                $mysupport_technical = eval(getTemplate('form_select_option'));
                            }
                            // if it's already marked as technical, can put it back to normal
                        } elseif ($mybb->get_input('mysupport_full')) {
                            $selected = '';
                            $value = 4;
                            $label = $lang->not_technical;
                            $mysupport_technical = eval(getTemplate('form_select_option'));
                        }
                        ++$count;
                    }
                }
            } // if it's solved, all you can do is mark it as not solved
            // are they allowed to mark it as not solved if it's been marked solved already?
            elseif (is_moderator($forumID, 'canmarksolved') ||
                (settingsGet('author') && $thread['uid'] == $currentUserID)
            ) {
                if ($mybb->get_input('mysupport_full')) {
                    $selected = '';
                    $value = 0;
                    $label = $lang->not_solved;
                    $mysupport_not_solved = eval(getTemplate('form_select_option'));
                }
                ++$count;
            }
        } else {
            $text = $lang->issupportthread_mark_as_support_thread;
            $class = 'mysupport_tab_markassupport';
            $url = $mybb->settings['bburl'] . "/showthread.php?action=mysupport&amp;issupportthread=1&amp;tid={$tid}&amp;my_post_key={$mybb->post_code}";
            $mysupport_options .= eval(getTemplate('tab'));
        }

        if ($mybb->get_input('mysupport_full')) {
            // are there actually any options to show for this user?
            if ($count > 0) {
                $mysupport_options = eval(getTemplate('form'));
            }
        } else {
            $mysupport_options = "<br /><div class=\"mysupport_tabs\">{$mysupport_options}</div>";
        }

        if ($mysupport_options) {
            global $header;
            $header .= $mysupport_options;
        }
    }

    if ($mybb->get_input('action') == 'mysupport') {
        verify_post_check($mybb->get_input('my_post_key'));
        $status = $mybb->get_input('status', MyBB::INPUT_INT);
        $assign = $mybb->get_input('assign', MyBB::INPUT_INT);
        $priority = $mybb->get_input('priority', MyBB::INPUT_INT);
        $category = $mybb->get_input('category', MyBB::INPUT_INT);
        $onhold = $mybb->get_input('onhold', MyBB::INPUT_INT);
        $issupportthread = $mybb->get_input('issupportthread', MyBB::INPUT_INT);
        $tid = intval($thread['tid']);
        $fid = intval($thread['fid']);
        $old_status = intval($thread['status']);
        $old_assign = intval($thread['assign']);
        $old_priority = intval($thread['priority']);
        $old_category = intval($thread['mysupport_category_id']);
        $old_onhold = intval($thread['onhold']);
        $old_issupportthread = intval($thread['issupportthread']);

        $valid_action = false;

        // we need to make sure they haven't edited the form to try to perform an action they're not allowed to do
        // we check everything in the entire form, if any part of it is wrong, it won't do anything
        if (empty($forum['mysupport'])) {
            error($lang->error_not_mysupport_forum);
        }
        // are they trying to assign the same status it already has?
        if ($status == $old_status && empty($mybb->input['onhold']) && empty($mybb->input['issupportthread'])) {
            #_dump();
            $duplicate_status = threadSolvedFriendlyStatusGet($status);
            error($lang->sprintf($lang->error_same_status, $duplicate_status));
        } elseif ($status == 0) {
            #_dump(0);
            // either the ability to unsolve is turned off,
            // they don't have permission to mark as not solved via group permissions, or they're not allowed to mark it as not solved even though they authored it
            if (
                !$forum['allowsolvestatus'] ||
                (
                    !is_moderator($fid, 'canmarksolved') ||
                    !($mybb->usergroup['canmarksolved'] && $thread['uid'] == $currentUserID)
                )
            ) {
                error($lang->no_permission_mark_notsolved);
            }

            $valid_action = true;
        } elseif ($status == 1) {
            #_dump(1);
            // either they're not in a group that can mark as solved
            // or they're not allowed to mark it as solved even though they authored it
            if (!$mybb->usergroup['canmarksolved'] && !(settingsGet('author') && $thread['uid'] == $currentUserID)) {
                error($lang->no_permission_mark_solved);
            }

            $valid_action = true;
        } elseif ($status == 2) {
            #_dump(2);
            if (!$forum['allowtechnicalstatus']) {
                error($lang->technical_not_enabled);
            }

            // they don't have the ability to mark threads as technical
            if (!is_moderator($fid, 'canmarktechnical')) {
                error($lang->no_permission_mark_technical);
            }

            $valid_action = true;
        } elseif ($status == 3) {
            #_dump(3);
            // either closing of threads is turned off altogether,
            // or it's on, but they're not in a group that can't mark as solved
            if ($thread['closed'] == 1 ||
                settingsGet('closewhensolved') == 'never' ||
                (
                    settingsGet('closewhensolved') !== 'never' &&
                    (!$mybb->usergroup['canmarksolved'] && !(settingsGet('author') && $thread['uid'] == $currentUserID))
                )) {
                error($lang->no_permission_mark_solved_close);
            }

            $valid_action = true;
        } elseif ($status == 4) {
            #_dump(4);
            // they don't have the ability to mark threads as not technical
            if (!is_moderator($fid, 'canmarktechnical')) {
                error($lang->no_permission_mark_nottechnical);
            }

            $valid_action = true;
        }
        // check if the thread is being put on/taken off hold
        // check here is a bit weird as it'll be 1/-1 if coming from the tab link, and 1 or nothing if coming from the checkbox in the form
        // if it's coming from the form, check if it's being put on hold and wasn't on hold before (put on hold), or the box wasn't checked and it was on hold before (taken off hold)
        // or, if it's coming from the link, check if it's being put on hold and wasn't on hold before (put on hold), or it's being taken off hold and was on hold before
        if (($mybb->get_input(
                    'via_form',
                    MyBB::INPUT_INT
                ) && (($onhold == 1 && $old_onhold == 0) || (!$onhold && $old_onhold == 1))) || (!$mybb->get_input(
                    'via_form',
                    MyBB::INPUT_INT
                ) && (($onhold == 1 && $old_onhold == 0) || ($onhold == -1 && $old_onhold == 1)))) {
            if (!settingsGet('enableonhold')) {
                error($lang->onhold_not_enabled);
            }

            if ($thread['status'] == 1) {
                error($lang->onhold_solved);
            }

            if (!$mybb->usergroup['canmarksolved'] && !(settingsGet('author') && $thread['uid'] == $currentUserID)) {
                error($lang->no_permission_thread_hold);
            }

            // we don't need to perform the big check above again, as if we're in here we know it's being changed
            // it'll either be 1, 0 or -1, if it's anything other than 1, we're taking it off hold
            if ($onhold != 1) {
                $ohold = 0;
            }

            $valid_action = true;
        }
        if (($mybb->get_input(
                'via_form',
                MyBB::INPUT_INT
            ) && !empty($mybb->input['issupportthread']) && (($issupportthread == 1 && $old_issupportthread == 0) || (!$issupportthread && $old_issupportthread == 1)))) {
            if (!settingsGet('enablenotsupportthread')) {
                error($lang->issupportthread_not_enabled);
            }

            if (!$mybb->usergroup['canmarksolved'] && !(settingsGet('author') && $thread['uid'] == $currentUserID)) {
                error($lang->no_permission_issupportthread);
            }
        }

        // trying to assign a thread to someone
        if ($assign != 0) {
            if (!settingsGet('enableassign')) {
                error($lang->assign_not_enabled);
            }
            // trying to assign a solved thread,
            // this is needed to see if we're trying to assign a currently solved thread whilst at the same time changing the status of it
            // the option to assign will still be there if it's solved as you may want to unsolved it and assign it again, but we can't assign it if it's staying solved, we have to be unsolving it
            if ($thread['status'] == 1 && $status != 0) {
                error($lang->assign_solved);
            }

            if (!is_moderator($fid, 'canassign')) {
                error($lang->assign_no_perms);
            }

            $assign_users = get_assign_users();
            // -1 is what's used to unassign a thread, so we need to exclude that
            if (!array_key_exists($assign, $assign_users) && $assign != '-1') {
                error($lang->assign_invalid);
            }

            $valid_action = true;
        }
        // setting a priority
        if ($priority != 0) {
            if (!settingsGet('enablepriorities')) {
                error($lang->priority_not_enabled);
            }

            if (!is_moderator($fid, 'cansetpriorities')) {
                error($lang->priority_no_perms);
            }

            if ($thread['status'] == 1 && $status != 0) {
                error($lang->priority_solved);
            }

            $mysupport_cache = $mybb->cache->read('mysupport');
            $mids = [];
            if (!empty($mysupport_cache['priorities'])) {
                foreach ($mysupport_cache['priorities'] as $priority_info) {
                    $mids[] = intval($priority_info['mid']);
                }
            }
            if (!in_array($priority, $mids) && $priority != '-1') {
                error($lang->priority_invalid);
            }

            $valid_action = true;
        }
        // setting a category
        if ($category != 0) {
            $categories = cacheGetCategories($fid);
            if (!array_key_exists($category, $categories) && $category != '-1') {
                error($lang->category_invalid);
            }

            $valid_action = true;
        }
        // it didn't hit an error with any of the above, it's a valid action
        if ($valid_action !== false) {
            // If you're choosing the same status or choosing none
            // and assigning the same user or assigning none (as in the empty option, not choosing 'Nobody' to remove an assignment)
            // and setting the same priority or setting none (as in the empty option, not choosing 'None' to remove a priority)
            // and setting the same hold status, and setting the same issupportthread status,
            // then you're not doing anything. You're either choosing the same stuff or choosing nothing at all
            if (($status == $old_status || $status == '-1') && ($assign == $old_assign || $assign == 0) && ($priority == $old_priority || $priority == 0) && ($category == $old_category || $category == 0) && ($onhold == $old_onhold) && (!empty($mybb->input['issupportthread']) && $issupportthread == $old_issupportthread)) {
                error($lang->error_no_action);
            }

            $mod_log_action = '';

            isset($mySupportRedirectMessages) || $mySupportRedirectMessages = '';

            if (!empty($mybb->input['issupportthread']) && $issupportthread != $old_issupportthread) {
                change_issupportthread($thread, $issupportthread);
            } else {
                // change the status and move/close
                if ($status != $old_status && $status != '-1') {
                    threadSolveStatusUpdate($tid, $status);
                }

                if ($onhold != $old_onhold) {
                    threadOnholdStatusUpdate($thread, $onhold);
                }

                // we need to see if the same user has been submitted, so it doesn't run this without reason
                // we also need to check if it's being marked as solved, if it is we don't need to do anything with assignments, it'll just be ignored
                if ($assign != $old_assign && ($assign != 0 && $status != 1 && $status != 3)) {
                    threadAssignmentUpdate($tid, $assign);
                }

                // we need to see if the same priority has been submitted, so it doesn't run this without reason
                // we also need to check if it's being marked as solved, if it is, we don't need to do anything with priorities, it'll just be ignored
                if ($priority != $old_priority && ($priority != 0 && $status != 1)) {
                    threadPriorityUpdate($tid, $priority);
                }

                // we need to see if the same category has been submitted, so it doesn't run this without a reason
                if ($category != $old_category && ($category != 0 && $status != 1)) {
                    threadCategoryUpdate($tid, $category);
                }
            }

            if (!empty($mod_log_action)) {
                $mod_log_data = [
                    'fid' => $fid,
                    'tid' => $tid
                ];
                log_moderator_action($mod_log_data, $mod_log_action);
            }

            // where should they go to afterward?
            $thread_url = get_thread_link($tid);

            redirect($thread_url, $mySupportRedirectMessages);
        }
    } elseif ($mybb->get_input('action') == 'best_answer') {
        verify_post_check($mybb->get_input('my_post_key'));

        $post = get_post($mybb->get_input('pid', MyBB::INPUT_INT));

        if (!$post ||
            !$forum['allowbestanswerstatus'] || (
                $thread['firstpost'] == $post['pid'] && !settingsGet('bestAnswerAllowFirstPost')
            )) {
            error($lang->bestanswer_invalid_forum);
        }

        if (empty($thread['tid']) || empty($post['pid']) || !$mybb->usergroup['canmarkbestanswer'] && !is_moderator(
                $post['fid'],
                'canmarkbestanswer'
            )) {
            error_no_permission();
        }

        // did this user author this thread?
        if ($currentUserID != $thread['uid']) {
            error($lang->bestanswer_not_author);
        } // is this post already the best answer?
        elseif ($post['pid'] == $thread['bestanswer']) {
            // this will mark it as the best answer
            $status_update = [
                'bestanswer' => 0
            ];
            // update the bestanswer column for this thread with 0

            threadUpdate($status_update, (int)$thread['tid']);

            // are we removing points for this?
            if (_points_system_enabled()) {
                if (settingsGet('bestanswerpoints') && settingsGet('bestanswerpoints')) {
                    update_points(
                        settingsGet('bestanswerpoints'),
                        $post['uid'],
                        true
                    );
                }
            }

            $post_url = get_post_link($post['pid'], $thread['tid']) . '#pid' . $post['pid'];

            redirect($post_url, $lang->unbestanswer_redirect);
        } // mark it as the best answer
        else {
            $status_update = [
                'bestanswer' => intval($post['pid'])
            ];
            // update the bestanswer column for this thread with the pid of the best answer

            threadUpdate($status_update, (int)$thread['tid']);

            // are we adding points for this?
            if (_points_system_enabled()) {
                if (settingsGet('bestanswerpoints')) {
                    update_points(
                        settingsGet('bestanswerpoints'),
                        $post['uid']
                    );
                }
            }

            // if this thread isn't solved yet, do that too whilst we're here
            // if they're marking a post as the best answer, it must have solved the thread, so save them marking it as solved manually
            if ($thread['status'] != 1 && (
                    $mybb->usergroup['canmarksolved'] ||
                    (settingsGet('author') && $thread['uid'] == $currentUserID)
                )) {
                $mod_log_action = '';

                // change the status
                threadSolveStatusUpdate((int)$thread['tid'], ThreadStatus::Solved);

                if (!empty($mod_log_action)) {
                    $mod_log_data = [
                        'fid' => intval($thread['fid']),
                        'tid' => intval($thread['tid'])
                    ];

                    log_moderator_action($mod_log_data, $mod_log_action);
                }
            }

            $post_url = get_post_link($post['pid'], $thread['tid']) . '#pid' . $post['pid'];

            redirect($post_url, $lang->bestanswer_redirect);
        }
    }
}

function showthread_end(): void
{
    global $forum, $thread;
    global $moderationoptions;

    $forumID = (int)$forum['fid'];

    $thread['mySupportBestAnswerButton'] = '';

    if (empty($moderationoptions) ||
        !str_contains($moderationoptions, '<!--MySupportInlineModerationOptions-->') ||
        empty($forum['mysupport']) ||
        empty($forum['allowbestanswerstatus'])) {
        return;
    }

    $moderationoptions = str_replace(
        '<!--MySupportInlineModerationOptions-->',
        inlineModerationBuild($forumID, $thread),
        $moderationoptions
    );

    newreply_end();


    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///
    ///

    if (empty($thread['bestanswer']) ||
        !($postData = get_post($thread['bestanswer'])) ||
        ($postData['visible'] !== 1 && !is_moderator($forumID))) {
        return;
    }

    global $mybb, $lang;

    languageLoad();

    $threadID = (int)$thread['tid'];

    $postID = (int)$thread['bestanswer'];

    $imageUrl = $mybb->get_asset_url('images/mysupport_bestanswer.png');

    $url = get_post_link($postID, $threadID) . '#pid' . $postID;

    $thread['mySupportBestAnswerButton'] = eval(getTemplate('thread_jump_to_best_answer'));
}

function usercp_start20(): void
{
    global $mybb, $db, $lang, $templates, $mysupport_usercp_options;

    if (settingsGet('displaytypeuserchange')) {
        $currentUserID = (int)$mybb->user['uid'];

        if ($mybb->get_input('action') == 'do_options') {
            $update = [
                'mysupportdisplayastext' => $mybb->get_input('mysupportdisplayastext', MyBB::INPUT_INT)
            ];

            userUpdate($update, $currentUserID);
        } elseif ($mybb->get_input('action') == 'options') {
            languageLoad();

            $mysupportdisplayastextcheck = '';
            if ($mybb->user['mysupportdisplayastext'] == 1) {
                $mysupportdisplayastextcheck = " checked=\"checked\"";
            }

            $mysupport_usercp_options = eval(getTemplate('usercp_options'));
        }
    }
}

function xmlhttp(): void
{
    global $mybb, $db, $lang;

    if ($mybb->get_input('action') !== 'mysupport_assign_users') {
        return;
    }

    $search_query = ltrim($mybb->get_input('query'));

    // If the string is less than 2 characters, quit.
    if (my_strlen($search_query) < 2) {
        exit;
    }

    $charset = 'UTF-8';

    if ($lang->settings['charset']) {
        $charset = $lang->settings['charset'];
    }

    // Send our headers.
    header("Content-type: application/json; charset={$charset}");

    $users = get_assign_users();

    $uids = implode("','", array_keys($users));

    $likestring = $db->escape_string_like($search_query);

    $data = [];

    foreach (
        usersGet(
            ["username LIKE '%{$likestring}%'", "uid IN ('{$uids}')"],
            ['uid', 'username'],
            [
                'order_by' => 'username',
                'order_dir' => 'asc',
                'limit_start' => 0,
                'limit' => 15
            ]
        ) as $user
    ) {
        $data[] = [
            'uid' => $user['uid'],
            'id' => $user['username'],
            'text' => $user['username']
        ];
    }

    echo json_encode($data);

    exit;
}

function usercp_subscriptions_start(): void
{
    global $templates;

    \MySupport\Core\control_object(
        $templates,
        'function get($title, $eslashes = 1, $htmlcomments = 1)
    {
        if ($title === "usercp_subscriptions_thread") {
            global $plugins;

            $plugins->run_hooks("usercp_subscriptions_thread");
        }

        return parent::get($title, $eslashes, $htmlcomments);
    }'
    );

    showthread_start20();
}

function usercp_subscriptions_thread(): void
{
    static $done;

    if (empty($done)) {
        $done = true;

        usercp_thread_subscriptions_thread();
    }

    usercp_thread_subscriptions_thread10();
}

// https://github.com/mybb/mybb/blob/f3dcfb59633f4777fdde550d1b4a3786332010ea/printthread.php#L24-L33
function printthread_start(): void
{
    global $mybb;
    global $thread;

    $thread = get_thread($mybb->get_input('tid', MyBB::INPUT_INT));

    if (empty($thread) || (int)$thread['visible'] === -1) {
        return;
    }

    usercp_thread_subscriptions_thread([$thread['tid']]);

    usercp_thread_subscriptions_thread10();
}
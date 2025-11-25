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

use AbstractPdoDbDriver;
use DB_SQLite;
use Exception;
use Moderation;
use MybbStuff_MyAlerts_AlertManager;
use MybbStuff_MyAlerts_AlertTypeManager;
use MybbStuff_MyAlerts_Entity_Alert;
use postParser;
use ReflectionProperty;

const CACHE_TYPE_ALL = 0;

const CACHE_TYPE_VERSION = 1;

const CACHE_TYPE_PRIORITIES = 2;

const CACHE_TYPE_DENIED_REASONS = 3;

const CACHE_TYPE_CATEGORIES = 4;

const DATABASE_ROW_TYPE_PRIORITY = 1;

const DATABASE_ROW_TYPE_DENIED_REASON = 2;

const DATABASE_ROW_TYPE_BACKUP = 3;

const REVOKE_DENIED_SUPPORT = -1;

const THREAD_STATUS_SOLVED_CLOSED = 3;

const INPUT_NO_CHANGE = -1;

const INPUT_TOGGLE_NOBODY_NONE = -2;

class ThreadStatus
{
    public const IsNotSupport = 0;
    public const IsSupport = 1;
    public const NotSolved = 0;
    public const Solved = 1;
    public const NotTechnical = 0;
    public const Technical = 1;
    public const NotOnhold = 0;
    public const Onhold = 1;
}

class AssignStatus
{
    public const Inactive = 0;
    public const Active = 1;
}

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

function settingsGet(string $setting_key = '')
{
    global $mybb;

    return $mybb->settings['mysupport_' . $setting_key] ?? false;
}

function send_alert(int $tid, int $uid, int $author = 0): void
{
    global $lang, $mybb, $alertType, $db;

    languageLoad();

    if (!(settingsGet('notifications') && class_exists('MybbStuff_MyAlerts_AlertTypeManager'))) {
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
    global $mybb;

    $currentCachedData = $mybb->cache->read('mysupport');

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

        case CACHE_TYPE_ALL:
        case CACHE_TYPE_CATEGORIES:

            $categoryObjects = categoryGet(
                queryFields: ['category_id', 'name', 'display_style', 'allowed_forums', 'allowed_groups'],
                queryOptions: ['order_by' => 'name']
            );

            $newCachedData['categories'] = [];

            foreach ($categoryObjects as $categoryData) {
                $newCachedData['categories'][(int)$categoryData['category_id']] = [
                    'name' => $categoryData['name'],
                    'display_style' => $categoryData['display_style'],
                    'allowed_forums' => $categoryData['allowed_forums'],
                    'allowed_groups' => $categoryData['allowed_groups'],
                ];
            }
    }

    $mybb->cache->update('mysupport', $newCachedData);
}

/**
 * Get the count of technical threads.
 *
 * @param int $forumID The FID we're in.
 * @return int The number of technical threads in this forum.
 */
function technicalThreadsGetTotal(int $forumID = 0): int
{
    global $mybb, $db;

    $totalTechnicalThreadsCount = 0;

    $forumsCache = $mybb->cache->read('forums');

    if ($forumID) {
        if (!empty($forumsCache[$forumID]['technicalthreads'])) {
            $totalTechnicalThreadsCount += $forumsCache[$forumID]['technicalthreads'];
        }
    } else {
        foreach ($forumsCache as $forumData) {
            if (!empty($forumData['technicalthreads'])) {
                $totalTechnicalThreadsCount += $forumData['technicalthreads'];
            }
        }
    }

    if (!$totalTechnicalThreadsCount) {
        return 0;
    }

    $enabledForums = implode("','", enabledForums());

    $whereClauses = [
        "fid IN ('{$enabledForums}')",
        "mysupport_is_technical='" . ThreadStatus::Technical . "'",
    ];

    if ($forumID) {
        $whereClauses[] = "fid='{$forumID}'";
    }

    if (empty($mybb->usergroup['cancp']) && empty($mybb->usergroup['issupermod'])) {
        if (empty($mybb->user['ismoderator'])) {
            $forumIDs = [];

            foreach (enabledForums() as $forumID) {
                if (is_moderator($forumID, 'canviewhiddenthreads')) {
                    $forumIDs[] = $forumID;
                }
            }

            $forumIDs = implode("','", $forumIDs);

            $whereClauses[] = "fid IN ('{$forumIDs}')";
        } else {
            $whereClauses[] = "visible='1'";
        }
    }

    $query = $db->simple_select(
        'threads',
        'COUNT(tid) as total_technical_threads',
        implode(' AND ', $whereClauses),
        ['limit' => 1]
    );

    return (int)$db->fetch_field($query, 'total_technical_threads');
}

/**
 * Get the count of assigned threads.
 *
 * @param int $forumID The FID we're in.
 * @return int The number of assigned threads in this forum.
 */
function assignedThreadsGetTotal(int $forumID = 0): int
{
    global $mybb, $db;

    if (empty($mybb->user['assignedthreads'])) {
        return 0;
    }

    $currentUserID = (int)$mybb->user['uid'];

    $enabledForums = implode("','", enabledForums());

    $whereClauses = [
        "thread.fid IN ('{$enabledForums}')",
        "assigned_thread.user_id='{$currentUserID}'",
        "assigned_thread.status='1'",
    ];

    if ($forumID) {
        $whereClauses[] = "thread.fid='{$forumID}'";
    }

    if (empty($mybb->usergroup['cancp']) && empty($mybb->usergroup['issupermod'])) {
        if (empty($mybb->user['ismoderator'])) {
            $forumIDs = [];

            foreach (enabledForums() as $forumID) {
                if (is_moderator($forumID, 'canviewhiddenthreads')) {
                    $forumIDs[] = $forumID;
                }
            }

            $forumIDs = implode("','", $forumIDs);

            $whereClauses[] = "thread.fid IN ('{$forumIDs}')";
        } else {
            $whereClauses[] = "thread.visible='1'";
        }
    }

    $query = $db->simple_select(
        "mysupport_assigned_threads assigned_thread LEFT JOIN {$db->table_prefix}threads thread ON (thread.tid=assigned_thread.thread_id)",
        'COUNT(assigned_thread.assign_id) as total_assigned_threads',
        implode(' AND ', $whereClauses),
        ['limit' => 1]
    );

    $totalAssignedThreadsCount = (int)$db->fetch_field($query, 'total_assigned_threads');

    if (!$totalAssignedThreadsCount) {
        userUpdate(['assignedthreads' => 0], (int)$mybb->user['uid']);
    }

    return $totalAssignedThreadsCount;
}

/**
 * Generates a list of all forums that have MySupport enabled.
 *
 * @return array Array of forums that have MySupport enabled.
 **/
function enabledForums(bool $assigningEnabled = false): array
{
    global $mybb;

    static $enabledForums = null;

    if ($enabledForums === null) {
        $enabledForums = [];

        $forumsCache = $mybb->cache->read('forums');

        foreach ($forumsCache as $forum) {
            // if this forum/category has MySupport enabled, add it to the array
            if (!empty($forum['mysupport'])) {
                $enabledForums[(int)$forum['fid']] = (int)$forum['fid'];
            }
        }
    }

    return $enabledForums;
}

/**
 * Get the text version of the status of a thread.
 *
 * @param int $solveStatus The status of the thread.
 * @return string The text version of the status of the thread.
 **/
function threadSolvedFriendlyStatusGet(int $solveStatus): string
{
    global $lang;

    languageLoad();

    return match ($solveStatus) {
        3, ThreadStatus::Solved => $lang->mySupportStatusTextSolved, // todo, what was 3 for again ??
        default => $lang->mySupportStatusTextNotSolved,
    };
}

/**
 * Get the text version of the status of a thread.
 *
 * @param int $technicalStatus The status of the thread.
 * @return string The text version of the status of the thread.
 **/
function threadTechnicalFriendlyStatusGet(int $technicalStatus): string
{
    global $lang;

    languageLoad();

    return match ($technicalStatus) {
        ThreadStatus::Technical => $lang->mySupportStatusTextTechnical,
        default => $lang->mySupportStatusTextNotTechnical,
    };
}

/**
 * Show the status of a thread.
 *
 * @param int $threadID
 * @param int|null $userID
 * @return string
 */
function displayStatusGet(int $threadID, ?int $userID = null): string
{
    $threadData = get_thread($threadID);

    $status = (int)$threadData['status'];

    $onhold = (int)$threadData['onhold'];

    $statusTime = (int)$threadData['statustime'];

    $theadUserID = (int)$threadData['uid'];

    global $mybb, $lang, $templates, $theme, $mysupport_status, $thread;

    $currentUserID = (int)$mybb->user['uid'];

    if ($userID === null) {
        $userPermissions = $mybb->usergroup;
    } else {
        $userPermissions = user_permissions();
    }

    // big check to see if either the status is to be shown to everybody, only to people who can mark as solved, or to people who can mark as solved or who authored the thread
    if (settingsGet('displayto') === 'all' ||
        (settingsGet('displayto') === 'canmas' && (
                $userPermissions['canmarksolved'] || (settingsGet('author') && $currentUserID === $theadUserID)
            )) ||
        (settingsGet('displayto') === 'canmasauthor' && (
                $userPermissions['canmarksolved'] || $currentUserID === $theadUserID
            ))) {
        if (settingsGet('relativetime')) {
            $date_time = my_date('relative', $statusTime);
        } else {
            $date_time = my_date('normal', $statusTime);
        }

        $date_time_technical = 0;

        if (displayTypeGet() !== 'text') {
            $date_time = strip_tags($date_time);
        }

        $status_title = $lang->sprintf($lang->technical_time, $date_time_technical);

        $threadTechnicalStatus = (int)$threadData['mysupport_is_technical'];

        // if this user cannot mark a thread as technical and people who can't mark as technical can't see that a technical thread is technical, don't execute this,
        // I used the word technical 4 times in that sentence didn't I? sorry about that
        if ($threadTechnicalStatus === ThreadStatus::Technical && !(settingsGet('hidetechnical') ||
                ($userPermissions['canseetechnotice'] || is_moderator(
                        $thread['fid'],
                        'canmarktechnical'
                    )))) {
            $status_class = $status_img = 'technical';
            $status_title = htmlspecialchars_uni($lang->sprintf($lang->technical_time, $date_time));

            if (displayTypeGet() === 'text') {
                $status_text = $lang->technical;
            }
        } elseif ($status === ThreadStatus::Solved) {
            $status_class = $status_img = 'solved';
            $status_text = $lang->mySupportDisplayStatusSolved;
            $status_title = htmlspecialchars_uni($lang->sprintf($lang->solved_time, $date_time));

            if (displayTypeGet() !== 'text') {
                $status_text = $lang->mySupportDisplayStatusSolved;
            }
        } else {
            $status_class = $status_img = 'notsolved';
            $status_text = $status_title = $lang->mySupportDisplayStatusNotSolved;
        }

        if ($onhold == ThreadStatus::Onhold) {
            $status_class = $status_img = 'onhold';
            $status_text = $lang->onhold;
            $status_title = $lang->onhold . ' - ' . $status_title;
        }

        if (displayTypeGet() === 'text') {
            $mysupport_status = eval(getTemplate('status_text'));
        } else {
            $mysupport_status = eval(getTemplate('status_image'));
        }

        return $mysupport_status;
    }

    return '';
}

/**
 * Check if a points system is enabled for points system integration.
 *
 * @return bool Whether your chosen points system is enabled.
 **/
function _points_system_enabled(): bool
{
    global $mybb;

    $plugins = $mybb->cache->read('plugins');

    $pointsSystemCode = settingsGet('pointssystem');

    if ($pointsSystemCode !== 'none') {
        if ($pointsSystemCode === 'other') {
            $pointsSystemCode = settingsGet('pointssystemname');
        }

        return in_array($pointsSystemCode, $plugins['active']);
    }

    return false;
}

/**
 * Change the status of a thread.
 *
 * @param int $threadID The thread identifier.
 * @param int $solveStatus The new status.
 */
function threadSolveStatusUpdate(int $threadID, int $solveStatus): void
{
    global $mybb;

    $currentUserID = (int)$mybb->user['uid'];

    if ($solveStatus === ThreadStatus::Solved) {
        threadUpdate([
            'status' => ThreadStatus::Solved,
            'statusuid' => $currentUserID,
            'statustime' => TIME_NOW,
            'assign' => 0,
            'priority' => 0,
            'onhold' => 0 // todo ? I think onhold threads can't be marked as solved ...
        ], $threadID);

        foreach (
            assignGet(
                ["thread_id='{$threadID}'", "status='" . AssignStatus::Active . "'"],
                ['assign_id', 'user_id']
            ) as $assignID => $assignData
        ) {
            assignUpdate(['status' => AssignStatus::Inactive], $assignID);

            usersUpdateAssignCount((int)$assignData['user_id']);
            // todo, maybe run this on mass like in technical threads forum rebuild ?
        }
    } else {
        // if we're marking it as unsolved, a post may have been marked as the best answer when it was originally solved, best remove it, as well as rest everything else
        threadUpdate([
            'status' => ThreadStatus::NotSolved,
            'statusuid' => 0,
            'statustime' => 0,
            'bestanswer' => 0,
        ], $threadID);

        // if we're marking a thread(s) as unsolved, open back any threads that were closed when they were marked as solved, but not any that were closed by denying support
        foreach (
            threadsGet(
                ["tid='{$threadID}'", "closed='1'", "closedbymysupport='1'"],
                ['tid']
            ) as $threadID => $threadData
        ) {
            require_once MYBB_ROOT . 'inc/class_moderation.php';

            $moderation = new Moderation();

            $moderation->open_threads($threadID);

            threadUpdate(['closedbymysupport' => 0], $threadID);
        }
    }
}

function threadTechnicalStatusUpdate(int $threadID, int $technicalStatus): void
{
    if ($technicalStatus === ThreadStatus::Technical) {
        threadUpdate(['mysupport_is_technical' => ThreadStatus::Technical,], $threadID);
    } else {
        /** if it's 4, it's because it was marked as being not technical after being marked technical
         ** basically put back to the original status of not solved (0)
         ** however it needs to be 4 so we can differentiate between this action (technical => not technical), and a user marking it as not solved
         ** because both of these options eventually set it back to 0
         ** so the mod log entry will say the correct action as the status was 4, and it used that
         ** now that the log has been inserted we can set it to 0 again for the thread update query so it's marked as unsolved **/
        threadUpdate(['mysupport_is_technical' => ThreadStatus::NotTechnical,], $threadID);
    }
}

// loads the dropdown menu for inline thread moderation
function inlineModerationBuild(int|array $forumIDs, ?array $threadData = null): string
{
    global $mybb, $lang;

    languageLoad();

    $isSupportOption = $solveGroup = $technicalGroup = $holdGroup = $assignGroup = $prioritiesGroup = $categoriesGroup = '';

    $threadStatusOnhold = (int)($threadData['onhold'] ?? 0);

    $currentUserID = (int)$mybb->user['uid'];

    $theadUserID = (int)($threadData['uid'] ?? 0);

    if (settingsGet('enablenotsupportthread') && (
            $threadData === null ||
            (is_int($forumIDs) && is_moderator($forumIDs, 'canmarksolved')) ||
            (settingsGet('author') && $theadUserID === $currentUserID)
        ) && (empty($threadData['issupportthread']) || $threadStatusOnhold !== ThreadStatus::Onhold)) {
        if ($threadData === null || empty($threadData['issupportthread'])) {
            $isSupportOption = eval(getTemplate('inline_thread_moderation_is_support'));
        }

        if ($threadData === null || !empty($threadData['issupportthread'])) {
            $isSupportOption = eval(getTemplate('inline_thread_moderation_is_not_support'));
        }
    }

    if (($threadData === null || (is_int($forumIDs) && is_moderator($forumIDs, 'canmarksolved'))) &&
        $threadStatusOnhold !== ThreadStatus::Onhold) {
        $solved = $notSolvedOption = '';

        if ($threadData === null || (int)$threadData['status'] !== ThreadStatus::Solved) {
            $solved = eval(getTemplate('inline_thread_moderation_solved'));
        }

        if ($threadData === null || (int)$threadData['status'] !== ThreadStatus::NotSolved) {
            $notSolvedOption = eval(getTemplate('inline_thread_moderation_not_solved'));
        }

        $solveGroup = eval(getTemplate('inline_thread_moderation_group_solved'));
    }

    $threadStatus = (int)($threadData['status'] ?? 0);

    $threadTechnicalStatus = (int)($threadData['mysupport_is_technical'] ?? 0);

    if (settingsGet('enabletechnical') && (
            $threadData === null || (is_int($forumIDs) && is_moderator($forumIDs, 'canmarktechnical'))
        ) && $threadStatusOnhold !== ThreadStatus::Onhold) {
        $technicalOption = $notTechnicalOption = '';

        if ($threadData === null || $threadTechnicalStatus !== ThreadStatus::Technical) {
            $technicalOption = eval(getTemplate('inline_thread_moderation_technical'));
        }

        if ($threadData === null || $threadTechnicalStatus !== ThreadStatus::NotTechnical) {
            $notTechnicalOption = eval(getTemplate('inline_thread_moderation_not_technical'));
        }

        $technicalGroup = eval(getTemplate('inline_thread_moderation_group_technical'));
    }

    if (settingsGet('enableonhold') && (
            $threadData === null || (is_int($forumIDs) && is_moderator($forumIDs, 'canmarkonhold'))
        )) {
        $onholdOption = $notOnholdOption = '';

        if ($threadData === null || $threadStatusOnhold !== ThreadStatus::Onhold) {
            $onholdOption = eval(getTemplate('inline_thread_moderation_onhold'));
        }

        if ($threadData === null || $threadStatusOnhold !== ThreadStatus::NotOnhold) {
            $notOnholdOption = eval(getTemplate('inline_thread_moderation_not_onhold'));
        }

        $holdGroup = eval(getTemplate('inline_thread_moderation_group_onhold'));
    }

    if (settingsGet('enableassign')) {
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

    if (settingsGet('enablepriorities') && (
            $threadData === null ||
            $threadStatusOnhold !== ThreadStatus::Onhold
        )) {
        $mysupport_cache = $mybb->cache->read('mysupport');

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
        $categoriesCache = cacheGetCategories($forumIDs);
    } else {
        $categoriesCache = [];

        foreach ($forumIDs as $forumID) {
            $categoriesCache = array_merge($categoriesCache, cacheGetCategories((int)$forumID));
        }
    }

    // only continue if there are any priorities
    if (!empty($categoriesCache) && (
            $threadData === null ||
            $threadStatusOnhold !== ThreadStatus::Onhold
        )) {
        $currentCategoryID = $threadData === null ? 0 : (int)$threadData['mysupport_category_id'];

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
    global $mybb;

    $prioritiesCache = $mybb->cache->read('mysupport')['priorities'] ?? [];

    if (!empty($prioritiesCache[$priorityID]) &&
        !empty($prioritiesCache[$priorityID]['name'])) {
        return ucfirst(
            htmlspecialchars_uni(
                preg_replace(
                    '/[^A-Za-z0-9 ]/',
                    '_',
                    $prioritiesCache[$priorityID]['name']
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
    global $db, $mybb;

    // who can be assigned threads?
    $groups = $mybb->cache->read('usergroups');

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
 * @param int|null $forumID
 * @return array Array of available categories.
 */
function cacheGetCategories(?int $forumID = null): array
{
    global $mybb;

    $categoriesCache = (array)$mybb->cache->read('mysupport')['categories'];

    $categoryObjects = [];

    foreach ($categoriesCache as $categoryID => $categoryData) {
        if (/*is_member($categoryData['allowed_groups']) &&*/
            $forumID === null ||
            is_member(
                $categoryData['allowed_forums'],
                ['usergroup' => $forumID, 'additionalgroups' => get_forum($forumID)['parentlist']]
            )) {
            $categoryObjects[$categoryID] = $categoryData['name'];
        }
    }

    return $categoryObjects;
}

/**
 * Change the support status of a thread.
 *
 * @param int $threadID The thread identifier.
 * @param int $onholdStatus The new hold status.
 **/
function threadSupportStatusUpdate(int $threadID, int $onholdStatus = 0): void
{
    if ($onholdStatus === ThreadStatus::IsSupport) {
        threadUpdate(['issupportthread' => ThreadStatus::IsSupport,], $threadID);
    } else {
        threadUpdate(['issupportthread' => ThreadStatus::IsNotSupport,], $threadID);
    }
}

/**
 * Change the hold status of a thread.
 *
 * @param int $threadID The thread identifier.
 * @param int $onholdStatus The new hold status.
 * @param bool $multiple If this is changing the hold status of multiple threads.
 **/
function threadOnholdStatusUpdate(int $threadID, int $onholdStatus = 0): void
{
    if ($onholdStatus === ThreadStatus::Onhold) {
        threadUpdate(['onhold' => ThreadStatus::Onhold,], $threadID);
    } else {
        threadUpdate(['onhold' => ThreadStatus::NotOnhold,], $threadID);
    }
}

/**
 * Change who a thread is assigned to.
 *
 * @param int $threadID Information thread identifier.
 * @param int $assignUserID
 */
function threadAssignmentUpdate(int $threadID, int $assignUserID): void
{
    if ($assignUserID === -1) {
        threadUpdate(['assign' => 0], $threadID);

        foreach (
            assignGet(
                ["thread_id='{$threadID}'", "status='" . AssignStatus::Active . "'"],
                ['assign_id']
            ) as $assignID => $assignData
        ) {
            assignUpdate([
                'status' => AssignStatus::Inactive
            ], $assignID);
        }
    } else {
        global $mybb, $db, $lang;

        $currentUserID = (int)$mybb->user['uid'];

        threadUpdate(['assign' => $assignUserID], $threadID);

        if (!assignGet(
            ["thread_id='{$threadID}'", "user_id='{$assignUserID}'", "status='" . AssignStatus::Active . "'"],
            ['assign_id']
        )) {
            assignInsert([
                'user_id' => $assignUserID,
                'assigner_user_id' => $currentUserID,
                'thread_id' => $threadID,
                'dateline' => TIME_NOW
            ]);
        }

        if (settingsGet('assignpm')) {
            if ($assignUserID === $currentUserID) {
                return;
            }

            $threadData = get_thread($threadID);

            $forumID = (int)$threadData['fid'];

            send_pm([
                'subject' => $lang->assign_pm_subject,
                'message' => $lang->sprintf(
                    $lang->assign_pm_message,
                    get_user($assignUserID)['username'],
                    $mybb->settings['bburl'] . '/' . get_forum_link($forumID),
                    strip_tags(get_forum($forumID)['name']),
                    $mybb->settings['bburl'] . '/' . get_thread_link($threadID),
                    $threadData['subject'],
                    $lang->sprintf(
                        $lang->assigned_by,
                        $mybb->settings['bburl'] . '/' . get_profile_link($currentUserID),
                        htmlspecialchars_uni($mybb->user['username'])
                    ),
                    $mybb->settings['bburl']
                ),
                'icon' => -1,
                'fromid' => 0,
                'toid' => [$assignUserID],
                'bccid' => [],
                'do' => '',
                'pmid' => '',
                'saveasdraft' => 0,
                'options' => [
                    'signature' => 1,
                    'disablesmilies' => 0,
                    'savecopy' => 0,
                    'readreceipt' => 0
                ]
            ], admin_override: true);
        }

        if (settingsGet('assignsubscribe')) {
            $query = $db->simple_select(
                'threadsubscriptions',
                'sid',
                "uid = '{$assignUserID}' AND tid = '{$threadID}'"
            );

            // only do this if they're not already subscribed
            if (!$db->num_rows($query)) {
                require_once MYBB_ROOT . 'inc/functions_user.php';

                add_subscribed_thread(
                    $threadID,
                    (int)(get_user($assignUserID)['subscriptionmethod']) === 2 ? 2 : 1,
                    $assignUserID
                );
            }
        }
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
 */
function threadCategoryUpdate(int $threadID, int $categoryID): void
{
    if ($categoryID === -1) {
        $categoryID = 0;
    }

    threadUpdate(['mysupport_category_id' => $categoryID], $threadID);
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

    $mysupportmodlog = explode(',', settingsGet('modlog'));

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
 * Recount how many technical threads there are in each forum.
 *
 **/
function forumsUpdateTechnicalCount(int $forumID): void
{
    global $db;

    // Fetch the number of threads and replies in this forum (Approved only)
    $query = $db->simple_select(
        'threads',
        'COUNT(tid) AS total_technical_threads',
        "fid='{$forumID}' AND issupportthread='1' AND mysupport_is_technical='" . ThreadStatus::Technical . "'"
    );

    $db->update_query(
        'forums',
        ['technicalthreads' => (int)$db->fetch_array($query, 'total_technical_threads')],
        "fid='{$forumID}'"
    );
}

/**
 * Recount how many threads a user has been assigned.
 **/
function usersUpdateAssignCount(int $userID): void
{
    $totalAssignedThreads = assignGet(
        ["user_id='{$userID}'", "status='" . AssignStatus::Active . "'"],
        ['COUNT(assign_id) as total_assigned_threads'],
        ['limit' => 1]
    )['total_assigned_threads'] ?? 0;

    userUpdate(['assignedthreads' => $totalAssignedThreads], $userID);
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

    $column = match (settingsGet('pointssystem')) {
        'myps' => 'myps',
        'newpoints' => 'newpoints',
        'other' => $db->escape_string(settingsGet('pointssystemcolumn')),
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

function usersGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $query = $db->simple_select(
        'users',
        implode(',', $queryFields),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (!$db->num_rows($query)) {
        return [];
    }

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $objects = [];

    while ($userData = $db->fetch_array($query)) {
        if (isset($userData['uid'])) {
            $objects[(int)$userData['uid']] = $userData;
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

function threadsGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
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
        return [];
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

function contentGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
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
        return [];
    }

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $objects = [];

    while ($rowData = $db->fetch_array($query)) {
        $objects[(int)$rowData['mid']] = $rowData;
    }

    return $objects;
}

function priorityInsert(array $priorityData, int $priorityID = 0, bool $isUpdate = false): int
{
    global $db;

    $insertData = [];

    $insertData['type'] = DATABASE_ROW_TYPE_PRIORITY;

    if (isset($priorityData['name'])) {
        $insertData['name'] = $db->escape_string($priorityData['name']);
    }

    if (isset($priorityData['description'])) {
        $insertData['description'] = $db->escape_string($priorityData['description']);
    }

    if (isset($priorityData['extra'])) {
        $insertData['extra'] = $db->escape_string($priorityData['extra']);
    }

    if (isset($priorityData['allowed_forums'])) {
        $insertData['allowed_forums'] = $db->escape_string($priorityData['allowed_forums']);
    }

    if (isset($priorityData['allowed_groups'])) {
        $insertData['allowed_groups'] = $db->escape_string($priorityData['allowed_groups']);
    }

    if ($isUpdate) {
        $db->update_query('mysupport', $insertData, "mid='{$priorityID}'");

        return $priorityID;
    }

    return (int)$db->insert_query('mysupport', $insertData);
}

function priorityUpdate(array $priorityData, int $priorityID): int
{
    return priorityInsert($priorityData, $priorityID, true);
}

function priorityGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
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

function categoryInsert(array $categoryData, int $categoryID = 0, bool $isUpdate = false): int
{
    global $db;

    $insertData = [];

    $insertData['type'] = DATABASE_ROW_TYPE_PRIORITY;

    if (isset($categoryData['name'])) {
        $insertData['name'] = $db->escape_string($categoryData['name']);
    }

    if (isset($categoryData['display_style'])) {
        $insertData['display_style'] = $db->escape_string($categoryData['display_style']);
    }

    if (isset($categoryData['allowed_forums'])) {
        $insertData['allowed_forums'] = $db->escape_string($categoryData['allowed_forums']);
    }

    if (isset($categoryData['allowed_groups'])) {
        $insertData['allowed_groups'] = $db->escape_string($categoryData['allowed_groups']);
    }

    if ($isUpdate) {
        $db->update_query('mysupport_categories', $insertData, "category_id='{$categoryID}'");

        return $categoryID;
    }

    return (int)$db->insert_query('mysupport_categories', $insertData);
}

function categoryUpdate(array $categoryData, int $categoryID): int
{
    return categoryInsert($categoryData, $categoryID, true);
}

function categoryGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $queryFields[] = 'category_id';

    $query = $db->simple_select(
        'mysupport_categories',
        implode(',', $queryFields),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (!$db->num_rows($query)) {
        return [];
    }

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $objects = [];

    while ($rowData = $db->fetch_array($query)) {
        $objects[(int)$rowData['category_id']] = $rowData;
    }

    return $objects;
}

function categoryDelete(int $categoryID): bool
{
    global $db;

    try {
        $db->delete_query('mysupport_categories', "category_id='{$categoryID}'");
    } catch (Exception $e) {
        return false;
    }

    return true;
}

function deniedReasonInsert(array $deniedReasonData, int $deniedReasonID = 0, bool $isUpdate = false): int
{
    global $db;

    $insertData = [];

    $insertData['type'] = DATABASE_ROW_TYPE_DENIED_REASON;

    if (isset($deniedReasonData['name'])) {
        $insertData['name'] = $db->escape_string($deniedReasonData['name']);
    }

    if (isset($deniedReasonData['description'])) {
        $insertData['description'] = $db->escape_string($deniedReasonData['description']);
    }

    if ($isUpdate) {
        $db->update_query('mysupport', $insertData, "mid='{$deniedReasonID}'");

        return $deniedReasonID;
    }

    return (int)$db->insert_query('mysupport', $insertData);
}

function deniedReasonUpdate(array $deniedReasonData, int $deniedReasonID): int
{
    return deniedReasonInsert($deniedReasonData, $deniedReasonID, true);
}

function deniedReasonGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
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

    $insertData = [];

    $insertData['type'] = DATABASE_ROW_TYPE_BACKUP;

    if (isset($backupData['name'])) {
        $insertData['name'] = $db->escape_string($backupData['name']);
    }

    if (isset($backupData['description'])) {
        $insertData['description'] = $db->escape_string($backupData['description']);
    }

    if (isset($priorityData['extra'])) {
        $insertData['extra'] = $db->escape_string($priorityData['extra']);
    }

    if ($isUpdate) {
        $db->update_query('mysupport', $insertData, "mid='{$backupID}'");

        return $backupID;
    }

    return (int)$db->insert_query('mysupport', $insertData);
}

function backupUpdate(array $backupData, int $backupID): int
{
    return backupInsert($backupData, $backupID, true);
}

function backupGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
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

function assignInsert(array $backupData, int $assignID = 0, bool $isUpdate = false): int
{
    global $db;

    $insertData = [];

    if (isset($backupData['user_id'])) {
        $insertData['user_id'] = (int)$backupData['user_id'];
    }

    if (isset($backupData['assigner_user_id'])) {
        $insertData['assigner_user_id'] = (int)$backupData['assigner_user_id'];
    }

    if (isset($backupData['thread_id'])) {
        $insertData['thread_id'] = (int)$backupData['thread_id'];
    }

    if (isset($backupData['dateline'])) {
        $insertData['dateline'] = (int)$backupData['dateline'];
    }

    if (isset($backupData['status'])) {
        $insertData['status'] = (int)$backupData['status'];
    }

    if ($isUpdate) {
        $db->update_query('mysupport_assigned_threads', $insertData, "mid='{$assignID}'");

        return $assignID;
    }

    return (int)$db->insert_query('mysupport_assigned_threads', $insertData);
}

function assignUpdate(array $assignData, int $assignID): int
{
    return assignInsert($assignData, $assignID, true);
}

function assignGet(array $whereClauses = [], array $queryFields = [], array $queryOptions = []): array
{
    global $db;

    $queryFields[] = 'assign_id';

    $query = $db->simple_select(
        'mysupport_assigned_threads',
        implode(',', $queryFields),
        implode(' AND ', $whereClauses),
        $queryOptions
    );

    if (!$db->num_rows($query)) {
        return [];
    }

    if (isset($queryOptions['limit']) && $queryOptions['limit'] === 1) {
        return (array)$db->fetch_array($query);
    }

    $objects = [];

    while ($rowData = $db->fetch_array($query)) {
        $objects[(int)$rowData['assign_id']] = $rowData;
    }

    return $objects;
}

function generatePrioritiesStyleCode(): string
{
    global $mybb;

    // basically, it's much easier (and neater) to generate makeshift classes for priorities for highlighting threads than adding inline styles
    $prioritiesCache = $mybb->cache->read('mysupport')['priorities'] ?? [];

    if (!$prioritiesCache) {
        return '';
    }

    $cssCode = "\n<style type=\"text/css\">\n";

    foreach ($prioritiesCache as $priorityID => $priorityData) {
        if (!empty($priorityData['extra'])) {
            $cssCode .= '.mySupportPriority_' . priorityClassGetName($priorityID) . " {\n";

            $cssCode .= "\tbackground: #" . preg_replace('/[^A-Za-z0-9 ]/', ' ', $priorityData['extra']) . ";\n";

            $cssCode .= "}\n";
        }
    }

    $cssCode .= "</style>\n";

    return $cssCode;
}

function displayTypeGet(): string
{
    global $mybb;

    $currentUserID = (int)$mybb->user['uid'];

    if ($currentUserID && settingsGet('displaytypeuserchange')) {
        if (!empty($mybb->user['mysupportdisplayastext'])) {
            return 'text';
        } else {
            return 'image';
        }
    }

    return (string)settingsGet('displaytype');
}

function gitHubIssuesPush(string $issueTitle, string $issueBody, string $granularToken): array|false
{
    global $mybb;

    $curlHandler = curl_init();

    $curlOptions = [
        CURLOPT_URL => 'https://api.github.com/repos/OUGC-Network/test/issues',
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'title' => $issueTitle,
            'body' => $issueBody,
            //'assignee' => '',
            //'milestone' => 1,
            //'labels' => ['bug'],
            //'assignees' => [''],
            //'type' => 'issue',
        ])
    ];

    $curlOptions[CURLOPT_HTTPHEADER] = [
        'Authorization: Bearer ' . $granularToken,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: ' . $mybb->settings['bbname'],
    ];

    if ($caBundlePath = get_ca_bundle_path()) {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;

        $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
    } else {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
    }

    curl_setopt_array($curlHandler, $curlOptions);

    $resultData = curl_exec($curlHandler);

    curl_close($curlHandler);

    $issues = json_decode($resultData, true);

    if (!is_array($issues) || empty($issues)) {
        return false;
    }

    return $issues;
}

function gitHubIssuesGet(int $issueID, string $granularToken): array|false
{
    global $mybb;

    $curlHandler = curl_init();

    $curlOptions = [
        CURLOPT_URL => 'https://api.github.com/repos/OUGC-Network/test/issues/' . $issueID,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ];

    $curlOptions[CURLOPT_HTTPHEADER] = [
        'Authorization: Bearer ' . $granularToken,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: ' . $mybb->settings['bbname'],
    ];

    if ($caBundlePath = get_ca_bundle_path()) {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = true;

        $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
    } else {
        $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
    }

    curl_setopt_array($curlHandler, $curlOptions);

    $resultData = curl_exec($curlHandler);

    curl_close($curlHandler);

    $issues = json_decode($resultData, true);

    if (!is_array($issues) || empty($issues)) {
        return false;
    }

    return $issues;
}

function moderationToolUpdateSupportStatus(array|int $threadIDs, int $isSupportStatus): void
{
    $isSupportStatus = match ($isSupportStatus) {
        ThreadStatus::IsSupport => ThreadStatus::IsSupport,
        default => ThreadStatus::IsNotSupport
    };

    $logType = 13;

    global $lang;

    if (!is_array($threadIDs)) {
        $threadIDs = [$threadIDs];
    } else {
        $threadIDs = array_filter(array_unique(array_map('intval', $threadIDs)));
    }

    $whereClauseThreadIDs = implode("','", $threadIDs);

    $whereClauses = [
        "tid IN ('{$whereClauseThreadIDs}')",
        "issupportthread!='{$isSupportStatus}'",
    ];

    foreach (threadsGet($whereClauses) as $threadID => $threadData) {
        threadSupportStatusUpdate($threadID, $isSupportStatus);
    }

    $totalThreads = count($threadIDs);

    if ($totalThreads > 1) {
        mod_log_action(
            $logType,
            $lang->sprintf(
                $isSupportStatus === ThreadStatus::IsSupport ? $lang->is_support_thread_success_multi : $lang->is_not_support_thread_success_multi,
                $totalThreads
            )
        );

        redirect_message(
            $lang->sprintf(
                $isSupportStatus === ThreadStatus::IsSupport ? $lang->is_support_thread_success_multi : $lang->is_not_support_thread_success_multi,
                $totalThreads
            )
        );
    } else {
        mod_log_action(
            $logType,
            $lang->sprintf(
                $isSupportStatus === ThreadStatus::IsSupport ? $lang->is_support_thread_success : $lang->is_not_support_thread_success
            )
        );

        redirect_message(
            $lang->sprintf(
                $isSupportStatus === ThreadStatus::IsSupport ? $lang->is_support_thread_success : $lang->is_not_support_thread_success,
            )
        );
    }
}

function moderationToolUpdateSolveStatus(array|int $threadIDs, int $solveStatus): void
{
    $solveStatus = match ($solveStatus) {
        ThreadStatus::Solved => ThreadStatus::Solved,
        default => ThreadStatus::NotSolved
    };

    $logType = match ($solveStatus) {
        ThreadStatus::Solved => 1,
        default => 0
    };

    global $lang;

    if (!is_array($threadIDs)) {
        $threadIDs = [$threadIDs];
    } else {
        $threadIDs = array_filter(array_unique(array_map('intval', $threadIDs)));
    }

    $newSolveStatusFriendly = threadSolvedFriendlyStatusGet($solveStatus);

    $whereClauseThreadIDs = implode("','", $threadIDs);

    $whereClauses = [
        "tid IN ('{$whereClauseThreadIDs}')",
        "onhold='0'",
        "status!='{$solveStatus}'",
    ];

    foreach (threadsGet($whereClauses) as $threadID => $threadData) {
        threadSolveStatusUpdate($threadID, $solveStatus);
    }

    $totalThreads = count($threadIDs);

    if ($totalThreads > 1) {
        mod_log_action(
            $logType,
            $lang->sprintf($lang->status_change_mod_log_multi, $totalThreads, $newSolveStatusFriendly)
        );

        redirect_message(
            $lang->sprintf(
                $lang->status_change_success_multi,
                $totalThreads,
                htmlspecialchars_uni($newSolveStatusFriendly)
            )
        );
    } else {
        $threadData = get_thread(reset($threadIDs));

        $oldSolveStatusFriendly = threadSolvedFriendlyStatusGet((int)$threadData['status']);

        mod_log_action(
            $logType,
            $lang->sprintf($lang->status_change_mod_log, $newSolveStatusFriendly)
        );

        redirect_message(
            $lang->sprintf(
                $lang->status_change_success,
                htmlspecialchars_uni($oldSolveStatusFriendly),
                htmlspecialchars_uni($newSolveStatusFriendly)
            )
        );
    }
}

function moderationToolUpdateTechnicalStatus(array|int $threadIDs, int $technicalStatus): void
{
    global $mybb;

    $technicalStatus = match ($technicalStatus) {
        ThreadStatus::Technical => ThreadStatus::Technical,
        default => ThreadStatus::NotTechnical
    };

    $logType = match ($technicalStatus) {
        ThreadStatus::Technical => 2,
        default => 4
    };

    global $lang;

    if (!is_array($threadIDs)) {
        $threadIDs = [$threadIDs];
    } else {
        $threadIDs = array_filter(array_unique(array_map('intval', $threadIDs)));
    }

    $newTechnicalStatusNewFriendly = threadTechnicalFriendlyStatusGet($technicalStatus);

    $whereClauseThreadIDs = implode("','", $threadIDs);

    $whereClauses = [
        "tid IN ('{$whereClauseThreadIDs}')",
        "onhold='0'",
        "mysupport_is_technical!='{$technicalStatus}'",
    ];

    $forumIDs = [];

    foreach (threadsGet($whereClauses, ['tid', 'fid']) as $threadID => $threadData
    ) {
        $forumIDs[(int)$threadData['fid']] = (int)$threadData['fid'];

        threadTechnicalStatusUpdate($threadID, $technicalStatus);
    }

    foreach ($forumIDs as $forumID) {
        forumsUpdateTechnicalCount($forumID);

        $mybb->cache->update_forums();
    }

    $totalThreads = count($threadIDs);

    if ($totalThreads > 1) {
        mod_log_action(
            $logType,
            $lang->sprintf($lang->status_change_mod_log_multi, $totalThreads, $newTechnicalStatusNewFriendly)
        );

        redirect_message(
            $lang->sprintf(
                $lang->status_change_success_multi,
                $totalThreads,
                htmlspecialchars_uni($newTechnicalStatusNewFriendly)
            )
        );
    } else {
        $threadData = get_thread(reset($threadIDs));

        $oldTechnicalStatusFriendly = threadTechnicalFriendlyStatusGet((int)$threadData['mysupport_is_technical']);

        mod_log_action($logType, $lang->sprintf($lang->status_change_mod_log, $newTechnicalStatusNewFriendly));

        redirect_message(
            $lang->sprintf(
                $lang->status_change_success,
                htmlspecialchars_uni($oldTechnicalStatusFriendly),
                htmlspecialchars_uni($newTechnicalStatusNewFriendly)
            )
        );
    }
}

function moderationToolUpdateOnholdStatus(array|int $threadIDs, int $onholdStatus): void
{
    $onholdStatus = match ($onholdStatus) {
        ThreadStatus::Onhold => ThreadStatus::Onhold,
        default => ThreadStatus::NotOnhold
    };

    $logType = 12;

    global $lang;

    if (!is_array($threadIDs)) {
        $threadIDs = [$threadIDs];
    } else {
        $threadIDs = array_filter(array_unique(array_map('intval', $threadIDs)));
    }

    $whereClauseThreadIDs = implode("','", $threadIDs);

    $whereClauses = [
        "tid IN ('{$whereClauseThreadIDs}')",
        "onhold!='{$onholdStatus}'",
    ];

    // when changing the hold status via the form in a thread, you can't you can't change the hold status if the thread's solved
    // here, it's not as easy to check for that; instead, only change the hold status if the thread isn't solved
    if ($onholdStatus === ThreadStatus::Onhold) {
        $whereClauses[] = "status!='1'";
    }

    foreach (threadsGet($whereClauses) as $threadID => $threadData) {
        threadOnholdStatusUpdate($threadID, $onholdStatus);
    }

    $totalThreads = count($threadIDs);

    if ($totalThreads > 1) {
        mod_log_action(
            $logType,
            $lang->sprintf(
                $onholdStatus === ThreadStatus::Onhold ? $lang->hold_on_success_multi : $lang->hold_off_success_multi,
                $totalThreads
            )
        );

        redirect_message(
            $lang->sprintf(
                $onholdStatus === ThreadStatus::Onhold ? $lang->hold_on_success_multi : $lang->hold_off_success_multi,
                $totalThreads
            )
        );
    } else {
        mod_log_action(
            $logType,
            $lang->sprintf(
                $onholdStatus === ThreadStatus::Onhold ? $lang->hold_on_success : $lang->hold_off_success
            )
        );

        redirect_message(
            $lang->sprintf(
                $onholdStatus === ThreadStatus::Onhold ? $lang->hold_on_success : $lang->hold_off_success,
            )
        );
    }
}

function moderationToolUpdatePriorityStatus(array|int $threadIDs, int $priorityID): void
{
    global $mybb;

    $logType = match ($priorityID) {
        INPUT_TOGGLE_NOBODY_NONE => 8,
        default => 7
    };

    if ($priorityID === INPUT_TOGGLE_NOBODY_NONE) {
        $priorityID = 0;
    }

    global $lang;

    if (!is_array($threadIDs)) {
        $threadIDs = [$threadIDs];
    } else {
        $threadIDs = array_filter(array_unique(array_map('intval', $threadIDs)));
    }

    $prioritiesCache = $mybb->cache->read('mysupport')['priorities'] ?? [];

    $newPriorityName = $prioritiesCache[$priorityID]['name'] ?? '';

    $whereClauseThreadIDs = implode("','", $threadIDs);

    $whereClauses = [
        "tid IN ('{$whereClauseThreadIDs}')",
        "onhold='0'",
        "priority!='{$priorityID}'",
    ];

    if (!$priorityID) {
        $whereClauses[] = "priority!='0'";
    }

    foreach (threadsGet($whereClauses) as $threadID => $threadData) {
        threadPriorityUpdate($threadID, $priorityID);
    }

    $totalThreads = count($threadIDs);

    if ($totalThreads > 1) {
        mod_log_action(
            $logType,
            $lang->sprintf(
                $priorityID ? $lang->priority_change_success_to_multi : $lang->priority_remove_success_multi,
                $totalThreads,
                $newPriorityName
            )
        );

        redirect_message(
            $lang->sprintf(
                $priorityID ? $lang->priority_change_success_to_multi : $lang->priority_remove_success_multi,
                $totalThreads,
                $newPriorityName
            )
        );
    } else {
        $threadData = get_thread(reset($threadIDs));

        $oldPriorityName = '';

        if (!empty($threadData['priority'])) {
            $oldPriorityName = $prioritiesCache[(int)$threadData['priority']]['name'] ?? '';
        }

        mod_log_action(
            $logType,
            $lang->sprintf(
                $priorityID ? $lang->priority_change_success_to : $lang->priority_remove_success,
                $oldPriorityName ?? $lang->priority_change_success_to_none,
                $newPriorityName
            )
        );

        redirect_message(
            $lang->sprintf(
                $priorityID ? $lang->priority_change_success_to : $lang->priority_remove_success,
                $oldPriorityName ?? $lang->priority_change_success_to_none,
                $newPriorityName
            )
        );
    }
}

function moderationToolUpdateCategoryStatus(array|int $threadIDs, int $categoryID): void
{
    global $mybb;

    $logType = match ($categoryID) {
        INPUT_TOGGLE_NOBODY_NONE => 10,
        default => 9
    };

    if ($categoryID === INPUT_TOGGLE_NOBODY_NONE) {
        $categoryID = 0;
    }

    global $lang;

    if (!is_array($threadIDs)) {
        $threadIDs = [$threadIDs];
    } else {
        $threadIDs = array_filter(array_unique(array_map('intval', $threadIDs)));
    }

    $categoriesCache = $mybb->cache->read('mysupport')['priorities'] ?? [];

    $newCategoryName = $categoriesCache[$categoryID]['name'] ?? '';

    $whereClauseThreadIDs = implode("','", $threadIDs);

    $whereClauses = [
        "tid IN ('{$whereClauseThreadIDs}')",
        "onhold='0'",
        "mysupport_category_id!='{$categoryID}'",
    ];

    if (!$categoryID) {
        $whereClauses[] = "mysupport_category_id!='0'";
    }

    foreach (threadsGet($whereClauses) as $threadID => $threadData) {
        threadCategoryUpdate($threadID, $categoryID);
    }

    $totalThreads = count($threadIDs);

    if ($totalThreads > 1) {
        mod_log_action(
            $logType,
            $lang->sprintf(
                $categoryID ? $lang->priority_change_success_to_multi : $lang->priority_remove_success_multi,
                $totalThreads,
                $newCategoryName
            )
        );

        redirect_message(
            $lang->sprintf(
                $categoryID ? $lang->priority_change_success_to_multi : $lang->priority_remove_success_multi,
                $totalThreads,
                $newCategoryName
            )
        );
    } else {
        $threadData = get_thread(reset($threadIDs));

        $oldCategoryName = '';

        if (!empty($threadData['mysupport_category_id'])) {
            $oldCategoryName = $categoriesCache[(int)$threadData['mysupport_category_id']]['name'] ?? '';
        }

        mod_log_action(
            $logType,
            $lang->sprintf(
                $categoryID ? $lang->priority_change_success_to : $lang->priority_remove_success,
                $oldCategoryName ?? $lang->priority_change_success_to_none,
                $newCategoryName
            )
        );

        redirect_message(
            $lang->sprintf(
                $categoryID ? $lang->priority_change_success_to : $lang->priority_remove_success,
                $oldCategoryName ?? $lang->priority_change_success_to_none,
                $newCategoryName
            )
        );
    }
}

function moderationToolPushToGitHub(int $threadID, string $granularToken): void
{
    global $mybb, $lang;

    languageLoad();

    $threadData = get_thread($threadID);

    $postData = get_post($threadData['firstpost']);

    $forumData = get_forum($postData['fid']);

    $issueData = gitHubIssuesPush(
        $threadData['subject'],
        parserMarkdown(
            parser()->parse_badwords(
                $postData['message']
            ),
            !empty($forumData['allowhtml']),
            !empty($forumData['allowimgcode'])
        ) . "\n\n" . $lang->sprintf(
            $lang->mySupportModerationToolPushToGitHubBodyFooter,
            $mybb->settings['bburl'],
            get_thread_link($threadID),
        ),
        $granularToken
    );

    if (!empty($issueData['html_url'])) {
        global $db;

        threadUpdate([
            'mysupport_issue_url' => $db->escape_string($issueData['html_url'])
        ], $threadID);
    }
}

function parser(): postParser
{
    static $parser;

    if (!($parser instanceof postParser)) {
        require_once MYBB_ROOT . '/inc/class_parser.php';

        $parser = new postParser();
    }

    return $parser;
}

function parserMarkdown(string $message, bool $allowHTML = false, bool $allowImageCode = false): string
{
    global $mybb;
    global $mySupportAllowHTML;

    $mySupportAllowHTML = $allowHTML;

    (function () use (&$message): string {
        // Assign pattern and replace values.
        $pattern = "#\[quote\](.*?)\[\/quote\](\r\n?|\n?)#si";

        $pattern_callback = "#\[quote=([\"']|&quot;|)(.*?)(?:\\1)(.*?)(?:[\"']|&quot;)?\](.*?)\[/quote\](\r\n?|\n?)#si";

        $replace = '> $1 [hr]';

        $replace_callback = function (array $matches): string {
            return '> ' . trim($matches[4]) . ' [hr]';
        };

        do {
            // preg_replace has erased the message? Restore it...
            $previous_message = $message;

            $message = preg_replace($pattern, $replace, $message, -1, $count);

            $message = preg_replace_callback($pattern_callback, $replace_callback, $message, -1, $count_callback);

            if (!$message) {
                $message = $previous_message;

                break;
            }
        } while ($count || $count_callback);

        return $message;
    })();

    $basicReplacements = $callbackReplacements = [];

    if (!empty($mybb->settings['allowbasicmycode'])) {
        $basicReplacements = array_merge($basicReplacements, [
            '#\[b\](.*?)\[/b\]#si' => '**$1**',
            '#\[u\](.*?)\[/u\]#si' => '<ins>$1</ins>',
            '#\[i\](.*?)\[/i\]#si' => '*$1*',
            '#\[s\](.*?)\[/s\]#si' => '~$1~',
            '#\[hr\]#si' => '<hr />',
        ]);
    }

    if (!empty($mybb->settings['allowcolormycode'])) {
        $basicReplacements = array_merge($basicReplacements, [
            '#\[color=([a-zA-Z]*|\#?[\da-fA-F]{3}|\#?[\da-fA-F]{6})](.*?)\[/color\]#si' => '$2',
        ]);
    }

    if (!empty($mybb->settings['allowsizemycode'])) {
        $basicReplacements = array_merge($basicReplacements, [
            '#\[size=(xx-small|x-small|small|medium|large|x-large|xx-large)\](.*?)\[/size\]#si' => '$2',
        ]);
    }

    if (!empty($mybb->settings['allowalignmycode'])) {
        $basicReplacements = array_merge($basicReplacements, [
            '#\[align=(left|center|right|justify)\](.*?)\[/align\]#si' => '$2',
        ]);
    }

    foreach ($basicReplacements as $myCodeReGex => $myCodeReplacement) {
        $message = preg_replace($myCodeReGex, $myCodeReplacement, $message);
    }

    if (!empty($mybb->settings['allowlinkmycode'])) {
        $callbackReplacements = array_merge($callbackReplacements, [
            '#\[url\]((?!javascript)[a-z]+?://)([^\r\n\"<]+?)\[/url\]#si' => '\MySupport\Core\parserMarkdownUrlSimple',
            '#\[url\]((?!javascript:)[^\r\n\"<]+?)\[/url\]#i' => '\MySupport\Core\parserMarkdownUrlComplex',
            '#\[url=((?!javascript)[a-z]+?://)([^\r\n\"<]+?)\](.+?)\[/url\]#si' => '\MySupport\Core\parserMarkdownUrlSimple',
            '#\[url=((?!javascript:)[^\r\n\"<]+?)\](.+?)\[/url\]#si' => '\MySupport\Core\parserMarkdownUrlComplex',
            '#\[email\]((?:[a-zA-Z0-9-_\+\.]+?)@[a-zA-Z0-9-]+\.[a-zA-Z0-9\.-]+(?:\?.*?)?)\[/email\]#i' => '\MySupport\Core\parserMarkdownEmail',
            '#\[email=((?:[a-zA-Z0-9-_\+\.]+?)@[a-zA-Z0-9-]+\.[a-zA-Z0-9\.-]+(?:\?.*?)?)\](.*?)\[/email\]#i' => '\MySupport\Core\parserMarkdownEmail',
        ]);
    }

    if (!empty($mybb->settings['allowsizemycode'])) {
        $callbackReplacements = array_merge($callbackReplacements, [
            '#\[size=([0-9\+\-]+?)\](.*?)\[/size\]#si' => function (array $matches): string {
                return str_replace("\'", "'", $matches[2]);
            },
        ]);
    }

    if (!empty($mybb->settings['allowfontmycode'])) {
        $callbackReplacements = array_merge($callbackReplacements, [
            "#\[font=\\s*(\"?)([a-z0-9 ,\-_'\"]+)\\1\\s*\](.*?)\[/font\]#si" => function (array $matches): string {
                return $matches[3];
            },
        ]);
    }

    if ($allowImageCode) {
        $callbackReplacements = array_merge($callbackReplacements, [
            "#\[img\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is" => '\MySupport\Core\parserMarkdownImageSimple',
            "#\[img=([1-9][0-9]*)x([1-9][0-9]*)\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is" => '\MySupport\Core\parserMarkdownImageDimensions',
            "#\[img align=(left|right)\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is" => '\MySupport\Core\parserMarkdownImageAlign',
            "#\[img=([1-9][0-9]*)x([1-9][0-9]*) align=(left|right)\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is" => '\MySupport\Core\parserMarkdownImageDimensionsAlign',
        ]);
    } else {
        $callbackReplacements = array_merge($callbackReplacements, [
            "#\[img\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is" => '\MySupport\Core\parserMarkdownImageDisabledSimple',
            "#\[img=([1-9][0-9]*)x([1-9][0-9]*)\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is" => '\MySupport\Core\parserMarkdownImageDisabledDimensions',
            "#\[img align=(left|right)\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is" => '\MySupport\Core\parserMarkdownDisabledImageAlign',
            "#\[img=([1-9][0-9]*)x([1-9][0-9]*) align=(left|right)\](\r\n?|\n?)(https?://([^<>\"']+?))\[/img\]#is" => '\MySupport\Core\parserMarkdownImageDisabledDimensionsAlign',
        ]);
    }

    foreach ($callbackReplacements as $myCodeReGex => $myCodeReplacement) {
        $message = preg_replace_callback($myCodeReGex, $myCodeReplacement, $message);
    }

    // Reset list cache
    if (!empty($mybb->settings['allowlistmycode'])) {
        global $mySupportListElements, $mySupportListCount;

        $mySupportListElements = [];

        $mySupportListCount = 0;

        // Find all lists
        $message = preg_replace_callback(
            '#(\[list(=(a|A|i|I|1))?\]|\[/list\])#si',
            function (array $matches) {
                global $mySupportListElements, $mySupportListCount;

                // Append number to identify matching list tags
                if (strcasecmp($matches[1], '[/list]') == 0) {
                    $count = array_pop($mySupportListElements);

                    if ($count !== null) {
                        return "[/list&{$count}]";
                    } else {
                        // No open list tag...
                        return $matches[0];
                    }
                } else {
                    ++$mySupportListCount;

                    $mySupportListElements[] = $mySupportListCount;

                    if (!empty($matches[2])) {
                        return "[list{$matches[2]}&{$mySupportListCount}]";
                    } else {
                        return "[list&{$mySupportListCount}]";
                    }
                }
            },
            $message
        );

        // Replace all lists
        for ($i = $mySupportListCount; $i > 0; $i--) {
            // Ignores missing end tags
            $message = preg_replace_callback(
                "#\s?\[list(=(a|A|i|I|1))?&{$i}\](.*?)(\[/list&{$i}\]|$)(\r\n?|\n?)#si",
                function (array $matches) {
                    $message = $matches[3];

                    $type = $matches[2];

                    // No list elements? That's invalid HTML
                    if (!str_contains($message, '[*]')) {
                        $message = "[*]{$message}";
                    }

                    $message = preg_split("#[^\S\n\r]*\[\*\]\s*#", $message);

                    if (isset($message[0]) && trim($message[0]) == '') {
                        array_shift($message);
                    }

                    $message = '- ' . implode("\n", $message) . "\n";

                    return preg_replace("#<(ol type=\"$type\"|ul)>\s*</li>#", '', $message);
                },
                $message,
                1
            );
        }
    }

    return $message;
}

/**
 * Parses email MyCode.
 *
 * @param array $matches Matches
 * @return string The built-up email link.
 */
function parserMarkdownEmail(array $matches): string
{
    $email = $name = parser()->encode_url($matches[1]);

    if (!empty($matches[2])) {
        $name = $matches[2];
    }

    return '[' . $name . '](' . $email . ')';
}

/**
 * Parses URL MyCode.
 *
 * @param string $url The URL to link to.
 * @param string $name The name of the link.
 * @return string The built-up link.
 */
function parserMarkdownUrl(string $url, string $name = ''): string
{
    if (!preg_match('#^[a-z0-9]+://#i', $url)) {
        $url = 'https://' . $url;
    }

    global $mySupportAllowHTML;

    if (!empty($mySupportAllowHTML)) {
        $url = parser()->parse_html($url);
    }

    if (!$name) {
        $name = $url;
    }

    // Fix some entities in URLs
    $url = parser()->encode_url($url);

    $name = parser()->parse_badwords(
        preg_replace('#&amp;\#([0-9]+);#si', '&#$1;', $name)
    ); // Fix & but allow Unicode, filter bad words

    return '[' . $name . '](' . $url . ')';
}

/**
 * Parses URL MyCode.
 *
 * @param array $matches Matches.
 * @return string The built-up link.
 */
function parserMarkdownUrlSimple(array $matches): string
{
    if (!isset($matches[3])) {
        $matches[3] = '';
    }

    return parserMarkdownUrl($matches[1] . $matches[2], $matches[3]);
}

/**
 * Parses URL MyCode.
 *
 * @param array $matches Matches.
 * @return string The built-up link.
 */
function parserMarkdownUrlComplex(array $matches): string
{
    if (!isset($matches[2])) {
        $matches[2] = '';
    }

    return parserMarkdownUrl($matches[1], $matches[2]);
}

/**
 * Parses IMG MyCode.
 *
 * @param string $url The URL to the image
 * @param array $dimensions Optional array of dimensions
 * @return string
 */
function parserMarkdownImage(string $url, array $dimensions = []): string
{
    global $lang;

    $url = trim($url);

    $url = str_replace("\n", '', $url);

    $url = str_replace("\r", '', $url);

    if (!empty($mySupportAllowHTML)) {
        $url = parser()->parse_html($url);
    }

    $alt = basename($url);

    $alt = htmlspecialchars_decode($alt);

    if (my_strlen($alt) > 55) {
        $alt = my_substr($alt, 0, 40) . '...' . my_substr($alt, -10);
    }

    $alt = parser()->encode_url($alt);

    $alt = preg_replace('#&(?!\#[0-9]+;)#si', '&amp;', $alt); // fix & but allow Unicode

    $alt = $lang->sprintf($lang->posted_image, $alt);

    $width = $height = '';

    if (isset($dimensions[0]) && $dimensions[0] > 0 && isset($dimensions[1]) && $dimensions[1] > 0) {
        $width = " width=\"{$dimensions[0]}\"";

        $height = " height=\"{$dimensions[1]}\"";
    }

    $url = parser()->encode_url($url);

    return '<img src="' . $url . '" ' . $width . $height . ' alt="' . $alt . '" />';
}

/**
 * Parses IMG MyCode.
 *
 * @param array $matches Matches.
 * @return string Image code.
 */
function parserMarkdownImageSimple(array $matches): string
{
    return parserMarkdownImage($matches[2]);
}

/**
 * Parses IMG MyCode.
 *
 * @param array $matches Matches.
 * @return string Image code.
 */
function parserMarkdownImageDimensions(array $matches): string
{
    return parserMarkdownImage($matches[4], [$matches[1], $matches[2]]);
}

/**
 * Parses IMG MyCode.
 *
 * @param array $matches Matches.
 * @return string Image code.
 */
function parserMarkdownImageAlign(array $matches): string
{
    return parserMarkdownImage($matches[3]);
}

/**
 * Parses IMG MyCode.
 *
 * @param array $matches Matches.
 * @return string Image code.
 */
function parserMarkdownImageDimensionsAlign(array $matches): string
{
    return parserMarkdownImage($matches[5], [$matches[1], $matches[2]]);
}

/**
 * Parses IMG MyCode disabled.
 *
 * @param string $url The URL to the image
 * @return string
 */
function parserMarkdownImageDisabled(string $url): string
{
    global $lang;

    $url = trim($url);

    $url = str_replace("\n", '', $url);

    $url = str_replace("\r", '', $url);

    $url = str_replace("\'", "'", $url);

    return $lang->sprintf($lang->posted_image, parserMarkdownUrl($url));
}

/**
 * Parses IMG MyCode disabled.
 *
 * @param array $matches Matches.
 * @return string Image code.
 */
function parserMarkdownImageDisabledSimple(array $matches): string
{
    return parserMarkdownImageDisabled($matches[2]);
}

/**
 * Parses IMG MyCode disabled.
 *
 * @param array $matches Matches.
 * @return string Image code.
 */
function parserMarkdownImageDisabledDimensions(array $matches): string
{
    return parserMarkdownImageDisabled($matches[4]);
}

/**
 * Parses IMG MyCode disabled.
 *
 * @param array $matches Matches.
 * @return string Image code.
 */
function parserMarkdownImageDisabledAlign(array $matches): string
{
    return parserMarkdownImageDisabled($matches[3]);
}

/**
 * Parses IMG MyCode disabled.
 *
 * @param array $matches Matches.
 * @return string Image code.
 */
function parserMarkdownImageDisabledDimensionsAlign(array $matches): string
{
    return parserMarkdownImageDisabled($matches[5]);
}

// control_object by Zinga Burga from MyBBHacks ( mybbhacks.zingaburga.com )
function control_object(&$obj, string $code): void
{
    static $cnt = 0;

    $newname = '_objcont_mysupport_' . (++$cnt);

    $objserial = serialize($obj);

    $classname = get_class($obj);

    $checkstr = 'O:' . strlen($classname) . ':"' . $classname . '":';

    $checkstr_len = strlen($checkstr);

    if (substr($objserial, 0, $checkstr_len) == $checkstr) {
        $vars = [];

        // grab resources/object etc, stripping scope info from keys
        foreach ((array)$obj as $k => $v) {
            if ($p = strrpos($k, "\0")) {
                $k = substr($k, $p + 1);
            }

            $vars[$k] = $v;
        }

        if (!empty($vars)) {
            $code .= '
					function ___setvars(&$a) {
						foreach($a as $k => &$v)
							$this->$k = $v;
					}
				';
        }

        eval('class ' . $newname . ' extends ' . $classname . ' {' . $code . '}');

        $obj = unserialize('O:' . strlen($newname) . ':"' . $newname . '":' . substr($objserial, $checkstr_len));

        if (!empty($vars)) {
            $obj->___setvars($vars);
        }
    }
    // else not a valid object or PHP serialize has changed
}

// explicit workaround for PDO, as trying to serialize it causes a fatal error (even though PHP doesn't complain over serializing other resources)
if ($GLOBALS['db'] instanceof AbstractPdoDbDriver) {
    $GLOBALS['AbstractPdoDbDriver_lastResult_prop'] = new ReflectionProperty('AbstractPdoDbDriver', 'lastResult');

    $GLOBALS['AbstractPdoDbDriver_lastResult_prop']->setAccessible(true);

    function control_db(string $code): void
    {
        global $db;

        $linkvars = [
            'read_link' => $db->read_link,
            'write_link' => $db->write_link,
            'current_link' => $db->current_link,
        ];

        unset($db->read_link, $db->write_link, $db->current_link);

        $lastResult = $GLOBALS['AbstractPdoDbDriver_lastResult_prop']->getValue($db);

        $GLOBALS['AbstractPdoDbDriver_lastResult_prop']->setValue($db, null); // don't let this block serialization

        control_object($db, $code);

        foreach ($linkvars as $k => $v) {
            $db->$k = $v;
        }

        $GLOBALS['AbstractPdoDbDriver_lastResult_prop']->setValue($db, $lastResult);
    }
} elseif ($GLOBALS['db'] instanceof DB_SQLite) {
    function control_db(string $code): void
    {
        global $db;

        $oldLink = $db->db;

        unset($db->db);

        control_object($db, $code);

        $db->db = $oldLink;
    }
} else {
    function control_db(string $code): void
    {
        control_object($GLOBALS['db'], $code);
    }
}
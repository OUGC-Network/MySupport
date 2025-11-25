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

namespace MySupport\Hooks\Shared;

use MySupport\Core\ThreadStatus;

use function MySupport\Core\moderationToolPushToGitHub;

use function MySupport\Core\moderationToolUpdateCategoryStatus;
use function MySupport\Core\moderationToolUpdateOnholdStatus;
use function MySupport\Core\moderationToolUpdatePriorityStatus;
use function MySupport\Core\moderationToolUpdateSolveStatus;

use function MySupport\Core\moderationToolUpdateSupportStatus;
use function MySupport\Core\moderationToolUpdateTechnicalStatus;

use const MySupport\Core\INPUT_NO_CHANGE;
use const MySupport\Core\INPUT_TOGGLE_NOBODY_NONE;

function class_custommoderation_execute_thread_moderation_start(array &$hookArguments): array
{
    $threadIDs = array_map('intval', $hookArguments['tids']);

    foreach ($threadIDs as $threadID) {
        $threadData = get_thread($threadID);

        if (isset($hookArguments['thread_options']['mySupportSupportStatus'])) {
            switch ((int)$hookArguments['thread_options']['mySupportSupportStatus']) {
                case ThreadStatus::IsSupport:
                    moderationToolUpdateSupportStatus($threadID, ThreadStatus::IsSupport);

                    break;
                case ThreadStatus::IsNotSupport:
                    moderationToolUpdateSupportStatus($threadID, ThreadStatus::IsNotSupport);

                    break;
                case INPUT_TOGGLE_NOBODY_NONE;
                    moderationToolUpdateSupportStatus($threadID, (int)empty($threadData['issupportthread']));
            }
        }

        if (isset($hookArguments['thread_options']['mySupportSolveStatus'])) {
            switch ((int)$hookArguments['thread_options']['mySupportSolveStatus']) {
                case ThreadStatus::Solved:
                    moderationToolUpdateSolveStatus($threadID, ThreadStatus::Solved);

                    break;
                case ThreadStatus::NotSolved:
                    moderationToolUpdateSolveStatus($threadID, ThreadStatus::NotSolved);

                    break;
                case INPUT_TOGGLE_NOBODY_NONE;
                    moderationToolUpdateSolveStatus($threadID, (int)empty($threadData['status']));
            }
        }

        if (isset($hookArguments['thread_options']['mySupportTechnicalStatus'])) {
            switch ((int)$hookArguments['thread_options']['mySupportTechnicalStatus']) {
                case ThreadStatus::Technical:
                    moderationToolUpdateTechnicalStatus($threadID, ThreadStatus::Technical);

                    break;
                case ThreadStatus::NotTechnical:
                    moderationToolUpdateTechnicalStatus($threadID, ThreadStatus::NotTechnical);

                    break;
                case INPUT_TOGGLE_NOBODY_NONE;
                    moderationToolUpdateTechnicalStatus($threadID, (int)empty($threadData['mysupport_is_technical']));
            }
        }

        if (isset($hookArguments['thread_options']['mySupportOnholdStatus'])) {
            switch ((int)$hookArguments['thread_options']['mySupportOnholdStatus']) {
                case ThreadStatus::Onhold:
                    moderationToolUpdateOnholdStatus($threadID, ThreadStatus::Onhold);

                    break;
                case ThreadStatus::NotOnhold:
                    moderationToolUpdateOnholdStatus($threadID, ThreadStatus::NotOnhold);

                    break;
                case INPUT_TOGGLE_NOBODY_NONE;
                    moderationToolUpdateOnholdStatus($threadID, (int)empty($threadData['onhold']));
            }
        }

        if (isset($hookArguments['thread_options']['mySupportAssignee']) &&
            (int)$hookArguments['thread_options']['mySupportAssignee'] == INPUT_TOGGLE_NOBODY_NONE) {
            moderationToolUpdateOnholdStatus($threadID, (int)empty($threadData['onhold']));
        }

        global $mybb;

        if (isset($hookArguments['thread_options']['mySupportPriority'])) {
            $priorityID = (int)$hookArguments['thread_options']['mySupportPriority'];

            $prioritiesCache = $mybb->cache->read('mysupport')['priorities'] ?? [];

            if (!(empty($prioritiesCache) || empty($prioritiesCache[$priorityID])) ||
                $priorityID === INPUT_TOGGLE_NOBODY_NONE) {
                moderationToolUpdatePriorityStatus($threadID, $priorityID);
            }
        }

        if (isset($hookArguments['thread_options']['mySupportCategory'])) {
            $categoryID = (int)$hookArguments['thread_options']['mySupportCategory'];

            $categoriesCache = $mybb->cache->read('mysupport')['categories'] ?? [];

            if (!(empty($categoriesCache) || empty($categoriesCache[$categoryID])) ||
                $categoryID === INPUT_TOGGLE_NOBODY_NONE) {
                moderationToolUpdateCategoryStatus($threadID, $categoryID);
            }
        }

        // perhaps patch instead?
        if (!empty($hookArguments['thread_options']['mySupportPushToGitHub']) &&
            empty($threadData['mysupport_issue_url'])) {
            moderationToolPushToGitHub($threadID, $hookArguments['thread_options']['mySupportPushToGitHub']);
        }
    }

    return $hookArguments;
}
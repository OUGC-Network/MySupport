<?php

/**
 * MySupport 1.8.0 - Task File
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

use function MySupport\Core\threadStatusUpdate;
use function MySupport\Core\backupDelete;
use function MySupport\Core\backupInsert;
use function MySupport\Core\enabledForums;
use function MySupport\Core\backupGet;
use function MySupport\Core\languageLoad;
use function MySupport\Core\priorityInsert;
use function MySupport\Core\threadsGet;

use const MySupport\Admin\FIELDS_DATA;
use const MySupport\Core\DATABASE_ROW_TYPE_BACKUP;

function task_mysupport(array $task): array
{
    global $mybb, $db, $lang;

    languageLoad();

    $task_log = $lang->task_mysupport_ran;

    // if this is empty or 0, it'll affect all threads
    if ($mybb->settings['mysupport_taskautosolvetime'] > 0) {
        $cut = TIME_NOW - intval($mybb->settings['mysupport_taskautosolvetime']);
        $mysupport_forums = implode("','", array_map('intval', enabledForums()));

        $threads_solved = false;

        $tids = [];

        // are there any MySupport forums?
        if (!empty($mysupport_forums)) {
            // Select all the unsolved threads in MySupport forums where the last post was before the cut-off time. Either the status time is before the cut-off time, or the status of the thread has never been changed
            // this means it's not been posted in, and no MySupport actions have taken place on it, within the cut-off time
            $threadObjects = threadsGet(
                [
                    "status!='1'",
                    "fid IN ('{$mysupport_forums}')",
                    "lastpost<'$cut'",
                    "(statustime<'" . $cut . "' OR statustime=0')"
                ],
                ['tid'],
            );

            foreach ($threadObjects as $thread) {
                $tids[] = $thread['tid'];
            }

            // if there are any threads to mark as solved
            if (!empty($tids)) {
                threadStatusUpdate($tids, 1, true);

                $threads_solved = true;
            }
        }

        if ($threads_solved) {
            $task_log .= $lang->sprintf($lang->task_mysupport_autosolve_count, count($tids));
        }
    }

    if ($mybb->settings['mysupport_taskbackup'] > 0) {
        $timecut = TIME_NOW - $mybb->settings['mysupport_taskbackup'];

        // no backups have been made within the cut-off time
        if (!backupGet(["extra>'{$timecut}'"])) {
            if (!defined('MYBB_ADMIN_DIR')) {
                if (!isset($config['admin_dir'])) {
                    $config['admin_dir'] = 'admin';
                }

                define('MYBB_ADMIN_DIR', MYBB_ROOT . $config['admin_dir'] . '/');
            }

            if (is_writable(MYBB_ADMIN_DIR . 'backups')) {
                $currentUserID = (int)$mybb->user['uid'];

                $name = substr(md5($currentUserID . TIME_NOW), 0, 10) . random_str(54);
                $file = MYBB_ADMIN_DIR . 'backups/mysupport_backup_' . $name . '.sql';

                $f = fopen($file, 'w');
                fwrite($f, "<?php\n");
                fwrite(
                    $f,
                    "/**\n * Backup of MySupport data\n * Generated: " . date(
                        "dS F Y \a\\t H:i",
                        TIME_NOW
                    ) . "\n * Only to be imported via the MySupport backup importer.\n**/\n\n"
                );

                foreach (FIELDS_DATA as $tableName => $tableFields) {
                    $id_field = null;

                    switch ($tableName) {
                        case 'forums':
                            $id_field = 'fid';
                            break;
                        case 'threads':
                            $id_field = 'tid';
                            break;
                        case 'users':
                            $id_field = 'uid';
                            break;
                        case 'usergroups':
                            $id_field = 'gid';
                            break;
                    }

                    if (empty($id_field)) {
                        continue;
                    }

                    $tableFields = implode(', ', array_map($db->escape_string, array_keys($tableFields)));
                    $query = $db->simple_select($tableName, $id_field . ',' . $tableFields);
                    $tableFields = explode(', ', $tableFields);
                    while ($r = $db->fetch_array($query)) {
                        $set = '';
                        foreach ($tableFields as $fieldName => $definition) {
                            if (!empty($set)) {
                                $set .= ', ';
                            }
                            $set .= '`' . $fieldName . "` = '" . $r[$fieldName] . "'";
                        }
                        $q = "\$queries[] = \"UPDATE " . TABLE_PREFIX . $tableName . ' SET ' . $set . ' WHERE `' . $id_field . "` = '" . $r[$id_field] . "'\";\n";
                        fwrite($f, $q);
                    }
                }

                foreach (backupGet() as $r) {
                    $keys = [];
                    $vals = [];
                    foreach ($r as $key => $val) {
                        $keys[] = '`' . $key . '`';
                        $vals[] = "'" . $val . "'";
                    }
                    $q = "\$queries[] = \"INSERT INTO " . TABLE_PREFIX . 'mysupport (' . implode(
                            ',',
                            $keys
                        ) . ') VALUES (' . implode(
                            ',',
                            $vals
                        ) . ")\";\n";
                    fwrite($f, $q);
                }

                fwrite($f, '?>');
                fclose($f);

                $insert = [
                    'type' => DATABASE_ROW_TYPE_BACKUP,
                    'name' => $db->escape_string($name),
                    'extra' => TIME_NOW
                ];

                backupInsert($insert);

                // get the latest 3 backups
                $backups = [0];
                foreach (
                    backupGet(queryOptions: [
                        'order_by' => 'extra',
                        'order_dir' => 'DESC',
                        'limit' => 3
                    ]) as $backup
                ) {
                    $backups[] = $backup['mid'];
                }
                $backups = implode("','", array_map('intval', $backups));

                // select all the backups that aren't the last 3

                foreach (backupGet(["mid NOT IN ('{$db->escape_string($backups)}')"]) as $backupID => $backup) {
                    if (file_exists(MYBB_ADMIN_DIR . 'backups/mysupport_backup_' . $backup['name'] . '.sql')) {
                        unlink(MYBB_ADMIN_DIR . 'backups/mysupport_backup_' . $backup['name'] . '.sql');
                    }

                    backupDelete($backupID);
                }

                $task_log .= ' ' . $lang->task_mysupport_backup_ran;
            }
        }
    }

    if (!empty($task_log)) {
        add_task_log($task, $task_log);
    }
    /*
    SELECT `t`.`tid`, `t`.`subject`, `t`.`fid`, `f`.`name`, `t`.`status`, `t`.`statusuid`, `u1`.`username` AS `statusuid_username`, `t`.`statustime`, `t`.`bestanswer`, `t`.`assign`, `u2`.`username` AS `assign_username`, `t`.`assignuid`, `u3`.`username` AS `assignuid_username`, `t`.`priority`, `m`.`name` AS `priority_name`, `t`.`prefix`, `tp`.`prefix` AS `prefix_name`
    FROM `mybb_threads` `t`
    LEFT JOIN `mybb_forums` `f` ON `t`.`fid` = `f`.`fid`
    LEFT JOIN `mybb_threadprefixes` `tp` ON `t`.`prefix` = `tp`.`pid`
    LEFT JOIN `mybb_users` `u1` ON `t`.`statusuid` = `u1`.`uid`
    LEFT JOIN `mybb_users` `u2` ON `t`.`assign` = `u2`.`uid`
    LEFT JOIN `mybb_users` `u3` ON `t`.`assignuid` = `u3`.`uid`
    LEFT JOIN `mybb_mysupport` `m` ON `t`.`priority` = `m`.`mid`
    WHERE CONCAT(',', f.parentlist, ',') LIKE '%,1,%'
    AND `t`.`closed` NOT LIKE 'moved|%'
    ORDER BY `t`.`tid` ASC;
    */

    return $task;
}

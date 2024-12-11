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

use function MySupport\Admin\pluginActivation;
use function MySupport\Admin\pluginDeactivation;
use function MySupport\Admin\pluginInformation;
use function MySupport\Admin\pluginInstallation;
use function MySupport\Admin\pluginIsInstalled;
use function MySupport\Admin\pluginUninstallation;
use function MySupport\Core\addHooks;
use function MySupport\Core\updateCache;
use function MySupport\MyAlerts\initLocations;
use function MySupport\MyAlerts\initMyalerts;
use function MySupport\MyAlerts\MyAlertsIsIntegrable;

use const MySupport\Core\ROOT;

if (!defined('IN_MYBB')) {
    die('This file cannot be accessed directly.');
}

define('MySupport\Core\ROOT', MYBB_ROOT . 'inc/plugins/mysupport');

require_once ROOT . '/core.php';

defined('PLUGINLIBRARY') or define('PLUGINLIBRARY', MYBB_ROOT . 'inc/plugins/pluginlibrary.php');

// Add our hooks
if (defined('IN_ADMINCP')) {
    require_once ROOT . '/admin.php';

    require_once ROOT . '/admin_hooks.php';

    addHooks('MySupport\AdminHooks');
} else {
    require_once ROOT . '/forum_hooks.php';

    addHooks('MySupport\ForumHooks');
}

require ROOT . '/myalerts.php';

if (MyAlertsIsIntegrable()) {
    initMyalerts();

    initLocations();
}

define('MySupport\Core\VERSION', '1.8.0');

define('MySupport\Core\VERSION_CODE', 1800);

function mysupport_info(): array
{
    return pluginInformation();
}

function mysupport_activate(): bool
{
    return pluginActivation();
}

function mysupport_deactivate(): bool
{
    return pluginDeactivation();
}

function mysupport_install(): bool
{
    return pluginInstallation();
}

function mysupport_is_installed(): bool
{
    return pluginIsInstalled();
}

function mysupport_uninstall(): bool
{
    return pluginUninstallation();
}

function update_mysupport()
{
    updateCache();
}

// control_object by Zinga Burga from MyBBHacks ( mybbhacks.zingaburga.com )
if (!function_exists('control_object')) {
    function control_object(&$obj, $code)
    {
        static $cnt = 0;
        $newname = '_objcont_' . (++$cnt);
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
}

if (!function_exists('control_db')) {
    // explicit workaround for PDO, as trying to serialize it causes a fatal error (even though PHP doesn't complain over serializing other resources)
    if ($GLOBALS['db'] instanceof AbstractPdoDbDriver) {
        $GLOBALS['AbstractPdoDbDriver_lastResult_prop'] = new ReflectionProperty('AbstractPdoDbDriver', 'lastResult');
        $GLOBALS['AbstractPdoDbDriver_lastResult_prop']->setAccessible(true);
        function control_db($code)
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
        function control_db($code)
        {
            global $db;
            $oldLink = $db->db;
            unset($db->db);
            control_object($db, $code);
            $db->db = $oldLink;
        }
    } else {
        function control_db($code)
        {
            control_object($GLOBALS['db'], $code);
        }
    }
}
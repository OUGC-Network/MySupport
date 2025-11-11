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

namespace MySupport\MyAlerts;

use MybbStuff_Core_ClassLoader;
use MybbStuff_MyAlerts_AlertFormatterManager;
use MybbStuff_MyAlerts_AlertTypeManager;
use MybbStuff_MyAlerts_Entity_AlertType;

use const GLOB_ONLYDIR;
use const MySupport\Core\ROOT;

function getAvailableLocations(): array
{
    $directory = ROOT . '/myalerts/';

    return array_map(
        'basename',
        glob($directory . '*', GLOB_ONLYDIR)
    );
}

function getInstalledLocations(): array
{
    global $cache;

    return $cache->read('mysupport')['MyAlertLocationsInstalled'] ?? [];
}

function isLocationAlertTypePresent(string $locationName): bool
{
    if (MyAlertsIsIntegrable()) {
        $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

        return $alertTypeManager->getByCode('mysupport_' . $locationName) !== null;
    }

    return false;
}

function installLocation(string $name): void
{
    global $db, $cache;

    $cacheEntry = $cache->read('mysupport');

    if (!in_array($name, $cacheEntry['MyAlertLocationsInstalled'])) {
        $cacheEntry['MyAlertLocationsInstalled'][] = $name;

        $cache->update('mysupport', $cacheEntry);
    }

    if (!isLocationAlertTypePresent($name)) {
        $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

        $alertType = new MybbStuff_MyAlerts_Entity_AlertType();

        $alertType->setCode('mysupport_' . $name);

        $alertTypeManager->add($alertType);
    }
}

function uninstallLocation(string $name): void
{
    global $db, $cache;

    // remove MyAlerts type
    $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

    $alertTypeManager->deleteByCode('mysupport_' . $name);

    // remove datacache value
    $cacheEntry = $cache->read('mysupport');
    $key = array_search($name, $cacheEntry['MyAlertLocationsInstalled']);

    if ($key !== false) {
        unset($cacheEntry['MyAlertLocationsInstalled'][$key]);
        $cache->update('mysupport', $cacheEntry);
    }
}

function initMyalerts(): void
{
    defined('MYBBSTUFF_CORE_PATH') or define('MYBBSTUFF_CORE_PATH', MYBB_ROOT . 'inc/plugins/MybbStuff/Core/');

    defined('MYALERTS_PLUGIN_PATH') or define('MYALERTS_PLUGIN_PATH', MYBB_ROOT . 'inc/plugins/MybbStuff/MyAlerts');

    require_once MYBBSTUFF_CORE_PATH . 'ClassLoader.php';

    $classLoader = new MybbStuff_Core_ClassLoader();

    $classLoader->registerNamespace('MybbStuff_MyAlerts', [MYALERTS_PLUGIN_PATH . '/src']);

    $classLoader->register();
}

function initLocations(): void
{
    foreach (getInstalledLocations() as $locationName) {
        require_once ROOT . '/myalerts/' . $locationName . '/init.php';
    }
}

function registerMyalertsFormatters(): void
{
    global $mybb, $lang, $formatterManager;

    $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();

    //$formatterManager or $formatterManager = \MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);

    foreach (getInstalledLocations() as $locationName) {
        $class = 'MybbStuff_MyAlerts_Formatter_MySupport_' . ucfirst($locationName) . 'Formatter';

        $formatter = new $class($mybb, $lang, 'mysupport_' . $locationName);

        $formatterManager->registerFormatter($formatter);
    }
}

function MyAlertsIsIntegrable(): bool
{
    global $cache;

    static $status;

    if (!$status) {
        $status = false;

        $plugins = $cache->read('plugins');

        if (!empty($plugins['active']) && in_array('myalerts', $plugins['active'])) {
            if ($euantor_plugins = $cache->read('euantor_plugins')) {
                if (isset($euantor_plugins['myalerts']['version'])) {
                    $version = explode('.', $euantor_plugins['myalerts']['version']);

                    if ($version[0] == '2' && $version[1] == '0') {
                        $status = true;
                    }
                }
            }
        }
    }

    return $status;
}
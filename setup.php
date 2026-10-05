<?php

/**
 * -------------------------------------------------------------------------
 * AccessTransparency plugin for GLPI
 * Copyright (C) 2026 by the TICGAL Team.
 * https://www.tic.gal
 * -------------------------------------------------------------------------
 * LICENSE
 * This file is part of the AccessTransparency plugin.
 * AccessTransparency plugin is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * AccessTransparency plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with AccessTransparency. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @package   accesstransparency
 * @author    the TICGAL team
 * @copyright Copyright (c) 2026 TICGAL team
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 * @link      https://www.tic.gal
 * @since     2026
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ACCESSTRANSPARENCY_VERSION', '1.3.0-beta.3');
define('PLUGIN_ACCESSTRANSPARENCY_MIN_GLPI', '11.0');
define('PLUGIN_ACCESSTRANSPARENCY_MAX_GLPI', '11.9');

/**
 * Plugin_Version_accesstransparency
 * @return array
 */
function plugin_version_accesstransparency(): array
{
    return [
        'name'          => 'Access Transparency',
        'version'       => PLUGIN_ACCESSTRANSPARENCY_VERSION,
        'author'        => '<a href="https://tic.gal">TICGAL</a>',
        'homepage'      => 'https://tic.gal',
        'license'       => 'AGPLv3+',
        'requirements'  => [
            'glpi' => [
                'min' => PLUGIN_ACCESSTRANSPARENCY_MIN_GLPI,
                'max' => PLUGIN_ACCESSTRANSPARENCY_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Plugin_Init_Accesstransparency
 * @return void
 */
function plugin_init_accesstransparency(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    Plugin::registerClass(PluginAccesstransparencyConfig::class, ['addtabon' => Config::class]);
    Plugin::registerClass(PluginAccesstransparencyProfile::class, ['addtabon' => Profile::class]);
    Plugin::registerClass(PluginAccesstransparencyLog::class, ['addtabon' => User::class]);
    Plugin::registerClass(PluginAccesstransparencyDocument::class, ['addtabon' => Document::class]);

    // Document downloads are tracked server-side (see hook.php)
    $PLUGIN_HOOKS[Hooks::POST_INIT]['accesstransparency'] = 'plugin_accesstransparency_track_document_download';

    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['accesstransparency'] = 'front/config.form.php';

    CronTask::register(
        'PluginAccesstransparencyConfig',
        'PurgeAccessTransparencyLogs',
        HOUR_TIMESTAMP,
        [
            'state' => 1,
            'mode' => CronTask::MODE_EXTERNAL,
            'hourmin' => 0,
            'hourmax' => 24,
        ],
    );

    CronTask::register(
        'PluginAccesstransparencyLog',
        'PluginAccesstransparencyGetLogs',
        HOUR_TIMESTAMP,
        [
            'state' => 1,
            'mode' => CronTask::MODE_EXTERNAL,
            'hourmin' => 0,
            'hourmax' => 24,
        ],
    );
}

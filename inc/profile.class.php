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

class PluginAccesstransparencyProfile extends CommonDBTM
{
    public static $rightname = 'profile';

    /**
     * {@inheritDoc}
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        switch ($item->getType()) {
            case Profile::class:
                return self::createTabEntry('Access Transparency');
        }
        return '';
    }

    public static function getIcon(): string
    {
        return 'ti ti-window';
    }

    /**
     * {@inheritDoc}
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        switch ($item->getType()) {
            case Profile::class:
                /** @var Profile $item */
                $profile = new self();
                return $profile->displayProfileForm($item);
        }
        return false;
    }

    /**
     * Display the profile form for AccessTransparency rights
     * @param  Profile $profile
     * @return bool
     */
    public function displayProfileForm(Profile $profile): bool
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        $can_edit = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='spaced'>";
        if ($can_edit) {
            echo "<form method='post' action='" . htmlspecialchars($profile::getFormURL()) . "'>";
        }

        $rights = self::getGeneralRights();
        $matrix_options = [
            'canedit' => $can_edit,
            'title'   => __('Access Transparency', 'accesstransparency'),
        ];

        $profile->displayRightsChoiceMatrix($rights, $matrix_options);
        if ($can_edit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $profile->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>\n";
            Html::closeForm();
        }
        echo '</div>';
        return true;
    }

    /**
     * Get general rights array
     * @return array
     */
    public static function getGeneralRights(): array
    {
        return [
            [
                'rights' => [READ => __('Read')],
                'label'  => __('Historical'),
                'field'  => 'plugin_accesstransparency_view',
            ],
        ];
    }

    /**
     * Remove profile rights on uninstall
     * @param Migration $migration
     * @return void
     */
    public static function uninstall(Migration $migration): void
    {
        $migration->displayMessage("Deleting accesstransparency profile rights");
        foreach (self::getGeneralRights() as $data) {
            ProfileRight::deleteProfileRights([$data['field']]);
        }
    }
}

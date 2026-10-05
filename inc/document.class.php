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

use Glpi\Application\View\TemplateRenderer;

class PluginAccesstransparencyDocument extends CommonDBTM
{
    public static $rightname = 'plugin_accesstransparency_view';
    public static function getTypeName($nb = 0): string
    {
        return __('Access Transparency', 'accesstransparency');
    }
    public static function getIcon(): string
    {
        return 'ti ti-window';
    }

    /** Valid user id that no user has: filtering by it matches no row */
    private const NO_USER_ID = 2147483647;

    /**
     * Can the current user read this user (entity scope included)?
     */
    private static function canSeeUser(int $users_id): bool
    {
        static $cache = [];

        return $cache[$users_id] ??= $users_id > 0 && (new User())->can($users_id, READ);
    }

    /**
     * Filters of the tab and the CSV: the user filter can only select users the viewer can read, otherwise
     * filtering by a hidden user would tell whether they opened the document.
     *
     * @param array $filters already normalized by PluginAccesstransparencyLog::normalizeFilters()
     */
    public static function restrictFiltersToVisibleUsers(array $filters): array
    {
        if (isset($filters['users_names'])) {
            $visible = array_values(array_filter($filters['users_names'], static fn($id) => self::canSeeUser((int) $id)));
            // No visible user left: match nothing (an empty list would mean "no filter", and 0 is dropped as invalid)
            $filters['users_names'] = $visible !== [] ? $visible : [self::NO_USER_ID];
        }

        return $filters;
    }

    public static function getDistinctUserNamesValuesInItemLog(CommonDBTM $item): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $items_id = $item->getField('id');

        $query = [
            'SELECT'    => 'users_id',
            'DISTINCT'  => true,
            'FROM'      => PluginAccesstransparencyLog::getTable(),
            'WHERE'     => [
                'itemtype'    => $item::getType(),
                'items_id'    => $items_id,
                'source_type' => PluginAccesstransparencyLog::DOCUMENT,
            ],
            'ORDER'     => 'id DESC',
        ];

        $iterator = $DB->request($query);

        $values = [];
        foreach ($iterator as $data) {
            if (empty($data['users_id'])) {
                continue;
            }
            // The users of other entities are not listed: they are not selectable as a filter either
            if (!self::canSeeUser((int) $data['users_id'])) {
                continue;
            }
            $values[$data['users_id']] = User::getNameForLog($data['users_id']);
        }

        asort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if (!($item instanceof Document) || !self::canViewForDocument($item)) {
            return '';
        }

        $nb = 0;
        if ($_SESSION['glpishow_count_on_tabs']) {
            $nb = countElementsInTable(PluginAccesstransparencyLog::getTable(), ['itemtype' => $item::getType(), 'items_id' => $item->getID(), 'source_type' => PluginAccesstransparencyLog::DOCUMENT]);
        }
        return self::createTabEntry(self::getTypeName(1), $nb);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // The tab content can be requested directly, so the rights are checked again here
        if ($item instanceof Document && self::canViewForDocument($item)) {
            self::displayUserInteractionsForDocument($item);
        }

        return true;
    }

    private static function canViewForDocument(Document $item): bool
    {
        return Session::haveRight(self::$rightname, READ) && $item->canViewItem();
    }

    public static function displayUserInteractionsForDocument(Document $doc)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $document_id = intval($doc->getID());

        $start       = max(0, (int) ($_GET["start"] ?? 0));
        $filters     = self::restrictFiltersToVisibleUsers(PluginAccesstransparencyLog::normalizeFilters($_GET['filters'] ?? []));
        $is_filtered = count($filters) > 0;
        $filters['source'] = [PluginAccesstransparencyLog::DOCUMENT];
        $sql_filters = PluginAccesstransparencyLog::convertFiltersValuesToSqlCriteria($filters);
        unset($filters['source']);

        $total_number    = countElementsInTable(PluginAccesstransparencyLog::getTable(), ['items_id' => $document_id, 'itemtype' => $doc::getType(), 'source_type' => PluginAccesstransparencyLog::DOCUMENT]);
        $filtered_number = countElementsInTable(PluginAccesstransparencyLog::getTable(), ['items_id' => $document_id, 'itemtype' => $doc::getType(), 'source_type' => PluginAccesstransparencyLog::DOCUMENT] + $sql_filters);


        TemplateRenderer::getInstance()->display('@accesstransparency/pages/document.html.twig', [
            'total_number'      => $total_number,
            'filtered_number'   => $filtered_number,
            'logs'              => $filtered_number > 0
            ? self::getHistoryData($doc, $start, $_SESSION['glpilist_limit'], $sql_filters)
            : [],
            'start'             => $start,
            'href'              => $doc::getFormURLWithID($document_id),
            'additional_params' => $is_filtered ? http_build_query(['filters' => $filters]) : "",
            'is_tab'            => true,
            'items_id'          => $document_id,
            'filters'           => $filters,
            'user_names'        => self::getDistinctUserNamesValuesInItemLog($doc),
            'csv_url'           => $CFG_GLPI['root_doc'] . '/plugins/accesstransparency/front/export_document_csv.php?' . http_build_query([
                'id'      => $document_id,
                'filters' => $filters,
            ]),
        ]);

        return true;
    }

    public static function getHistoryData(CommonDBTM $item, $start = 0, $limit = 0, array $sqlfilters = [])
    {
        $DBread = DBConnection::getReadConnection();

        $query = [
            'FROM' => PluginAccesstransparencyLog::getTable(),
            'WHERE' => [
                'items_id' => $item->getID(),
                'itemtype' => $item::getType(),
            ] + $sqlfilters,
            'ORDER' => 'source_date DESC',
        ];
        if ($limit) {
            $query['START'] = (int) $start;
            $query['LIMIT'] = (int) $limit;
        }
        $iterator = $DBread->request($query);
        $logs = [];
        foreach ($iterator as $data) {
            $tmp = [];

            $tmp['id'] = $data['id'];
            $tmp['source_date'] = $data['source_date'];
            // A user of another entity opened the document: not shown to a viewer who can't read them
            $can_see_user = self::canSeeUser((int) $data['users_id']);
            $tmp['users_id'] = $can_see_user ? (int) $data['users_id'] : 0;
            $tmp['user_name'] = $can_see_user
                ? User::getNameForLog($data['users_id'])
                : __s('Item not available or not visible to you', 'accesstransparency');
            $tmp['opened_from'] = PluginAccesstransparencyLog::resolveSourceItem(
                $data['source_itemtype'] ?? null,
                (int) ($data['source_items_id'] ?? 0),
            );

            $logs[] = $tmp;
        }

        return $logs;
    }
}

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


use Glpi\Exception\Http\NotFoundHttpException;

if (!Plugin::isPluginActive('accesstransparency')) {
   throw new NotFoundHttpException();
}

Session::checkRight(PluginAccesstransparencyLog::$rightname, READ);

$user = new User();
$users_id = (int)($_GET['id'] ?? 0);
// Same checks as the User tab (core checks can(READ) before loading a tab): the itemtype right and the item scope
if ($users_id <= 0 || !$user->can($users_id, READ)) {
   throw new NotFoundHttpException();
}

$filters = $_GET['filters'] ?? [];
if (!is_array($filters)) {
   $filters = [];
}
$sql_filters = PluginAccesstransparencyLog::convertFiltersValuesToSqlCriteria($filters);

$rows = [];
foreach (PluginAccesstransparencyLog::getHistoryData($user, 0, PluginAccesstransparencyCsvexport::MAX_ROWS, $sql_filters) as $log) {
   $rows[] = [
      $log['id'],
      $log['source_type'],
      $log['source_date'],
      $log['message'],
   ];
}
if (countElementsInTable(PluginAccesstransparencyLog::getTable(), ['users_id' => $users_id] + $sql_filters) > PluginAccesstransparencyCsvexport::MAX_ROWS) {
   $rows[] = PluginAccesstransparencyCsvexport::getTruncatedRow(4);
}

return (new PluginAccesstransparencyCsvexport(
   sprintf('accesstransparency-user-%d.csv', $users_id),
   [__('ID'), __('Source'), _n('Date', 'Dates', 1), __('Message')],
   $rows
))->toResponse();

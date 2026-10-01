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

Session::checkRight(PluginAccesstransparencyDocument::$rightname, READ);

$doc = new Document();
$documents_id = (int)($_GET['id'] ?? 0);
// Same checks as the Document tab: the document must exist and be visible to the viewer
if ($documents_id <= 0 || !$doc->getFromDB($documents_id) || !$doc->canViewItem()) {
   throw new NotFoundHttpException();
}

$filters = $_GET['filters'] ?? [];
if (!is_array($filters)) {
   $filters = [];
}
$filters['source'] = [PluginAccesstransparencyLog::DOCUMENT];
$sql_filters = PluginAccesstransparencyLog::convertFiltersValuesToSqlCriteria($filters);

$rows = [];
foreach (PluginAccesstransparencyDocument::getHistoryData($doc, 0, 0, $sql_filters) as $log) {
   $rows[] = [
      $log['id'],
      $log['source_date'],
      $log['user_name'],
      $log['opened_from'] !== null ? sprintf(__('%1$s: %2$s'), $log['opened_from']['label'], $log['opened_from']['name']) : '',
   ];
}

\Glpi\Csv\CsvResponse::output(new PluginAccesstransparencyCsvexport(
   sprintf('accesstransparency-document-%d.csv', $documents_id),
   [__('ID'), _n('Date', 'Dates', 1), User::getTypeName(1), __('Opened from', 'accesstransparency')],
   $rows
));

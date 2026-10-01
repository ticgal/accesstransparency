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

/**
 * Call all install methods of the plugin
 *
 * @return bool
 */
function plugin_accesstransparency_install(): bool
{
   $migration = new Migration(PLUGIN_ACCESSTRANSPARENCY_VERSION);

   foreach (glob(dirname(__FILE__) . '/inc/*') as $filepath) {
      if (preg_match("/inc.(.+)\.class.php/", $filepath, $matches)) {
         $classname = 'PluginAccesstransparency' . ucfirst($matches[1]);
         include_once $filepath;
         if (method_exists($classname, 'install')) {
            $classname::install($migration);
         }
      }
   }

   return true;
}

/**
 * Record every document download/open, whatever the way the user reached it
 * (document list, ticket timeline, direct URL...). Called on every request via Hooks::POST_INIT.
 *
 * @return void
 */
function plugin_accesstransparency_track_document_download(): void
{
   // GLPI 11 routes every legacy front/*.php script through public/index.php,
   // so SCRIPT_NAME is always the front controller: only REQUEST_URI has the requested path.
   $uri = $_SERVER['REQUEST_URI'] ?? '';
   if (strpos($uri, '/front/document.send.php') === false || !isset($_GET['docid'])) {
      return;
   }

   $docid = filter_var($_GET['docid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
   $users_id = Session::getLoginUserID();
   if ($docid === false || !$users_id || PluginAccesstransparencyConfig::isUserExcluded()) {
      return;
   }

   // Only record real accesses, with the same checks front/document.send.php does after this hook
   $doc = new Document();
   if (!$doc->getFromDB($docid) || !$doc->canViewFile($_GET)) {
      return;
   }

   // Document::getDownloadLink($linked_item) appends &itemtype=...&items_id=... when the link is
   // rendered for a linked item (ticket timeline, change documents...). It is request input:
   // only keep it when it resolves to a real CommonDBTM class.
   $source_itemtype = null;
   $source_items_id = 0;
   $itemtype = $_GET['itemtype'] ?? null;
   $items_id = filter_var($_GET['items_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
   if (is_string($itemtype) && $itemtype !== '' && $items_id !== false && is_a($itemtype, CommonDBTM::class, true)) {
      $source_itemtype = $itemtype;
      $source_items_id = $items_id;
   }

   $log = new PluginAccesstransparencyLog();
   $log->add([
      'source_type'     => PluginAccesstransparencyLog::DOCUMENT,
      'source_id'       => $docid,
      'source_date'     => $_SESSION['glpi_currenttime'],
      'itemtype'        => Document::getType(),
      'items_id'        => $docid,
      'users_id'        => $users_id,
      'new_value'       => '/front/document.send.php?docid=' . $docid,
      'source_itemtype' => $source_itemtype,
      'source_items_id' => $source_items_id,
   ]);
}

/**
 * Call all uninstall methods of the plugin
 *
 * @return bool
 */
function plugin_accesstransparency_uninstall(): bool
{
   $migration = new Migration(PLUGIN_ACCESSTRANSPARENCY_VERSION);

   foreach (glob(dirname(__FILE__) . '/inc/*') as $filepath) {
      if (preg_match("/inc.(.+)\.class.php/", $filepath, $matches)) {
         $classname = 'PluginAccesstransparency' . ucfirst($matches[1]);
         include_once $filepath;
         if (method_exists($classname, 'uninstall')) {
            $classname::uninstall($migration);
         }
      }
   }

   return true;
}

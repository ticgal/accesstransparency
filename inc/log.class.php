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
use Glpi\RichText\RichText;
use Glpi\Search\SearchOption;

class PluginAccesstransparencyLog extends CommonDBTM
{
   public static $rightname = 'plugin_accesstransparency_view';

   const LOG = 1;
   const EVENT = 2;
   const DOCUMENT = 3;

   static function cronInfo(string $name)
   {
      switch ($name) {
         case 'PluginAccesstransparencyGetLogs':
            return ['description' => __('Get logs', 'accesstransparency'), 'parameter' => __('Limit number of logs to retrieve', 'accesstransparency')];
      }
      return [];
   }

   static function cronPluginAccesstransparencyGetLogs($crontask)
   {
      global $DB;

      $tot = 0;

      $limit = (int)$crontask->fields['param'];

      // Get logs to retrieve
      $last_id = 0;
      $query = [
         'SELECT' => 'source_id',
         'FROM' => self::getTable(),
         'WHERE' => [
            'source_type' => self::LOG,
         ],
         'ORDER' => 'source_id DESC',
      ];
      $result = $DB->request($query);
      if ($row = $result->current()) {
         $last_id = $row['source_id'];
      }

      $query = [
         'FROM' => Log::getTable(),
         'WHERE' => [
            'id' => ['>', $last_id],
         ],
      ];
      if ($limit > 0) {
         $query['LIMIT'] = $limit;
      }
      $result = $DB->request($query);
      foreach ($result as $row) {
         $user_id = 0;
         if (!empty($row['user_name']) && preg_match('/\((\d+)\)/', $row['user_name'], $matches)) {
            $user_id = (int)$matches[1];
         }
         if ($user_id <= 0) {
            continue;
         }
         $log = new self();
         $log->add([
            'source_type' => self::LOG,
            'source_id' => $row['id'],
            'source_date' => $row['date_mod'],
            'users_id' => $user_id,
            'itemtype' => $row['itemtype'],
            'items_id' => $row['items_id'],
            'action_code' => $row['linked_action'],
            'id_search_option' => $row['id_search_option'],
            'old_value' => $row['old_value'],
            'new_value' => $row['new_value'],
         ]);
         $tot++;
      }

      //Get events to retrieve
      $last_id = 0;
      $query = [
         'SELECT' => 'source_id',
         'FROM' => self::getTable(),
         'WHERE' => [
            'source_type' => self::EVENT,
         ],
         'ORDER' => 'source_id DESC',
      ];
      $result = $DB->request($query);
      if ($row = $result->current()) {
         $last_id = $row['source_id'];
      }
      $query = [
         'FROM' => Glpi\Event::getTable(),
         'WHERE' => [
            'id' => ['>', $last_id],
            'OR' => [
               [
                  'service' => 'login',
                  'level' => 3
               ],
               [
                  'service' => 'tracking',
                  'level' => 4
               ],
               [
                  'service' => 'inventory',
                  'level' => 4
               ]
            ]
         ],
      ];
      if ($limit > 0) {
         $query['LIMIT'] = $limit;
      }
      $result = $DB->request($query);
      foreach ($result as $row) {
         $user_id = self::resolveUserIdFromEventMessage((string)$row['message']);

         if ($user_id <= 0) {
            continue;
         }
         $log = new self();
         $log->add([
            'source_type' => self::EVENT,
            'source_id' => $row['id'],
            'source_date' => $row['date'],
            'users_id' => $user_id,
            'items_id' => $row['items_id'],
            'severity_level' => $row['level'],
            'message' => $row['message'],
            'service' => $row['service'],
         ]);
         $tot++;
      }

      $crontask->setVolume($tot);
      return ($tot > 0 ? 1 : 0);
   }

   /**
    * Resolve users_id from a login event message without relying on translated sentence structure.
    *
    * The strategy is:
    * 1) Find all usernames contained in the event message.
    * 2) If there is a single match, use it.
    * 3) If there are many, keep only usernames that match the start of the message.
    * 4) If still ambiguous, return 0.
    */
   private static function resolveUserIdFromEventMessage(string $message): int
   {
      global $DB;

      $message = trim($message);
      if ($message === '') {
         return 0;
      }

      $query = [
         'SELECT' => ['id', 'name'],
         'FROM' => User::getTable(),
         'WHERE' => [
            'name' => ['!=', ''],
            new \Glpi\DBAL\QueryExpression('INSTR(' . $DB->quoteValue($message) . ', ' . DBmysql::quoteName('name') . ') > 0'),
         ],
      ];

      $result = $DB->request($query);

      $matches = [];
      foreach ($result as $row) {
         $name = (string)$row['name'];
         if ($name === '') {
            continue;
         }
         $matches[] = [
            'id' => (int)$row['id'],
            'name' => $name,
         ];
      }

      if (count($matches) === 1) {
         return $matches[0]['id'];
      }

      if (count($matches) === 0) {
         return 0;
      }

      $prefix_matches = [];
      foreach ($matches as $match) {
         if (strpos($message, $match['name']) === 0) {
            $prefix_matches[] = $match;
         }
      }

      if (count($prefix_matches) === 1) {
         return $prefix_matches[0]['id'];
      }

      return 0;
   }

   public static function getSourceType()
   {

      $options = [
         self::LOG => __('Log entry', 'accesstransparency'),
         self::EVENT   => __('Event', 'accesstransparency'),
         self::DOCUMENT   => __('Document access', 'accesstransparency'),
      ];

      return $options;
   }

   public static function getTypeName($nb = 0)
   {
      return 'Access Transparency';
   }

   public static function getIcon()
   {
      return 'fa-solid fa-cube';
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
   {
      if ($item::getType() != User::getType() || !Session::haveRight(self::$rightname, READ)) {
         return '';
      }

      $nb = 0;
      if (
         $_SESSION['glpishow_count_on_tabs']
         && ($item instanceof CommonDBTM)
      ) {
         $nb = countElementsInTable(
            self::getTable(),
            [
               'users_id' => $item->getID(),
            ]
         );
      }
      return self::createTabEntry(self::getTypeName(1), $nb, $item::getType());
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
   {
      if ($item::getType() == User::getType() && Session::haveRight(self::$rightname, READ)) {
         self::showForItem($item);
      }
      return true;
   }

   public static function showForItem(CommonDBTM $item, $withtemplate = 0)
   {
      global $CFG_GLPI;

      if (!self::canView()) {
         return;
      }

      $itemtype = $item->getType();
      $items_id = $item->getField('id');

      $start       = intval(($_GET["start"] ?? 0));
      $filters     = $_GET['filters'] ?? [];
      $is_filtered = count($filters) > 0;
      $sql_filters = self::convertFiltersValuesToSqlCriteria($filters);

      // Total Number of events
      $total_number    = countElementsInTable(self::getTable(), ['users_id' => $items_id]);
      $filtered_number = countElementsInTable(self::getTable(), ['users_id' => $items_id] + $sql_filters);

      TemplateRenderer::getInstance()->display('@accesstransparency/pages/log.html.twig', [
         'total_number'      => $total_number,
         'filtered_number'   => $filtered_number,
         'logs'              => $filtered_number > 0
            ? self::getHistoryData($item, $start, $_SESSION['glpilist_limit'], $sql_filters)
            : [],
         'start'             => $start,
         'href'              => $item::getFormURLWithID($items_id),
         'additional_params' => $is_filtered ? http_build_query(['filters' => $filters]) : "",
         'is_tab'            => true,
         'items_id'          => $items_id,
         'filters'           => $filters,
         'type_source'   => $is_filtered
            ? self::getSourceType()
            : [],
      ]);
   }

   public static function getHistoryData(CommonDBTM $item, $start = 0, $limit = 0, array $sqlfilters = [])
   {
      $DBread = DBConnection::getReadConnection();

      $query = [
         'FROM' => self::getTable(),
         'WHERE' => [
            'users_id' => $item->getID()
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
         $tmp['source_type'] = self::getSourceType()[$data['source_type']] ?? $data['source_type'];
         $tmp['source_date'] = $data['source_date'];
         $tmp['user_name'] = User::getNameForLog($data['users_id']);

         // The rows describe items from any entity: never show details of an item the viewer can't read.
         // The row itself is kept (with a placeholder) so the pager counts stay consistent.
         if (!self::canViewRowItem($data)) {
            $tmp['message'] = __s('Item not available or not visible to you', 'accesstransparency');
            $logs[] = $tmp;
            continue;
         }

         // The message is rendered with |raw: every value coming from the database must be escaped here.
         switch ($data['source_type']) {
            case self::LOG:
               $logmessage = self::getLogMessage($data);
               $tmp['message'] = sprintf(
                  __s('%s #%d: %s', 'accesstransparency'),
                  htmlescape($data['itemtype']),
                  $data['items_id'],
                  $logmessage
               );
               break;
            case self::EVENT:
               $tmp['message'] = sprintf(
                  __s('%s event: %s', 'accesstransparency'),
                  htmlescape($data['service']),
                  htmlescape($data['message'])
               );
               break;
            case self::DOCUMENT:
               $tmp['message'] = sprintf(
                  __s('Accessed document #%d', 'accesstransparency'),
                  $data['items_id']
               );
               break;
            default:
               $tmp['message'] = htmlescape($data['message'] ?? '');
         }

         $logs[] = $tmp;
      }

      return $logs;
   }

   /**
    * Check that the current user can read the item a stored row refers to,
    * including its entity (can() checks both the itemtype right and the item scope).
    */
   public static function canViewRowItem(array $data): bool
   {
      switch ($data['source_type']) {
         case self::LOG:
         case self::DOCUMENT:
            $itemtype = (string)($data['itemtype'] ?? '');
            $items_id = (int)($data['items_id'] ?? 0);
            break;
         case self::EVENT:
            // `field` holds the itemtype resolved from the glpi_events type, stored since 1.3.0.
            // Events with no itemtype ("system", "dropdown"...) have no item to check.
            $itemtype = (string)($data['field'] ?? '');
            $items_id = (int)($data['items_id'] ?? 0);
            if ($itemtype === '') {
               return true;
            }
            break;
         default:
            return true;
      }

      if ($itemtype === '' || !is_a($itemtype, CommonDBTM::class, true)) {
         // Unknown or removed itemtype: nothing can be checked, nor displayed reliably
         return $data['source_type'] == self::EVENT;
      }

      if ($items_id <= 0) {
         return $itemtype::canView();
      }

      $item = getItemForItemtype($itemtype);
      return $item !== false && $item->can($items_id, READ);
   }

   /**
    * Resolve the `type` of a glpi_events row (e.g. "ticket", "users") to an itemtype,
    * the same way core Glpi\Event does to link events to their item.
    */
   public static function resolveEventItemtype(string $type): ?string
   {
      static $mapping = [];

      if ($type === '') {
         return null;
      }
      if (array_key_exists($type, $mapping)) {
         return $mapping[$type];
      }

      $dbu = new DbUtils();
      $mapping[$type] = null;
      foreach ([$type, $dbu->fixItemtypeCase($type), $dbu->fixItemtypeCase($dbu->getSingular($type))] as $candidate) {
         if (is_a($candidate, CommonDBTM::class, true)) {
            $mapping[$type] = $candidate;
            break;
         }
      }

      return $mapping[$type];
   }

   public static function getLogMessage(array $data): string
   {
      $DBread = DBConnection::getReadConnection();

      if (!class_exists($data["itemtype"])) {
         return sprintf(__s('Unknown itemtype: %s', 'accesstransparency'), htmlescape($data["itemtype"]));
      }

      $SEARCHOPTION = SearchOption::getOptionsForItemtype($data["itemtype"]);
      $log = new Log();
      $log->getFromDB($data["source_id"]);

      $item = getItemForItemtype($data["itemtype"]);
      $item->getFromDB($data["items_id"]);
      $message = '';
      $datatype = '';
      $itemtable = $item->getTable();

      if ($data["action_code"]) {
         $action_label = Log::getLinkedActionLabel($data["action_code"]);

         // Yes it is an internal device
         switch ($data["action_code"]) {
            case Log::HISTORY_CREATE_ITEM:
            case Log::HISTORY_DELETE_ITEM:
            case Log::HISTORY_LOCK_ITEM:
            case Log::HISTORY_UNLOCK_ITEM:
            case Log::HISTORY_RESTORE_ITEM:
               $message = htmlescape($action_label);
               break;

            case Log::HISTORY_ADD_DEVICE:
               //TRANS: %s is the component name
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["new_value"])
               );
               break;

            case Log::HISTORY_UPDATE_DEVICE:
               $tmpfield = NOT_AVAILABLE;
               $linktype_field = explode('#', $log->fields["itemtype_link"]);
               $linktype       = $linktype_field[0];
               $field          = $linktype_field[1];
               $devicetype     = $linktype::getDeviceType();
               $tmpfield   = $devicetype;
               $specif_fields  = $linktype::getSpecificities();
               if (isset($specif_fields[$field]['short name'])) {
                  $tmpfield   = $devicetype;
                  $tmpfield  .= " (" . $specif_fields[$field]['short name'] . ")";
               }
               //TRANS: %1$s is the old_value, %2$s is the new_value
               $message  = sprintf(
                  __s('%1$s: %2$s'),
                  sprintf(
                     __s('%1$s (%2$s)'),
                     htmlescape($action_label),
                     htmlescape($tmpfield)
                  ),
                  sprintf(
                     __s('%1$s by %2$s'),
                     htmlescape($data["old_value"]),
                     htmlescape($data["new_value"])
                  )
               );
               break;

            case Log::HISTORY_DELETE_DEVICE:
               //TRANS: %s is the component name
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["old_value"])
               );
               break;

            case Log::HISTORY_LOCK_DEVICE:
               //TRANS: %s is the component name
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["old_value"])
               );
               break;

            case Log::HISTORY_UNLOCK_DEVICE:
               //TRANS: %s is the component name
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["new_value"])
               );
               break;

            case Log::HISTORY_INSTALL_SOFTWARE:
               //TRANS: %s is the software name
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["new_value"])
               );
               break;

            case Log::HISTORY_UNINSTALL_SOFTWARE:
               //TRANS: %s is the software name
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["old_value"])
               );
               break;

            case Log::HISTORY_DISCONNECT_DEVICE:
               //TRANS: %s is the item name
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["old_value"])
               );
               break;

            case Log::HISTORY_CONNECT_DEVICE:
               //TRANS: %s is the item name
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["new_value"])
               );
               break;

            case Log::HISTORY_LOG_SIMPLE_MESSAGE:
               $message = htmlescape($data["new_value"]);
               break;

            case Log::HISTORY_ADD_RELATION:

               $as = false;
               if ($data['id_search_option']) {
                  // Record with specific value in `_force_log_option`
                  $as = $SEARCHOPTION[$data['id_search_option']]['name'] ?? false;
               }

               if (is_a($data['itemtype'], CommonITILObject::class, true)) {
                  /** @var CommonITILObject $item */
                  if ($as === false) {
                     // Old record, befoe the usage of specific `_force_log_option` value.

                     $is = $isr = $isa = $iso = false;
                     switch ($log->fields['itemtype_link']) {
                        case Group::class:
                           $is = 'isGroup';
                           break;

                        case User::class:
                           $is = 'isUser';
                           break;

                        case Supplier::class:
                           $is = 'isSupplier';
                           break;
                     }
                     if ($is) {
                        $iditem = intval(substr($data['new_value'], strrpos($data['new_value'], '(') + 1)); // This is terrible idea
                        $isr = $item->$is(CommonITILActor::REQUESTER, $iditem);
                        $isa = $item->$is(CommonITILActor::ASSIGN, $iditem);
                        $iso = $item->$is(CommonITILActor::OBSERVER, $iditem);
                     }
                     // Simple Heuristic, of course not enough
                     if ($isr && !$isa && !$iso) {
                        $as = _n('Requester', 'Requesters', 1);
                     } elseif (!$isr && $isa && !$iso) {
                        $as = __('Assigned to');
                     } elseif (!$isr && !$isa && $iso) {
                        $as = _n('Observer', 'Observers', 1);
                     }
                  }
               }

               if ($as) {
                  $message = sprintf(
                     __s('%1$s: %2$s'),
                     htmlescape($action_label),
                     sprintf(
                        __s('%1$s (%2$s)'),
                        htmlescape($data["new_value"]),
                        htmlescape($as)
                     )
                  );
               } else {
                  $message = sprintf(
                     __s('%1$s: %2$s'),
                     htmlescape($action_label),
                     htmlescape($data["new_value"])
                  );
               }
               break;

            case Log::HISTORY_UPDATE_RELATION:
               $linktype_field = explode('#', $log->fields["itemtype_link"]);
               $linktype     = $linktype_field[0];
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  sprintf(
                     __s('%1$s (%2$s)'),
                     htmlescape($data["old_value"]),
                     htmlescape($data["new_value"])
                  )
               );
               break;

            case Log::HISTORY_DEL_RELATION:
               $as = false;
               if ($data['id_search_option']) {
                  // Record with specific value in `_force_log_option`
                  $as = $SEARCHOPTION[$data['id_search_option']]['name'] ?? false;
               }

               if ($as) {
                  $message = sprintf(
                     __s('%1$s: %2$s (%3$s)'),
                     htmlescape($action_label),
                     htmlescape($data["old_value"]),
                     htmlescape($as)
                  );
               } else {
                  $message = sprintf(
                     __s('%1$s: %2$s'),
                     htmlescape($action_label),
                     htmlescape($data["old_value"])
                  );
               }
               break;

            case Log::HISTORY_LOCK_RELATION:
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["old_value"])
               );
               break;

            case Log::HISTORY_UNLOCK_RELATION:
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  htmlescape($data["new_value"])
               );
               break;

            case Log::HISTORY_ADD_SUBITEM:
               $tmpfield = '';
               if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                  $tmpfield = $item2->getTypeName(1);
               }
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  sprintf(
                     __s('%1$s (%2$s)'),
                     htmlescape($tmpfield),
                     htmlescape($data["new_value"])
                  )
               );

               break;

            case Log::HISTORY_UPDATE_SUBITEM:
               $tmpfield = '';
               if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                  $tmpfield = $item2->getTypeName(1);
               }
               if (empty($data["new_value"])) {
                  $message = sprintf(
                     __s('%1$s: %2$s'),
                     htmlescape($action_label),
                     htmlescape($tmpfield),
                  );
               } else {
                  $message = sprintf(
                     __s('%1$s: %2$s'),
                     htmlescape($action_label),
                     sprintf(
                        __s('%1$s (%2$s)'),
                        htmlescape($tmpfield),
                        htmlescape($data["new_value"])
                     )
                  );
               }

               break;

            case Log::HISTORY_DELETE_SUBITEM:
               $tmpfield = '';
               if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                  $tmpfield = $item2->getTypeName(1);
               }
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  sprintf(
                     __s('%1$s (%2$s)'),
                     htmlescape($tmpfield),
                     htmlescape($data["old_value"])
                  )
               );
               break;

            case Log::HISTORY_LOCK_SUBITEM:
               $tmpfield = '';
               if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                  $tmpfield = $item2->getTypeName(1);
               }
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  sprintf(
                     __s('%1$s (%2$s)'),
                     htmlescape($tmpfield),
                     htmlescape($data["old_value"])
                  )
               );
               break;

            case Log::HISTORY_UNLOCK_SUBITEM:
               $tmpfield = '';
               if ($item2 = getItemForItemtype($log->fields["itemtype_link"])) {
                  $tmpfield = $item2->getTypeName(1);
               }
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  sprintf(
                     __s('%1$s (%2$s)'),
                     htmlescape($tmpfield),
                     htmlescape($data["new_value"])
                  )
               );
               break;

            case Log::HISTORY_SEND_WEBHOOK:
               $message = sprintf(
                  __s('%1$s: %2$s'),
                  htmlescape($action_label),
                  sprintf(
                     __s('%1$s (Status %2$s -> %3$s)'),
                     htmlescape($log->fields["itemtype_link"]),
                     htmlescape($data["old_value"]),
                     htmlescape($data["new_value"])
                  )
               );
               break;

            default:
               $fct = [$log->fields['itemtype_link'], 'getHistoryEntry'];
               if (
                  ($data['action_code'] >= Log::HISTORY_PLUGIN)
                  && $log->fields['itemtype_link']
                  && is_callable($fct)
               ) {
                  $message = call_user_func($fct, $data);
               }
         }
      } else {
         $fieldname = "";
         $searchopt = [];
         $tablename = '';
         // It's not an internal device
         foreach ($SEARCHOPTION as $key2 => $val2) {
            if ($key2 === $data["id_search_option"]) {
               $tmpfield =  $val2["name"];
               $tablename    =  $val2["table"];
               $fieldname    = $val2["field"];
               $searchopt    = $val2;
               if (isset($val2['datatype'])) {
                  $datatype = $val2["datatype"];
               }
               break;
            }
         }
         if (
            ($itemtable == $tablename)
            || ($datatype == 'right')
         ) {
            switch ($datatype) {
               // specific case for text field
               case 'text':
                  $message = __s('Update of the field');
                  break;

               default:
                  $data["old_value"] = RichText::getTextFromHtml($item->getValueToDisplay($searchopt, $data["old_value"]) ?? '', false, true);
                  $data["new_value"] = RichText::getTextFromHtml($item->getValueToDisplay($searchopt, $data["new_value"]) ?? '', false, true);
                  break;
            }
         }

         if (empty($message)) {
            $newval = $data["new_value"];
            $oldval = $data["old_value"];

            if ($data['id_search_option'] == '70') {
               $newval_expl = explode(' ', $newval);
               $oldval_expl = explode(' ', $oldval);

               if ($oldval_expl[0] == '&nbsp;') {
                  $oldval = $data["old_value"];
               } else {
                  $old_iterator = $DBread->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => $oldval_expl[0]]]);
                  foreach ($old_iterator as $val) {
                     $oldval = sprintf(
                        __('%1$s %2$s'),
                        formatUserName(
                           $val['id'],
                           $oldval_expl[0],
                           $val['realname'],
                           $val['firstname']
                        ),
                        ($oldval_expl[1] ?? "0")
                     );
                  }
               }

               if ($newval_expl[0] == '&nbsp;') {
                  $newval = $data["new_value"];
               } else {
                  $new_iterator = $DBread->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => $newval_expl[0]]]);
                  foreach ($new_iterator as $val) {
                     $newval = sprintf(
                        __('%1$s %2$s'),
                        formatUserName(
                           $val['id'],
                           $newval_expl[0],
                           $val['realname'],
                           $val['firstname']
                        ),
                        ($newval_expl[1] ?? "0")
                     );
                  }
               }
            }
            $message = sprintf(
               __s('Change %1$s to %2$s'),
               '<del>' . htmlescape($oldval) . '</del>',
               '<ins>' . htmlescape($newval) . '</ins>'
            );
         }
      }
      return $message;
   }

   public static function convertFiltersValuesToSqlCriteria(array $filters)
   {
      $sql_filters = [];

      if (isset($filters['source']) && !empty($filters['source'])) {
         $type_source_crit = [];
         foreach ($filters['source'] as $index => $type_source) {
            $type_source_crit[] = ['source_type' => $type_source];
         }
         $sql_filters[] = [
            'OR' => $type_source_crit,
         ];
      }

      if (isset($filters['users_names']) && !empty($filters['users_names'])) {
         $users_names_crit = [];
         foreach ($filters['users_names'] as $index => $user_name) {
            $users_names_crit[] = ['users_id' => $user_name];
         }
         $sql_filters[] = [
            'OR' => $users_names_crit,
         ];
      }

      if (isset($filters['date']) && !empty($filters['date'])) {
         $sql_filters[] = [
            ['source_date' => ['>=', "{$filters['date']} 00:00:00"]],
            ['source_date' => ['<=', "{$filters['date']} 23:59:59"]],
         ];
      }

      return $sql_filters;
   }

   public static function install(Migration $migration): void
   {
      /** @var \DBmysql $DB */
      global $DB;

      $default_charset    = DBConnection::getDefaultCharset();
      $default_collation  = DBConnection::getDefaultCollation();
      $default_key_sign   = DBConnection::getDefaultPrimaryKeySignOption();

      $table = self::getTable();
      if (!$DB->tableExists($table)) {
         $migration->displayMessage("Installing $table");
         $query = "CREATE TABLE IF NOT EXISTS `$table` (
               `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
               `source_type` INT {$default_key_sign} NOT NULL default 0,
               `source_id` INT {$default_key_sign} NOT NULL default 0,
				   `source_date` TIMESTAMP NULL DEFAULT NULL,
               `users_id` INT {$default_key_sign} NOT NULL default 0,
               `itemtype` varchar(255) DEFAULT NULL,
               `items_id` INT {$default_key_sign} NOT NULL default 0,
               `severity_level` INT {$default_key_sign} NOT NULL default 0,
               `action_code` INT {$default_key_sign} NOT NULL default 0,
               `id_search_option` INT {$default_key_sign} NOT NULL default 0,
               `field` varchar(255) DEFAULT NULL,
               `old_value` varchar(255) DEFAULT NULL,
               `new_value` varchar(255) DEFAULT NULL,
               `message` TEXT,
               `service` varchar(255) DEFAULT NULL,
				   `date_creation` TIMESTAMP NULL DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `users_id` (`users_id`),
				   KEY `item` (`itemtype`, `items_id`),
				   KEY `source` (`source_type`, `source_id`),
				   KEY `source_date` (`source_date`),
				   KEY `date_creation` (`date_creation`)
            )ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

         $DB->doQuery($query);
      }
   }

   public static function uninstall(Migration $migration): void
   {
      $table = self::getTable();
      $migration->displayMessage("Uninstalling $table");
      $migration->dropTable($table);
   }
}

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

    public const LOG = 1;
    public const EVENT = 2;
    public const DOCUMENT = 3;

    public static function cronInfo(string $name)
    {
        switch (strtolower($name)) {
            case 'pluginaccesstransparencygetlogs':
                return ['description' => __('Get logs', 'accesstransparency'), 'parameter' => __('Limit number of logs to retrieve', 'accesstransparency')];
        }
        return [];
    }

    /** Source rows read and committed together with their cursor */
    private const INGEST_BATCH_SIZE = 500;

    public static function cronPluginAccesstransparencyGetLogs($crontask)
    {
        $limit = (int) $crontask->fields['param'];
        $excluded_users = array_flip(PluginAccesstransparencyConfig::getExcludedUsersIds());

        $tot = self::ingestSource(Log::getTable(), [], $limit, 'last_log_id', static function (array $row) use ($excluded_users): array {
            $inputs = [];
            foreach (self::getUsersIdsFromLogUserName((string) $row['user_name']) as $user_id) {
                if (isset($excluded_users[$user_id])) {
                    continue;
                }
                $inputs[] = [
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
                ];
            }
            return $inputs;
        });

        $events_criteria = [
            'OR' => [
                [
                    'service' => 'login',
                    'level' => 3,
                ],
                [
                    'service' => 'Impersonate',
                    'level' => 3,
                ],
                [
                    'service' => 'tracking',
                    'level' => 4,
                ],
                [
                    'service' => 'inventory',
                    'level' => 4,
                ],
                [
                    'service' => 'massiveaction',
                    'level' => 4,
                ],
                [
                    'service' => 'setup',
                    'level' => [3, 4],
                ],
            ],
        ];
        $tot += self::ingestSource(Glpi\Event::getTable(), $events_criteria, $limit, 'last_event_id', static function (array $row) use ($excluded_users): array {
            if ($row['service'] === 'Impersonate') {
                // "A starts/stops impersonating user B": the event concerns both users
                $users_ids = self::findUsersIdsInEventMessage((string) $row['message']);
            } else {
                $user_id = self::resolveUserIdFromEventMessage((string) $row['message']);
                $users_ids = $user_id > 0 ? [$user_id] : [];
            }

            $inputs = [];
            foreach ($users_ids as $user_id) {
                if (isset($excluded_users[$user_id])) {
                    continue;
                }
                $inputs[] = [
                    'source_type' => self::EVENT,
                    'source_id' => $row['id'],
                    'source_date' => $row['date'],
                    'users_id' => $user_id,
                    'items_id' => $row['items_id'],
                    'severity_level' => $row['level'],
                    'message' => $row['message'],
                    'service' => $row['service'],
                    // itemtype of the event item, used to check the viewer can see it (see getEventItemtypeField())
                    'field' => self::getEventItemtypeField((string) $row['type']),
                ];
            }
            return $inputs;
        });

        $crontask->setVolume($tot);
        return ($tot > 0 ? 1 : 0);
    }

    /**
     * Copy the new rows of a source table (glpi_logs, glpi_events), from its cursor in the configuration.
     *
     * The cursor stores the last *scanned* source id, not the last inserted one: rows that can't be attributed
     * to a user must not be read again on every run. Each batch of rows is inserted and its cursor saved in one
     * transaction, so an interrupted run neither loses nor duplicates rows when it is retried.
     *
     * @param array    $criteria extra WHERE criteria on the source table
     * @param int      $limit    max number of source rows to scan (0: no limit)
     * @param callable(array): array[] $map source row => inputs of the rows to add
     *
     * @return int number of rows added
     */
    private static function ingestSource(string $table, array $criteria, int $limit, string $cursor_field, callable $map): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $cursor = (int) (PluginAccesstransparencyConfig::getInstance()->fields[$cursor_field] ?? 0);
        $remaining = $limit > 0 ? $limit : PHP_INT_MAX;
        $added = 0;

        while ($remaining > 0) {
            $batch_size = min(self::INGEST_BATCH_SIZE, $remaining);
            $rows = iterator_to_array($DB->request([
                'FROM'  => $table,
                'WHERE' => ['id' => ['>', $cursor]] + $criteria,
                'ORDER' => 'id ASC',
                'LIMIT' => $batch_size,
            ]), false);
            if ($rows === []) {
                break;
            }

            $DB->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $cursor = (int) $row['id'];
                    foreach ($map($row) as $input) {
                        // add() returns false when a plugin hook vetoes the row: not counted
                        if ((new self())->add($input) !== false) {
                            $added++;
                        }
                    }
                }
                self::saveCursors([$cursor_field => $cursor]);
                $DB->commit();
            } catch (\Throwable $e) {
                $DB->rollBack();
                throw $e;
            }

            $remaining -= count($rows);
            if (count($rows) < $batch_size) {
                break;
            }
        }

        return $added;
    }

    private static function saveCursors(array $cursors): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $config = PluginAccesstransparencyConfig::getInstance();
        $DB->update(PluginAccesstransparencyConfig::getTable(), $cursors, ['id' => $config->getID()]);
        $config->fields = $cursors + $config->fields;
    }

    /**
     * Users of a glpi_logs.user_name value, as built by Log::history():
     * - "<name> (<id>)", from User::getNameForLog();
     * - "<name A> (<id A>) impersonated by <name B> (<id B>)" when impersonation is active, with a
     *   separator translated in the language of the session: the row is attributed to both users.
     *
     * Names are user-controlled and may contain "(<id>)" themselves ("Mallory (77)" gives "Mallory (77) (42)"),
     * so only the trailing id is trusted as is. The impersonated user is added only when the value starts with
     * their current name and id and ends with the impersonator's current name and id; when that can't be
     * verified (e.g. a user was renamed since), the row is attributed to the trailing id only.
     *
     * @return int[]
     */
    public static function getUsersIdsFromLogUserName(string $user_name): array
    {
        if (!preg_match('/^(.*) \((\d+)\)$/s', $user_name, $matches) || (int) $matches[2] <= 0) {
            return [];
        }
        $last_id = (int) $matches[2];
        $prefix = $matches[1];

        // Single user, whatever their name contains
        $last_name = self::getUserFormattedNameForLog($last_id);
        if ($last_name === null || $prefix === $last_name || !str_ends_with($prefix, $last_name)) {
            return [$last_id];
        }

        // Impersonation: "<name A> (<id A>)<separator><name B>", B being the user of the trailing id
        $head = substr($prefix, 0, strlen($prefix) - strlen($last_name));
        if (preg_match_all('/ \((\d+)\)/', $head, $candidates, PREG_OFFSET_CAPTURE)) {
            foreach ($candidates[1] as $index => [$first_id]) {
                $first_id = (int) $first_id;
                $segment_end = $candidates[0][$index][1] + strlen($candidates[0][$index][0]);
                if (
                    $first_id > 0
                    && $first_id !== $last_id
                    && $segment_end < strlen($head) // non-empty separator
                    && substr($head, 0, $candidates[0][$index][1]) === self::getUserFormattedNameForLog($first_id)
                ) {
                    return [$first_id, $last_id];
                }
            }
        }

        return [$last_id];
    }

    /**
     * Name part of User::getNameForLog() for the current name of the user, null if they don't exist.
     */
    private static function getUserFormattedNameForLog(int $users_id): ?string
    {
        /** @var \DBmysql $DB */
        global $DB;

        static $cache = [];
        if (!array_key_exists($users_id, $cache)) {
            $data = $DB->request([
                'SELECT' => ['name', 'realname', 'firstname'],
                'FROM'   => User::getTable(),
                'WHERE'  => ['id' => $users_id],
            ])->current();
            if ($data === null) {
                $cache[$users_id] = null;
            } elseif (!empty($data['realname'])) {
                $cache[$users_id] = $data['realname'] . (!empty($data['firstname']) ? ' ' . $data['firstname'] : '');
            } else {
                $cache[$users_id] = (string) $data['name'];
            }
        }

        return $cache[$users_id];
    }

    /**
     * Resolve users_id from a login event message without relying on translated sentence structure.
     *
     * The strategy is:
     * 1) Find all usernames contained in the event message as whole words.
     * 2) If there is a single match, use it.
     * 3) If there are many, keep only usernames that match the start of the message.
     * 4) If still ambiguous, return 0.
     */
    private static function resolveUserIdFromEventMessage(string $message): int
    {
        $matches = self::findUsersInEventMessage($message);

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

    /**
     * IDs of all the users whose login appears in the event message as a whole word.
     *
     * @return int[]
     */
    private static function findUsersIdsInEventMessage(string $message): array
    {
        return array_column(self::findUsersInEventMessage($message), 'id');
    }

    /**
     * Users whose login appears in the event message as a whole word.
     *
     * Failed login messages contain the login exactly as the visitor typed it, so a visitor can still get
     * an event attributed to a user by typing their login: the message is only ever displayed escaped.
     * Whole-word matching at least prevents logins contained in other words ("ad" in "admin").
     *
     * @return array<array{id: int, name: string}>
     */
    private static function findUsersInEventMessage(string $message): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $message = trim($message);
        if ($message === '') {
            return [];
        }

        $query = [
            'SELECT' => ['id', 'name'],
            'FROM' => User::getTable(),
            'WHERE' => [
                'name' => ['!=', ''],
                new \Glpi\DBAL\QueryExpression('INSTR(' . $DB->quoteValue($message) . ', ' . DBmysql::quoteName('name') . ') > 0'),
            ],
        ];

        $matches = [];
        foreach ($DB->request($query) as $row) {
            $name = (string) $row['name'];
            if ($name === '' || !preg_match('/(?<![\w.@-])' . preg_quote($name, '/') . '(?![\w.@-])/u', $message)) {
                continue;
            }
            $matches[] = [
                'id' => (int) $row['id'],
                'name' => $name,
            ];
        }

        return $matches;
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

    /**
     * The rows are only meant to be read through the plugin views (User and Document tabs and their CSV),
     * which check the viewed user/document and every item a row refers to.
     * Generic collection accesses (legacy REST API, search engine...) only check the plugin right and the
     * system criteria, so they never return any row: `id` is AUTO_INCREMENT and is never 0.
     */
    public static function getSystemSQLCriteria(?string $tablename = null): array
    {
        return [($tablename ?? static::getTable()) . '.id' => 0];
    }

    /**
     * Single item accesses (legacy REST API getItem, can()...) apply the same policy as the views:
     * the viewer must be able to read the user the row belongs to and the item it refers to.
     */
    public function canViewItem(): bool
    {
        if (!parent::canViewItem()) {
            return false;
        }

        $users_id = (int) ($this->fields['users_id'] ?? 0);
        $user = new User();
        return $users_id > 0
         && $user->can($users_id, READ)
         && self::canViewRowItem($this->fields);
    }

    public static function getIcon()
    {
        return 'ti ti-window';
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
                ],
            );
        }
        return self::createTabEntry(self::getTypeName(1), $nb, $item::getType());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof User && Session::haveRight(self::$rightname, READ)) {
            self::showForItem($item);
        }
        return true;
    }

    public static function showForItem(CommonDBTM $item, $withtemplate = 0)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!self::canView()) {
            return;
        }

        $itemtype = $item->getType();
        $items_id = $item->getField('id');

        $start       = max(0, (int) ($_GET["start"] ?? 0));
        $filters     = self::normalizeFilters($_GET['filters'] ?? []);
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
            'csv_url'           => $CFG_GLPI['root_doc'] . '/plugins/accesstransparency/front/export_user_csv.php?' . http_build_query([
                'id'      => $items_id,
                'filters' => $filters,
            ]),
        ]);
    }

    public static function getHistoryData(CommonDBTM $item, $start = 0, $limit = 0, array $sqlfilters = [])
    {
        $DBread = DBConnection::getReadConnection();

        $query = [
            'FROM' => self::getTable(),
            'WHERE' => [
                'users_id' => $item->getID(),
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
                    // canViewRowItem() already checked that the item exists and is readable
                    $itemtype = $data['itemtype'];
                    $item_label = sprintf('%s #%d', $itemtype::getTypeName(1), $data['items_id']);
                    $tmp['message'] = sprintf(
                        __s('%1$s: %2$s'),
                        sprintf(
                            '<a href="%s">%s</a>',
                            htmlescape($itemtype::getFormURLWithID($data['items_id'])),
                            htmlescape($item_label),
                        ),
                        $logmessage,
                    );
                    break;
                case self::EVENT:
                    $tmp['message'] = sprintf(
                        __s('%s event: %s', 'accesstransparency'),
                        htmlescape($data['service']),
                        htmlescape($data['message']),
                    );
                    break;
                case self::DOCUMENT:
                    $tmp['message'] = sprintf(
                        __s('Accessed document #%d', 'accesstransparency'),
                        $data['items_id'],
                    );
                    $source = self::resolveSourceItem($data['source_itemtype'] ?? null, (int) ($data['source_items_id'] ?? 0));
                    if ($source !== null) {
                        $tmp['message'] .= ' ' . sprintf(__s('(opened from %s)', 'accesstransparency'), $source['link']);
                    }
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
                $itemtype = (string) ($data['itemtype'] ?? '');
                $items_id = (int) ($data['items_id'] ?? 0);
                break;
            case self::EVENT:
                // `field` holds the itemtype resolved from the glpi_events type (since 1.3.0, backfilled on upgrade),
                // see getEventItemtypeField(): '' for core "system" events, NULL when the itemtype is unknown
                // (type not resolvable, plugin disabled, source event purged before the backfill).
                // Only events that never concern an item are safe then.
                $items_id = (int) ($data['items_id'] ?? 0);
                if ($data['field'] === null) {
                    return $items_id <= 0 && in_array($data['service'], ['login', 'Impersonate'], true);
                }
                $itemtype = (string) $data['field'];
                if ($itemtype === '') {
                    // Core "system" events are always logged with items_id 0
                    return $items_id <= 0;
                }
                break;
            default:
                return true;
        }

        if ($itemtype === '' || !is_a($itemtype, CommonDBTM::class, true)) {
            // Unknown or removed itemtype (e.g. its plugin is disabled): nothing can be checked, deny
            return false;
        }

        // Child items (e.g. RuleCriteria/RuleAction) resolve their parent class dynamically: if that parent
        // belongs to a plugin that is now disabled, its class can't be autoloaded and PHP throws an Error.
        // Such rows can't be checked, so they are hidden instead of breaking the whole tab.
        try {
            if ($items_id <= 0) {
                return $itemtype::canView();
            }

            $item = getItemForItemtype($itemtype);
            // @phpstan-ignore instanceof.alwaysTrue (getItemForItemtype() returns false for abstract classes)
            return $item instanceof CommonDBTM && $item->can($items_id, READ);
        } catch (\Error) {
            return false;
        }
    }

    /**
     * Resolve the item a document was opened from (a Ticket, a Change...) into a label and an HTML link.
     *
     * @return array{label: string, name: string, url: string, link: string}|null
     *         null when there is no source item, it no longer exists or the viewer can't read it
     */
    public static function resolveSourceItem(?string $itemtype, int $items_id): ?array
    {
        if ($itemtype === null || $itemtype === '' || $items_id <= 0 || !is_a($itemtype, CommonDBTM::class, true)) {
            return null;
        }

        try {
            $item = getItemForItemtype($itemtype);
            // @phpstan-ignore instanceof.alwaysTrue (getItemForItemtype() returns false for abstract classes)
            if (!($item instanceof CommonDBTM) || !$item->can($items_id, READ)) {
                return null;
            }
        } catch (\Error) {
            // Parent class from a disabled plugin can't be autoloaded
            return null;
        }

        $name = $item->getNameID(['forceid' => true]);
        $label = sprintf(__('%1$s: %2$s'), $itemtype::getTypeName(1), $name);
        $url = $item->getLinkURL();

        return [
            'label' => $itemtype::getTypeName(1),
            'name'  => $name,
            'url'   => $url,
            'link'  => sprintf('<a href="%s">%s</a>', htmlescape($url), htmlescape($label)),
        ];
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
                // Class names are case-insensitive for PHP ("location" passes): store the declared name
                $mapping[$type] = (new ReflectionClass($candidate))->getName();
                break;
            }
        }

        return $mapping[$type];
    }

    /**
     * Value stored in `field` for an event, from the `type` of its glpi_events row:
     * - the resolved itemtype;
     * - '' for core "system" events, the only events known to never concern an item;
     * - NULL when the itemtype can't be resolved: the row is then treated as concerning an unknown item.
     */
    public static function getEventItemtypeField(string $type): ?string
    {
        if ($type === 'system') {
            return '';
        }

        return self::resolveEventItemtype($type);
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
                        htmlescape($data["new_value"]),
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
                            htmlescape($tmpfield),
                        ),
                        sprintf(
                            __s('%1$s by %2$s'),
                            htmlescape($data["old_value"]),
                            htmlescape($data["new_value"]),
                        ),
                    );
                    break;

                case Log::HISTORY_DELETE_DEVICE:
                    //TRANS: %s is the component name
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["old_value"]),
                    );
                    break;

                case Log::HISTORY_LOCK_DEVICE:
                    //TRANS: %s is the component name
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["old_value"]),
                    );
                    break;

                case Log::HISTORY_UNLOCK_DEVICE:
                    //TRANS: %s is the component name
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["new_value"]),
                    );
                    break;

                case Log::HISTORY_INSTALL_SOFTWARE:
                    //TRANS: %s is the software name
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["new_value"]),
                    );
                    break;

                case Log::HISTORY_UNINSTALL_SOFTWARE:
                    //TRANS: %s is the software name
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["old_value"]),
                    );
                    break;

                case Log::HISTORY_DISCONNECT_DEVICE:
                    //TRANS: %s is the item name
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["old_value"]),
                    );
                    break;

                case Log::HISTORY_CONNECT_DEVICE:
                    //TRANS: %s is the item name
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["new_value"]),
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
                                htmlescape($as),
                            ),
                        );
                    } else {
                        $message = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($data["new_value"]),
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
                            htmlescape($data["new_value"]),
                        ),
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
                            htmlescape($as),
                        );
                    } else {
                        $message = sprintf(
                            __s('%1$s: %2$s'),
                            htmlescape($action_label),
                            htmlescape($data["old_value"]),
                        );
                    }
                    break;

                case Log::HISTORY_LOCK_RELATION:
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["old_value"]),
                    );
                    break;

                case Log::HISTORY_UNLOCK_RELATION:
                    $message = sprintf(
                        __s('%1$s: %2$s'),
                        htmlescape($action_label),
                        htmlescape($data["new_value"]),
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
                            htmlescape($data["new_value"]),
                        ),
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
                                htmlescape($data["new_value"]),
                            ),
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
                            htmlescape($data["old_value"]),
                        ),
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
                            htmlescape($data["old_value"]),
                        ),
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
                            htmlescape($data["new_value"]),
                        ),
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
                            htmlescape($data["new_value"]),
                        ),
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
                                    $val['firstname'],
                                ),
                                ($oldval_expl[1] ?? "0"),
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
                                    $val['firstname'],
                                ),
                                ($newval_expl[1] ?? "0"),
                            );
                        }
                    }
                }
                $message = sprintf(
                    __s('Change %1$s to %2$s'),
                    '<del>' . htmlescape($oldval) . '</del>',
                    '<ins>' . htmlescape($newval) . '</ins>',
                );
            }
        }
        return $message;
    }

    /**
     * Filters of the User/Document tabs and their CSV exports, from request input: only flat lists of
     * known values are kept (nested arrays would reach the SQL builder as operators, e.g. ['!=', 1]).
     *
     * @return array{active?: '1', source?: int[], users_names?: int[], date?: string}
     */
    public static function normalizeFilters(mixed $filters): array
    {
        if (!is_array($filters)) {
            return [];
        }

        $normalized = [];
        if (isset($filters['active'])) {
            $normalized['active'] = '1';
        }

        $source = self::filterIntList($filters['source'] ?? [], array_keys(self::getSourceType()));
        if ($source !== []) {
            $normalized['source'] = $source;
        }

        $users = self::filterIntList($filters['users_names'] ?? []);
        if ($users !== []) {
            $normalized['users_names'] = $users;
        }

        $date = $filters['date'] ?? null;
        if (is_string($date) && ($parsed = DateTime::createFromFormat('!Y-m-d', $date)) && $parsed->format('Y-m-d') === $date) {
            $normalized['date'] = $date;
        }

        return $normalized;
    }

    /**
     * Positive integers of a flat list, optionally restricted to allowed values (at most 100).
     *
     * @param int[]|null $allowed
     * @return int[]
     */
    private static function filterIntList(mixed $values, ?array $allowed = null): array
    {
        if (!is_array($values)) {
            return [];
        }

        $list = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value !== false && ($allowed === null || in_array($value, $allowed, true))) {
                $list[$value] = $value;
            }
        }

        return array_slice(array_values($list), 0, 100);
    }

    public static function convertFiltersValuesToSqlCriteria(array $filters)
    {
        $filters = self::normalizeFilters($filters);
        $sql_filters = [];

        if (isset($filters['source'])) {
            $sql_filters[] = ['source_type' => $filters['source']];
        }

        if (isset($filters['users_names'])) {
            $sql_filters[] = ['users_id' => $filters['users_names']];
        }

        if (isset($filters['date'])) {
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
               `source_itemtype` varchar(255) DEFAULT NULL,
               `source_items_id` INT {$default_key_sign} NOT NULL default 0,
				   `date_creation` TIMESTAMP NULL DEFAULT NULL,
               PRIMARY KEY (`id`),
               KEY `users_id_source_date` (`users_id`, `source_date`),
				   KEY `item` (`itemtype`, `items_id`),
				   KEY `source` (`source_type`, `source_id`),
				   KEY `source_date` (`source_date`),
				   KEY `date_creation` (`date_creation`)
            )ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        } else {
            // 1.3.0: item a document was opened from
            $migration->addField($table, 'source_itemtype', 'string', ['after' => 'service']);
            $migration->addField($table, 'source_items_id', 'fkey', ['after' => 'source_itemtype']);
            // 1.3.0: store the itemtype of the events ingested before, to check the viewer can see their item
            self::backfillEventsItemtype();

            // 1.3.0: the User tab filters by users_id and sorts by source_date
            $migration->addKey($table, ['users_id', 'source_date'], 'users_id_source_date');
            $migration->dropKey($table, 'users_id');
        }
    }

    /**
     * Fill the itemtype (`field`) of event rows that don't have it, from their source glpi_events row.
     * Rows whose source event no longer exists stay NULL and are treated as not visible.
     */
    private static function backfillEventsItemtype(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();
        $events_table = Glpi\Event::getTable();

        // 1.3.0-beta stored '' for every unresolved type: only core "system" events (items_id 0) may keep it.
        // The others go back to NULL ("unknown") to be resolved again below, or stay hidden.
        // Rows stored by getEventItemtypeField() never match, so this can run on every update.
        $DB->update(
            $table,
            ["$table.field" => null],
            [
                "$table.source_type" => self::EVENT,
                "$table.field"       => '',
                'OR'                 => [
                    "$table.items_id"    => ['>', 0],
                    "$events_table.id"   => null,
                    "$events_table.type" => ['!=', 'system'],
                ],
            ],
            ['LEFT JOIN' => [$events_table => ['ON' => [$table => 'source_id', $events_table => 'id']]]],
        );

        // 1.3.0-beta could store an itemtype with the case of the glpi_events type ("location"):
        // store the declared class name, or NULL ("unknown") when the class can't be loaded anymore.
        $stored = $DB->request([
            'SELECT'   => [new \Glpi\DBAL\QueryExpression('DISTINCT BINARY ' . DBmysql::quoteName('field') . ' AS ' . DBmysql::quoteName('field'))],
            'FROM'     => $table,
            'WHERE'    => ['source_type' => self::EVENT, 'NOT' => ['field' => null], ['field' => ['!=', '']]],
        ]);
        foreach ($stored as $row) {
            $field = (string) $row['field'];
            $canonical = is_a($field, CommonDBTM::class, true) ? (new ReflectionClass($field))->getName() : null;
            if ($canonical !== $field) {
                // BINARY: the collation is case-insensitive, `field = 'location'` would also match 'Location'
                $DB->update(
                    $table,
                    ['field' => $canonical],
                    ['source_type' => self::EVENT, new \Glpi\DBAL\QueryExpression('BINARY ' . DBmysql::quoteName('field') . ' = ' . $DB->quoteValue($field))],
                );
            }
        }

        $types = $DB->request([
            'SELECT'     => "$events_table.type",
            'DISTINCT'   => true,
            'FROM'       => $table,
            'INNER JOIN' => [$events_table => ['ON' => [$table => 'source_id', $events_table => 'id']]],
            'WHERE'      => ["$table.source_type" => self::EVENT, "$table.field" => null],
        ]);
        foreach ($types as $row) {
            $field = self::getEventItemtypeField((string) $row['type']);
            if ($field === null) {
                // Still unknown (e.g. its plugin is disabled): left NULL, resolved by a later update if it comes back
                continue;
            }
            $DB->update(
                $table,
                ['field' => $field],
                ["$table.source_type" => self::EVENT, "$table.field" => null, "$events_table.type" => $row['type']],
                ['INNER JOIN' => [$events_table => ['ON' => [$table => 'source_id', $events_table => 'id']]]],
            );
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $table = self::getTable();
        $migration->displayMessage("Uninstalling $table");
        $migration->dropTable($table);
    }
}

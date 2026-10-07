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

    $migration->executeMigration();

    plugin_accesstransparency_migrate_legacy_tables($migration);

    return true;
}

/**
 * Bring the data of previous versions into the logs table, then drop their tables.
 *
 * - glpi_plugin_accesstransparency_userinteractions: document accesses of 1.0.x/1.1.x
 *   (and of the TICGAL-Dev/marketplace line), the only data that exists nowhere else.
 * - glpi_plugin_accesstransparency_lastLog / _logevents: 1.1.x copies of glpi_logs/glpi_events,
 *   re-imported from GLPI by the log ingestion cron.
 *
 * @return void
 */
function plugin_accesstransparency_migrate_legacy_tables(Migration $migration): void
{
    /** @var \DBmysql $DB */
    global $DB;

    $old_table = 'glpi_plugin_accesstransparency_userinteractions';
    if ($DB->tableExists($old_table)) {
        $migration->displayMessage("Migrating $old_table");

        $logs_table = PluginAccesstransparencyLog::getTable();
        $has_source_item = $DB->fieldExists($old_table, 'source_itemtype') && $DB->fieldExists($old_table, 'source_items_id');

        // All rows or none; the old table is dropped only once they are committed.
        // A run interrupted between the commit and the drop is retried without duplicates (existing rows are skipped).
        $DB->beginTransaction();
        try {
            foreach ($DB->request(['FROM' => $old_table, 'ORDER' => 'id ASC']) as $row) {
                // The document id column changed name across versions, and 1.0.x only stored the path
                $docid = (int) ($row['documents_id'] ?? $row['document_id'] ?? 0);
                if ($docid <= 0 && preg_match('/docid=(\d+)/', (string) $row['path'], $matches)) {
                    $docid = (int) $matches[1];
                }
                if ($docid <= 0 || (int) $row['users_id'] <= 0) {
                    continue;
                }

                $values = [
                    'source_type'     => PluginAccesstransparencyLog::DOCUMENT,
                    'source_id'       => $docid,
                    'source_date'     => $row['date_creation'],
                    'users_id'        => (int) $row['users_id'],
                    'itemtype'        => Document::getType(),
                    'items_id'        => $docid,
                    'new_value'       => mb_substr((string) $row['path'], 0, 255),
                    'source_itemtype' => $has_source_item ? $row['source_itemtype'] : null,
                    'source_items_id' => $has_source_item ? (int) $row['source_items_id'] : 0,
                    'date_creation'   => $row['date_creation'],
                ];
                $already_migrated = countElementsInTable($logs_table, [
                    'users_id'    => $values['users_id'],
                    'source_date' => $values['source_date'],
                    'source_type' => $values['source_type'],
                    'items_id'    => $values['items_id'],
                ]) > 0;
                if (!$already_migrated) {
                    $DB->insert($logs_table, $values);
                }
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        $migration->dropTable($old_table);
    }

    foreach (['glpi_plugin_accesstransparency_lastLog', 'glpi_plugin_accesstransparency_logevents'] as $table) {
        $migration->dropTable($table);
    }

    // Purge task of the removed PluginAccesstransparencyUserinteractions class
    $DB->delete(CronTask::getTable(), ['itemtype' => 'PluginAccesstransparencyUserinteractions']);
}

/**
 * Record every document download/open, whatever the way the user reached it
 * (document list, ticket timeline, direct URL...). Called on every request via Hooks::POST_INIT.
 *
 * The access is recorded from the response, not the request: only when front/document.send.php
 * actually served the file (a missing file, a bad checksum or a denied access end with an exception).
 *
 * @return void
 */
function plugin_accesstransparency_track_document_download(): void
{
    /** @var \Glpi\Kernel\Kernel|null $kernel */
    global $kernel;

    if (!isset($_SERVER['REQUEST_URI']) || !($kernel instanceof \Glpi\Kernel\Kernel)) {
        return; // CLI
    }

    $kernel->getContainer()->get('event_dispatcher')->addListener(
        \Symfony\Component\HttpKernel\KernelEvents::RESPONSE,
        'plugin_accesstransparency_record_document_response',
    );
}

/**
 * kernel.response listener registered by plugin_accesstransparency_track_document_download().
 *
 * @return void
 */
function plugin_accesstransparency_record_document_response(\Symfony\Component\HttpKernel\Event\ResponseEvent $event): void
{
    $request = $event->getRequest();
    $response = $event->getResponse();

    // GET only: HEAD gets the headers, not the document.
    if (!$event->isMainRequest() || !$request->isMethod('GET')) {
        return;
    }

    // The script that serves the request, not the raw path: the legacy router also resolves
    // "/front/document.send.php/<anything>" to this script.
    $script = $request->attributes->get(\Glpi\Controller\LegacyFileLoadController::REQUEST_FILE_KEY);
    $send_script = realpath(GLPI_ROOT . '/front/document.send.php');
    $is_legacy_send = is_string($script) && $send_script !== false && realpath($script) === $send_script;

    // High-Level API v2: GET /api.php[/v2.x]/Management/Document/{id}/Download
    $api_docid = null;
    if (preg_match('#^/api\.php(?:/v\d+(?:\.\d+)*)?/Management/Document/(\d+)/Download/?$#', $request->getPathInfo(), $matches)) {
        $api_docid = (int) $matches[1];
    }

    if (!$is_legacy_send && $api_docid === null) {
        return;
    }
    // 200: file sent, 304: authorized re-open of the copy cached by the browser
    if (!$response->isSuccessful() && $response->getStatusCode() !== 304) {
        return;
    }

    $docid = $is_legacy_send
        ? filter_var($request->query->get('docid'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : $api_docid;

    // While impersonating, the session is the impersonated user's: the actor is the impersonator
    $users_id = Session::getImpersonatorId() ?? Session::getLoginUserID();
    if ($docid === false || $docid < 1 || !$users_id || PluginAccesstransparencyConfig::isUserExcluded()) {
        return;
    }
    if (Session::isImpersonateActive()) {
        $impersonator = new User();
        if (!$impersonator->getFromDB($users_id) || PluginAccesstransparencyConfig::isUserExcluded((string) $impersonator->fields['name'])) {
            return;
        }
    }

    // Recording must never break the download
    try {
        $doc = new Document();
        if (!$doc->getFromDB($docid)) {
            return;
        }

        // The Documents tab of an item links with itemtype/items_id, the ITIL timeline with tickets_id,
        // changes_id or problems_id (the legacy parameters are only read when itemtype is absent, like the core does)
        $itemtype = $is_legacy_send ? $request->query->get('itemtype') : null;
        $items_id = $is_legacy_send ? $request->query->get('items_id') : null;
        if ($is_legacy_send && $itemtype === null) {
            foreach (['tickets_id' => Ticket::class, 'changes_id' => Change::class, 'problems_id' => Problem::class] as $param => $class) {
                if ($request->query->has($param)) {
                    $itemtype = $class;
                    $items_id = $request->query->get($param);
                    break;
                }
            }
        }

        $source = plugin_accesstransparency_get_document_source($doc, $itemtype, $items_id);

        $log = new PluginAccesstransparencyLog();
        $log->add([
            'source_type'     => PluginAccesstransparencyLog::DOCUMENT,
            'source_id'       => $docid,
            'source_date'     => $_SESSION['glpi_currenttime'],
            'itemtype'        => Document::getType(),
            'items_id'        => $docid,
            'users_id'        => $users_id,
            'new_value'       => $is_legacy_send ? '/front/document.send.php?docid=' . $docid : $request->getPathInfo(),
            'source_itemtype' => $source[0] ?? null,
            'source_items_id' => $source[1] ?? 0,
        ]);
    } catch (\Throwable $e) {
        \Glpi\Error\ErrorHandler::logCaughtException($e);
    }
}

/**
 * Item a document was opened from, from the parameters of the download link: itemtype/items_id
 * (Document::getDownloadLink($linked_item), Documents tab) or tickets_id/changes_id/problems_id (ITIL timeline).
 * It is request input: only kept when the item exists, the user can read it and the document is linked to it
 * (the same checks as Document::canViewFile() for the item it is opened from).
 *
 * @return array{0: class-string<CommonDBTM>, 1: int}|null
 */
function plugin_accesstransparency_get_document_source(Document $doc, mixed $itemtype, mixed $items_id): ?array
{
    /** @var \DBmysql $DB */
    global $DB;

    $items_id = filter_var($items_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_string($itemtype) || $itemtype === '' || $items_id === false || !is_a($itemtype, CommonDBTM::class, true)) {
        return null;
    }

    $item = getItemForItemtype($itemtype);
    // @phpstan-ignore instanceof.alwaysTrue (getItemForItemtype() returns false for abstract classes)
    if (!($item instanceof CommonDBTM) || !$item->can($items_id, READ)) {
        return null;
    }

    // ITIL objects: documents of the object and of its timeline (followups, tasks, solutions...) the user can see
    $link_criteria = $item instanceof CommonITILObject
      ? $item->getAssociatedDocumentsCriteria()
      : ['itemtype' => $item::class, 'items_id' => $items_id];
    $linked = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => Document_Item::getTable(),
        'WHERE' => ['documents_id' => $doc->getID(), $link_criteria],
    ])->current();
    if ((int) $linked['cpt'] === 0) {
        return null;
    }

    // Declared class name (class names are case-insensitive for PHP)
    return [$item::class, $items_id];
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

    // Tables of previous versions that may still be there
    foreach (
        [
            'glpi_plugin_accesstransparency_userinteractions',
            'glpi_plugin_accesstransparency_lastLog',
            'glpi_plugin_accesstransparency_logevents',
        ] as $table
    ) {
        $migration->dropTable($table);
    }

    $migration->executeMigration();

    // GLPI does not remove the automatic actions of an uninstalled plugin
    CronTask::unregister('accesstransparency');

    return true;
}

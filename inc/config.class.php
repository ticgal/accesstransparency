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

class PluginAccesstransparencyConfig extends CommonDBTM
{
    public static $rightname = 'config';

    private static ?self $instance = null;

    public const KEEP_ALL   = 'keep_all';

    /**
     * {@inheritDoc}
     */
    public function __construct()
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($DB->tableExists($this->getTable())) {
            $this->getFromDB(1);
        }
    }

    public static function getTypeName($nb = 0): string
    {
        return 'Access Transparency';
    }

    public static function getInstance(int $n = 1): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            if (!self::$instance->getFromDB($n)) {
                self::$instance->getEmpty();
            }
        }
        return self::$instance;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if ($item::getType() === Config::getType()) {
            return self::createTabEntry(self::getTypeName());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item::getType() === Config::getType()) {
            self::showConfigForm();
        }
        return true;
    }

    public static function showConfigForm(): bool
    {

        $config = self::getInstance();

        TemplateRenderer::getInstance()->display('@accesstransparency/pages/config.html.twig', [
            'config' => $config,
            'canedit' => Session::haveRight(self::$rightname, UPDATE),
            'form_path' => $config->getFormURL(),
            'logs_interval_options' => self::getLogRetentionOptions(),
        ]);

        return true;
    }

    public static function getLogRetentionOptions(): array
    {
        $values = [
            self::KEEP_ALL => __('Keep all logs', 'accesstransparency'),
        ];
        for ($i = 1; $i <= 120; $i++) {
            $values[$i] = sprintf(_n('Delete if older than %s month', 'Delete if older than %s months', $i, 'accesstransparency'), $i);
        }
        return $values;
    }

    public function prepareInputForUpdate($input): false|array
    {
        // Only the fields of the form: the ingestion cursors (last_log_id, last_event_id) are managed internally
        $input = array_filter(
            $input,
            static fn($key) => in_array($key, ['id', 'log_retention_minutes', 'excluded_logins'], true) || str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        if (array_key_exists('log_retention_minutes', $input)) {
            $retention = (string) $input['log_retention_minutes'];
            if (!array_key_exists($retention, self::getLogRetentionOptions())) {
                Session::addMessageAfterRedirect(__s('Invalid log retention', 'accesstransparency'), false, ERROR);
                return false;
            }
            $input['log_retention_minutes'] = $retention;
        }

        if (array_key_exists('excluded_logins', $input)) {
            if (!is_string($input['excluded_logins'])) {
                return false;
            }
            // Stored normalized, comma-separated like the (single line) form field
            $input['excluded_logins'] = implode(', ', $this->normalizeLoginsList($input['excluded_logins']));
        }

        // Log update fields in history manually
        foreach ($this->fields as $key => $value) {
            if (isset($input[$key]) && $input[$key] != $value) {
                Log::history(1, Config::class, [1, $key . ' ' . $value, $input[$key]]);
            }
        }
        return $input;
    }

    /**
     * Logins (lowercase) of the accounts that must not be tracked, e.g. service or inventory accounts.
     *
     * @return string[]
     */
    public function getExcludedLoginsList(): array
    {
        return $this->normalizeLoginsList((string) ($this->fields['excluded_logins'] ?? ''));
    }

    /**
     * @return string[] lowercase logins, from a list separated by new lines or commas
     */
    private function normalizeLoginsList(string $raw): array
    {
        $logins = preg_split('/[\r\n,]+/', $raw) ?: [];
        $logins = array_filter(array_map(static fn($login) => mb_strtolower(trim($login)), $logins), static fn($login) => $login !== '');
        return array_values(array_unique($logins));
    }

    /**
     * Is the given login (default: the current user) excluded from tracking?
     */
    public static function isUserExcluded(?string $login = null): bool
    {
        $login ??= $_SESSION['glpiname'] ?? '';
        if ($login === '') {
            return false;
        }

        return in_array(mb_strtolower($login), self::getInstance()->getExcludedLoginsList(), true);
    }

    /**
     * IDs of the users whose login is excluded from tracking.
     *
     * @return int[]
     */
    public static function getExcludedUsersIds(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $logins = self::getInstance()->getExcludedLoginsList();
        if ($logins === []) {
            return [];
        }

        $ids = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => User::getTable(), 'WHERE' => ['name' => $logins]]) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    public static function cronInfo(string $name)
    {
        switch (strtolower($name)) {
            case 'purgeaccesstransparencylogs':
                return ['description' => __('Purge old logs', 'accesstransparency')];
        }

        return [];
    }

    public static function cronPurgeAccessTransparencyLogs(CronTask $crontask)
    {
        /** @var \DBmysql $DB */
        global $DB;

        $config = self::getInstance();
        $time  = $config->fields['log_retention_minutes'] ?? self::KEEP_ALL;

        // Anything other than a number of months (keep_all, NULL, legacy values) keeps everything:
        // (int) of those values is 0, which would purge every row older than now.
        $months = is_numeric($time) ? (int) $time : 0;
        if ($months < 1) {
            return 0;
        }

        $table = PluginAccesstransparencyLog::getTable();
        $where = ['source_date' => ['<', date('Y-m-d H:i:s', strtotime(sprintf('-%d months', $months)))]];

        $count = countElementsInTable($table, $where);
        if ($count > 0) {
            // One DELETE statement: deleteByCriteria() loads and deletes the rows one by one
            $DB->delete($table, $where);
        }

        $crontask->addVolume($count);
        return $count > 0 ? 1 : 0;
    }

    public static function getIcon(): string
    {
        return 'ti ti-window';
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
            `log_retention_minutes` VARCHAR(50) DEFAULT NULL,
            `excluded_logins` TEXT DEFAULT NULL,
            `last_log_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `last_event_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
         )ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);

            $config = new self();
            $config->add([
                'id' => 1,
                'log_retention_minutes' => self::KEEP_ALL,
                'excluded_logins' => '',
            ]);
        } else {
            // 1.3.0 (already present on installs coming from the TICGAL-Dev/marketplace line)
            $migration->addField($table, 'excluded_logins', 'text', ['after' => 'log_retention_minutes']);

            // 1.3.0: cursors of the log ingestion cron, started where the previous version stopped
            $logs_table = PluginAccesstransparencyLog::getTable();
            foreach (['last_log_id' => PluginAccesstransparencyLog::LOG, 'last_event_id' => PluginAccesstransparencyLog::EVENT] as $field => $source_type) {
                if (!$DB->fieldExists($table, $field)) {
                    $migration->addField($table, $field, 'fkey');
                    if ($DB->tableExists($logs_table)) {
                        $migration->addPostQuery(
                            "UPDATE `$table` SET `$field` = (SELECT COALESCE(MAX(`source_id`), 0) FROM `$logs_table` WHERE `source_type` = $source_type)",
                        );
                    }
                }
            }

            // 1.1.x leftovers
            $migration->dropField($table, 'file_log_retention_minutes');

            // The "delete all" option no longer exists, and NULL used to mean "keep all"
            $migration->addPostQuery($DB->buildUpdate(
                $table,
                ['log_retention_minutes' => self::KEEP_ALL],
                [
                    'OR' => [
                        ['log_retention_minutes' => null],
                        ['NOT' => ['log_retention_minutes' => array_merge([self::KEEP_ALL], array_map('strval', range(1, 120)))]],
                    ],
                ],
            ));
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $table = self::getTable();
        $migration->displayMessage("Uninstalling $table");
        $migration->dropTable($table);
    }
}

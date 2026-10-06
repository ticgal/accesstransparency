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

use Glpi\Csv\ExportToCsvInterface;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV export of the rows displayed on the User and Document tabs.
 */
class PluginAccesstransparencyCsvexport implements ExportToCsvInterface
{
    /**
     * Maximum number of rows exported at once (each row may need several queries to be built)
     */
    public const MAX_ROWS = 10000;

    private string $filename;
    /** @var string[] */
    private array $header;
    /** @var array<array<string|int|null>> */
    private array $rows;

    /**
     * @param string $filename
     * @param string[] $header
     * @param array<array<string|int|null>> $rows Values may contain the HTML built for the tabs
     */
    public function __construct(string $filename, array $header, array $rows)
    {
        $this->filename = $filename;
        $this->header   = $header;
        $this->rows     = $rows;
    }

    public function getFileName(): ?string
    {
        return $this->filename;
    }

    public function getFileHeader(): array
    {
        return $this->header;
    }

    public function getFileContent(): array
    {
        return array_map(
            static fn(array $row) => array_map([self::class, 'toText'], $row),
            $this->rows,
        );
    }

    /**
     * Build the CSV as a response, to be returned by the legacy front script.
     *
     * Glpi\Csv\CsvResponse::output() sends the headers and flushes the output itself,
     * which GLPI 11 reports as unexpected output of a legacy script.
     */
    public function toResponse(): Response
    {
        // Same settings as Glpi\Csv\CsvResponse::output()
        $csv = Writer::createFromString('');
        $csv->setEscape('');
        $csv->setDelimiter($_SESSION["glpicsv_delimiter"] ?? ";");
        $csv->insertOne($this->getFileHeader());
        $csv->insertAll($this->getFileContent());

        return new Response($csv->toString(), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, (string) $this->getFileName()),
        ]);
    }

    /**
     * Convert the HTML of a displayed value (escaped text, links, <del>/<ins>) back to plain text.
     */
    public static function toText($value): string
    {
        $text = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Values such as user names are user-controlled: never let a spreadsheet run them as formulas
        return preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
    }

    /**
     * Row appended to an export truncated to MAX_ROWS.
     *
     * @return string[]
     */
    public static function getTruncatedRow(int $columns): array
    {
        return array_pad(
            [sprintf(__('Export limited to the %d most recent entries', 'accesstransparency'), self::MAX_ROWS)],
            $columns,
            '',
        );
    }
}

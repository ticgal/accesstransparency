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

/**
 * CSV export of the rows displayed on the User and Document tabs.
 */
class PluginAccesstransparencyCsvexport implements ExportToCsvInterface
{
   /**
    * @param string $filename
    * @param string[] $header
    * @param array<array<string|int|null>> $rows Values may contain the HTML built for the tabs
    */
   public function __construct(
      private string $filename,
      private array $header,
      private array $rows
   ) {
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
         $this->rows
      );
   }

   /**
    * Convert the HTML of a displayed value (escaped text, links, <del>/<ins>) back to plain text.
    */
   public static function toText($value): string
   {
      return trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
   }
}

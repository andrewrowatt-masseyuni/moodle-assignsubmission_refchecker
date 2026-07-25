<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace assignsubmission_refchecker\local\source;

/**
 * A bibliographic database that can be asked whether a reference exists.
 *
 * Implementations exist so the set of databases searched can change without touching the pipeline.
 * That matters here: which databases are worth searching depends heavily on discipline, and this
 * plugin is intended for general use, so a third source may well need adding once real usage shows
 * where the coverage gaps are.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface reference_source {
    /**
     * The short identifier stored against a result, e.g. "crossref".
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * The human readable name shown in the report.
     *
     * @return string
     */
    public function get_display_name(): string;

    /**
     * Look a reference up.
     *
     * @param array $reference Parsed reference: raw, title, authors, journal, year, doi.
     * @return array|null A candidate record, or null when nothing plausible was found. The record
     *     has keys: title, authors (string[]), journal, year, doi, url, citations, retracted.
     * @throws \assignsubmission_refchecker\local\exception\transient_exception On a retryable fault.
     * @throws \assignsubmission_refchecker\local\exception\permanent_exception On an unretryable fault.
     */
    public function check(array $reference): ?array;

    /**
     * Whether the service is reachable, for the admin health check.
     *
     * @return bool
     */
    public function is_available(): bool;
}

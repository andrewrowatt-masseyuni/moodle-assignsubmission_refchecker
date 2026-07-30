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

namespace assignsubmission_refchecker\local;

/**
 * Decoders for the reference columns that hold JSON.
 *
 * foundauthors, issues and formatted are written by the checking tasks from whatever the external
 * databases returned, and are read by both the on screen report and the downloadable one. Sharing
 * the decoding is what stops the two drifting on how they treat a null, empty or malformed value:
 * a reference whose author list failed to encode must come out as "no authors" everywhere, never
 * as a warning in one place and a fatal in the other.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_columns {
    /**
     * Decode a JSON array column, tolerating null and malformed values.
     *
     * @param string|null $json
     * @return string[]
     */
    public static function decode_list(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /**
     * Decode a JSON list of records, tolerating null and malformed values.
     *
     * Separate from decode_list() because that one flattens every element to a string, which is
     * right for a list of author names and wrong for the issue column: an issue is a code plus its
     * placeholder values, and casting it to a string would turn it into the word "Array".
     *
     * @param string|null $json
     * @return array[]
     */
    public static function decode_records(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded)) : [];
    }

    /**
     * Decode a JSON object column, tolerating null and malformed values.
     *
     * @param string|null $json
     * @return array<string, string>
     */
    public static function decode_map(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}

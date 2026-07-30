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
 * The specific ways a reference can disagree with the work that was found.
 *
 * An issue is stored as a code plus whatever the wording needs to interpolate, never as a finished
 * sentence. Three reasons, all of which bit before this class existed:
 *
 * - Issues are detected in cron, so a sentence built at detection time is frozen in whatever
 *   language cron happened to be running in, and no amount of switching language afterwards can
 *   translate it.
 * - The report has one audience that may see operational detail and one that may not, and a code can
 *   be worded differently for each. A sentence cannot.
 * - Some issues quote the reference back to the reader, and a result is shared site-wide through
 *   `assignsubmission_refchecker_cache` once it is known. A code carries no submitted text, so it
 *   can be discarded on the way into that table and re-derived on the way out.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issue {
    /** @var string Some of the people cited are not on the work that was found. */
    public const AUTHORS = 'authors';

    /** @var string None of the people on the work that was found are cited. */
    public const AUTHORS_NONE = 'authors_none';

    /** @var string The reference names people who are not on the work that was found at all. */
    public const EXTRAAUTHORS = 'extraauthors';

    /** @var string The cited year and the found year disagree by more than a version difference. */
    public const YEAR = 'year';

    /** @var string The years disagree by an amount a preprint and its published form explains. */
    public const YEAR_VERSION = 'year_version';

    /** @var string The cited year has not happened yet. */
    public const YEAR_FUTURE = 'year_future';

    /** @var string The cited DOI resolves to something else. */
    public const DOI = 'doi';

    /** @var string The cited journal is not where the work that was found appeared. */
    public const JOURNAL = 'journal';

    /** @var string One venue is a preprint server and the other is where it was published. */
    public const JOURNAL_VERSION = 'journal_version';

    /**
     * Every code this plugin can record.
     *
     * Anything absent is treated as unrecognised rather than rendered, so a result recorded by a
     * newer version of the plugin cannot put a raw string in front of a reader.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::AUTHORS,
            self::AUTHORS_NONE,
            self::EXTRAAUTHORS,
            self::YEAR,
            self::YEAR_VERSION,
            self::YEAR_FUTURE,
            self::DOI,
            self::JOURNAL,
            self::JOURNAL_VERSION,
        ];
    }

    /**
     * Build one issue record.
     *
     * @param string $code One of the constants on this class.
     * @param array $a Placeholder values for the wording, if it takes any.
     * @return array{code: string, a?: array}
     */
    public static function make(string $code, array $a = []): array {
        return $a === [] ? ['code' => $code] : ['code' => $code, 'a' => $a];
    }

    /**
     * Whether a list of issues contains a given code.
     *
     * @param array $issues As returned by the matcher or decoded from the database.
     * @param string $code
     * @return bool
     */
    public static function has(array $issues, string $code): bool {
        foreach ($issues as $record) {
            if (is_array($record) && ($record['code'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sentence for one issue, in the language of the person reading it.
     *
     * @param mixed $record An issue record. A bare string is passed through, so a result stored by
     *      an older version of the plugin still reads correctly rather than disappearing.
     * @return string Empty when the code is not one this version recognises.
     */
    public static function format($record): string {
        if (is_string($record)) {
            return $record;
        }

        if (!is_array($record)) {
            return '';
        }

        $code = (string) ($record['code'] ?? '');
        if (!in_array($code, self::all(), true)) {
            return '';
        }

        $a = (array) ($record['a'] ?? []);

        return get_string('issue_' . $code, 'assignsubmission_refchecker', $a === [] ? null : (object) $a);
    }

    /**
     * The sentences for a list of issues, dropping any that cannot be rendered.
     *
     * @param array $issues
     * @return string[]
     */
    public static function format_all(array $issues): array {
        $out = [];
        foreach ($issues as $record) {
            $sentence = self::format($record);
            if ($sentence !== '') {
                $out[] = $sentence;
            }
        }

        return $out;
    }
}

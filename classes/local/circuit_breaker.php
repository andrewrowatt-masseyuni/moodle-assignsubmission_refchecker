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
 * Stands a repeatedly failing database down for a while.
 *
 * Some of these services are simply unreliable. DBLP answers with HTTP 500s and empty replies when
 * it is busy, and while that lasts every reference in a submission pays a failed request, a wait,
 * and a line in the site log. Backing off means one failure costs one request rather than one per
 * reference, and the service is tried again shortly afterwards.
 *
 * Deliberately per source: a source standing down never affects the others, and the answer from the
 * services that are healthy is still used.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class circuit_breaker {
    /** @var int Failures tolerated before a source is stood down at all. */
    public const FAILURES_BEFORE_BACKOFF = 2;

    /** @var int How long the first stand-down lasts, in seconds. It doubles from there. */
    public const BASE_BACKOFF = 60;

    /** @var int However bad things get, try again at least this often. */
    public const MAX_BACKOFF = 900;

    /**
     * Whether this source should be skipped for now.
     *
     * @param string $source
     * @return bool
     */
    public static function is_open(string $source): bool {
        global $DB;

        $skipuntil = $DB->get_field(rate_limiter::TABLE, 'skipuntil', ['source' => $source]);

        return $skipuntil !== false && (int) $skipuntil > time();
    }

    /**
     * How long a stood-down source has left, in seconds.
     *
     * @param string $source
     * @return int Zero when the source is available.
     */
    public static function remaining(string $source): int {
        global $DB;

        $skipuntil = (int) $DB->get_field(rate_limiter::TABLE, 'skipuntil', ['source' => $source]);

        return max(0, $skipuntil - time());
    }

    /**
     * Note that a source failed.
     *
     * The first failure or two are ignored: services hiccup, and standing one down on a single
     * blip would lose answers it could have given.
     *
     * @param string $source
     * @return void
     */
    public static function record_failure(string $source): void {
        global $DB;

        $record = self::record_for($source);
        $failures = (int) $record->failures + 1;

        $skipuntil = 0;
        if ($failures >= self::FAILURES_BEFORE_BACKOFF) {
            $backoff = min(
                self::BASE_BACKOFF * (2 ** ($failures - self::FAILURES_BEFORE_BACKOFF)),
                self::MAX_BACKOFF,
            );
            $skipuntil = time() + $backoff;
        }

        $DB->update_record(rate_limiter::TABLE, (object) [
            'id' => $record->id,
            'failures' => $failures,
            'skipuntil' => $skipuntil,
            'timemodified' => time(),
        ]);
    }

    /**
     * Note that a source answered.
     *
     * Any answer clears the record entirely, so a service that recovers is trusted again at once
     * rather than serving out the rest of a backoff it no longer deserves.
     *
     * @param string $source
     * @return void
     */
    public static function record_success(string $source): void {
        global $DB;

        $record = $DB->get_record(rate_limiter::TABLE, ['source' => $source]);
        if (!$record || ((int) $record->failures === 0 && (int) $record->skipuntil === 0)) {
            // Nothing to clear, and this runs after every successful request, so skip the write.
            return;
        }

        $DB->update_record(rate_limiter::TABLE, (object) [
            'id' => $record->id,
            'failures' => 0,
            'skipuntil' => 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * The row for a source, created if this is the first time it has been seen.
     *
     * @param string $source
     * @return \stdClass
     */
    protected static function record_for(string $source): \stdClass {
        global $DB;

        $record = $DB->get_record(rate_limiter::TABLE, ['source' => $source]);
        if ($record) {
            return $record;
        }

        $record = (object) [
            'source' => $source,
            'nextallowedms' => 0,
            'failures' => 0,
            'skipuntil' => 0,
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record(rate_limiter::TABLE, $record);

        return $record;
    }

    /**
     * Clear every stand-down. Used by tests, and worth having for an administrator.
     *
     * @return void
     */
    public static function reset(): void {
        global $DB;

        $DB->set_field(rate_limiter::TABLE, 'failures', 0, []);
        $DB->set_field(rate_limiter::TABLE, 'skipuntil', 0, []);
    }
}

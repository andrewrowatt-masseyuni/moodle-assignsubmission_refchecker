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

use assignsubmission_refchecker\local\exception\rate_limited_exception;
use core\lock\lock_config;

/**
 * Paces requests to each external database.
 *
 * The services this plugin searches have very different appetites. CrossRef and OpenAlex are happy
 * to be called quickly by anyone in their polite pools; arXiv asks for roughly one request every
 * three seconds; DBLP starts returning 500s under load; and unauthenticated Semantic Scholar is a
 * shared pool that rate limits within seconds. Learning each limit by tripping it is how an
 * institution's address ends up blocked, so requests are paced deliberately instead.
 *
 * Callers reserve a slot rather than holding a lock while they wait. The lock is held only long
 * enough to claim the next free moment and write the one after it back, so several cron workers
 * take consecutive slots instead of queueing behind each other.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limiter {
    /** @var string The table holding each source's next free slot. */
    public const TABLE = 'assignsubmission_refchecker_rate';

    /**
     * Waits at or beyond this are handed back to the task API instead of slept through.
     *
     * A cron worker asleep for a minute is a worker doing nothing. Below the threshold the wait is
     * shorter than the cost of rescheduling, so sleeping is the cheaper option.
     *
     * @var int
     */
    public const MAX_INLINE_WAIT = 5;

    /** @var int How long to wait for the reservation lock before giving up, in seconds. */
    protected const LOCK_TIMEOUT = 10;

    /** @var array<string, int> Fallback intervals in milliseconds, used when unconfigured. */
    protected const DEFAULT_INTERVALS = [
        'crossref' => 100,
        'openalex' => 100,
        'dblp' => 1000,
        'arxiv' => 3000,
        'semanticscholar' => 3000,
    ];

    /**
     * Wait until this source may be contacted again.
     *
     * @param string $source The source name, e.g. "arxiv".
     * @param int|null $intervalms Override the configured interval, in milliseconds.
     * @return int How long was waited, in seconds.
     * @throws rate_limited_exception When the wait is long enough to be worth rescheduling.
     */
    public static function throttle(string $source, ?int $intervalms = null): int {
        $interval = $intervalms ?? self::interval_for($source);
        if ($interval <= 0) {
            return 0;
        }

        $slotms = self::reserve($source, $interval);
        $waitms = $slotms - self::now_ms();

        if ($waitms <= 0) {
            return 0;
        }

        $waitseconds = (int) ceil($waitms / 1000);

        if ($waitseconds >= self::MAX_INLINE_WAIT) {
            // Hand the wait back to the task API, which will re-run this work later. The slot has
            // already been reserved and simply goes unused, which costs nothing but a small gap.
            throw new rate_limited_exception($waitseconds, "Pacing requests to {$source}");
        }

        usleep($waitms * 1000);

        return $waitseconds;
    }

    /**
     * Claim the next free request slot for a source.
     *
     * @param string $source
     * @param int $intervalms The minimum gap between requests, in milliseconds.
     * @return int The unix time in milliseconds at which the caller may proceed.
     */
    protected static function reserve(string $source, int $intervalms): int {
        global $DB;

        $lock = self::get_lock($source);
        $slotms = self::now_ms();

        try {
            $record = $DB->get_record(self::TABLE, ['source' => $source]);

            // Never schedule into the past: a long idle period should not bank up credit.
            $slotms = max($slotms, (int) ($record->nextallowedms ?? 0));

            $next = (object) [
                'source' => $source,
                'nextallowedms' => $slotms + $intervalms,
                'timemodified' => time(),
            ];

            if ($record) {
                $next->id = $record->id;
                $DB->update_record(self::TABLE, $next);
            } else {
                $DB->insert_record(self::TABLE, $next);
            }
        } finally {
            if ($lock) {
                $lock->release();
            }
        }

        return $slotms;
    }

    /**
     * The current unix time in milliseconds.
     *
     * @return int
     */
    protected static function now_ms(): int {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Acquire the short lock guarding one source's reservation.
     *
     * A failure to acquire is not fatal. Pacing is a courtesy rather than a correctness
     * requirement, and refusing to search because a lock was busy would be a worse outcome than
     * two workers briefly sharing a slot.
     *
     * @param string $source
     * @return \core\lock\lock|false
     */
    protected static function get_lock(string $source) {
        try {
            $factory = lock_config::get_lock_factory('assignsubmission_refchecker_rate');

            return $factory->get_lock($source, self::LOCK_TIMEOUT);
        } catch (\Throwable $e) {
            debugging(
                'assignsubmission_refchecker could not lock the rate limiter: ' . $e->getMessage(),
                DEBUG_DEVELOPER,
            );

            return false;
        }
    }

    /**
     * The configured minimum gap between requests to a source, in milliseconds.
     *
     * @param string $source
     * @return int
     */
    public static function interval_for(string $source): int {
        $configured = get_config('assignsubmission_refchecker', 'rateinterval_' . $source);

        if ($configured !== false && $configured !== '' && (int) $configured >= 0) {
            return (int) $configured;
        }

        return self::DEFAULT_INTERVALS[$source] ?? 1000;
    }

    /**
     * The built in interval for a source, for the settings defaults.
     *
     * @param string $source
     * @return int
     */
    public static function default_interval(string $source): int {
        return self::DEFAULT_INTERVALS[$source] ?? 1000;
    }

    /**
     * Forget every reservation. Used by tests, and after a configuration change.
     *
     * @return void
     */
    public static function reset(): void {
        global $DB;

        $DB->delete_records(self::TABLE);
    }
}

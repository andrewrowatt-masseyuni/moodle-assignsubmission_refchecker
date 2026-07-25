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

namespace assignsubmission_refchecker;

use assignsubmission_refchecker\local\exception\rate_limited_exception;
use assignsubmission_refchecker\local\rate_limiter;

/**
 * Tests for pacing requests to the external databases.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\rate_limiter
 */
final class rate_limiter_test extends \advanced_testcase {
    /**
     * Start each test with no reservations outstanding.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        rate_limiter::reset();
    }

    /**
     * The first request to an idle source goes straight through.
     */
    public function test_first_request_is_not_delayed(): void {
        $started = microtime(true);

        $this->assertSame(0, rate_limiter::throttle('arxiv', 3000));

        $this->assertLessThan(1, microtime(true) - $started);
    }

    /**
     * A second request to the same source is pushed past the interval.
     */
    public function test_second_request_is_deferred(): void {
        global $DB;

        rate_limiter::throttle('dblp', 1000);

        $before = (int) $DB->get_field(rate_limiter::TABLE, 'nextallowedms', ['source' => 'dblp']);
        $this->assertGreaterThan(0, $before);

        // A long interval so the second call reschedules rather than sleeping through the test.
        try {
            rate_limiter::throttle('dblp', 60000);
            $this->fail('Expected the second request to be deferred.');
        } catch (rate_limited_exception $e) {
            $this->assertGreaterThan(0, $e->get_retry_after());
        }
    }

    /**
     * A long wait is handed back to the task API rather than slept through.
     *
     * A cron worker asleep for a minute is a worker doing nothing, so past a threshold the wait
     * becomes the task API's problem.
     */
    public function test_a_long_wait_reschedules(): void {
        rate_limiter::throttle('arxiv', 120000);

        try {
            rate_limiter::throttle('arxiv', 120000);
            $this->fail('Expected a rate_limited_exception.');
        } catch (rate_limited_exception $e) {
            $this->assertGreaterThanOrEqual(rate_limiter::MAX_INLINE_WAIT, $e->get_retry_after());
            $this->assertLessThanOrEqual(120, $e->get_retry_after());
        }
    }

    /**
     * Sources are paced independently of one another.
     */
    public function test_sources_do_not_block_each_other(): void {
        rate_limiter::throttle('arxiv', 60000);

        // A different source is unaffected by arXiv's reservation.
        $this->assertSame(0, rate_limiter::throttle('crossref', 100));
    }

    /**
     * Consecutive callers take consecutive slots rather than the same one.
     *
     * This is what makes the reservation safe across several cron workers: the lock is released
     * before anybody waits, so the slots queue up instead of the workers doing so.
     */
    public function test_callers_reserve_consecutive_slots(): void {
        global $DB;

        $interval = 60000;

        for ($i = 1; $i <= 3; $i++) {
            try {
                rate_limiter::throttle('dblp', $interval);
            } catch (rate_limited_exception $e) {
                // Expected from the second call onwards.
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $nextallowed = (int) $DB->get_field(rate_limiter::TABLE, 'nextallowedms', ['source' => 'dblp']);
        $elapsed = $nextallowed - (int) round(microtime(true) * 1000);

        // Three reservations at a minute each should have booked out roughly three minutes.
        $this->assertGreaterThan(2 * $interval, $elapsed);
    }

    /**
     * A zero interval disables pacing for a source entirely.
     */
    public function test_zero_interval_disables_pacing(): void {
        global $DB;

        $this->assertSame(0, rate_limiter::throttle('crossref', 0));
        $this->assertSame(0, rate_limiter::throttle('crossref', 0));

        $this->assertFalse($DB->record_exists(rate_limiter::TABLE, ['source' => 'crossref']));
    }

    /**
     * Idle time does not bank up credit.
     *
     * A source left alone for an hour gets one immediate request, not an hour's worth.
     */
    public function test_idle_time_does_not_accumulate_credit(): void {
        global $DB;

        $DB->insert_record(rate_limiter::TABLE, (object) [
            'source' => 'arxiv',
            'nextallowedms' => (int) round(microtime(true) * 1000) - (3600 * 1000),
            'timemodified' => time() - 3600,
        ]);

        $this->assertSame(0, rate_limiter::throttle('arxiv', 3000));

        // The next slot is measured from now, not from the stale timestamp.
        $nextallowed = (int) $DB->get_field(rate_limiter::TABLE, 'nextallowedms', ['source' => 'arxiv']);
        $this->assertGreaterThan((int) round(microtime(true) * 1000), $nextallowed);
    }

    /**
     * The configured interval overrides the built in default.
     */
    public function test_configured_interval_wins(): void {
        $this->assertSame(3000, rate_limiter::interval_for('arxiv'));

        set_config('rateinterval_arxiv', 250, 'assignsubmission_refchecker');

        $this->assertSame(250, rate_limiter::interval_for('arxiv'));
    }

    /**
     * An unknown source still gets a sensible pace rather than none at all.
     */
    public function test_unknown_source_has_a_default(): void {
        $this->assertGreaterThan(0, rate_limiter::interval_for('somethingelse'));
    }
}

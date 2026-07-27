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

use assignsubmission_refchecker\local\circuit_breaker;
use assignsubmission_refchecker\local\rate_limiter;

/**
 * Tests for standing down a repeatedly failing database.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\circuit_breaker
 */
final class circuit_breaker_test extends \advanced_testcase {
    /**
     * Start each test with nothing stood down.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        rate_limiter::reset();
    }

    /**
     * A source nobody has complained about is available.
     */
    public function test_an_untouched_source_is_available(): void {
        $this->assertFalse(circuit_breaker::is_open('dblp'));
        $this->assertSame(0, circuit_breaker::remaining('dblp'));
    }

    /**
     * A single failure does not stand a source down.
     *
     * Services hiccup. Standing one down on one blip would lose answers it could have given.
     */
    public function test_one_failure_is_tolerated(): void {
        circuit_breaker::record_failure('dblp');

        $this->assertFalse(circuit_breaker::is_open('dblp'));
    }

    /**
     * Repeated failures stand the source down.
     */
    public function test_repeated_failures_stand_a_source_down(): void {
        circuit_breaker::record_failure('dblp');
        circuit_breaker::record_failure('dblp');

        $this->assertTrue(circuit_breaker::is_open('dblp'));
        $this->assertGreaterThan(0, circuit_breaker::remaining('dblp'));
    }

    /**
     * A refusal stands the source down from the first one, without the usual tolerance.
     *
     * The tolerance is for failures we have to infer, such as a 500 or a timeout. A service that
     * turns a request away has told us something definite, and asking again straight away is how
     * one that was merely busy decides to start blocking the site properly.
     */
    public function test_a_minimum_stand_down_applies_from_the_first_failure(): void {
        circuit_breaker::record_failure('semanticscholar', 120);

        $this->assertTrue(circuit_breaker::is_open('semanticscholar'));
        $this->assertGreaterThan(60, circuit_breaker::remaining('semanticscholar'));
    }

    /**
     * The minimum is a floor, not a ceiling: repeated refusals still escalate.
     */
    public function test_repeated_refusals_still_escalate(): void {
        for ($i = 0; $i < 6; $i++) {
            circuit_breaker::record_failure('semanticscholar', 30);
        }

        $this->assertGreaterThan(30, circuit_breaker::remaining('semanticscholar'));
    }

    /**
     * Each further failure stands the source down for longer, up to a ceiling.
     */
    public function test_backoff_grows_and_is_capped(): void {
        circuit_breaker::record_failure('dblp');
        circuit_breaker::record_failure('dblp');
        $first = circuit_breaker::remaining('dblp');

        circuit_breaker::record_failure('dblp');
        $second = circuit_breaker::remaining('dblp');

        $this->assertGreaterThan($first, $second);

        for ($i = 0; $i < 12; $i++) {
            circuit_breaker::record_failure('dblp');
        }

        $this->assertLessThanOrEqual(circuit_breaker::MAX_BACKOFF, circuit_breaker::remaining('dblp'));
    }

    /**
     * Any answer clears the stand-down immediately.
     *
     * A service that has recovered should not serve out a penalty it no longer deserves.
     */
    public function test_a_success_clears_the_stand_down(): void {
        circuit_breaker::record_failure('dblp');
        circuit_breaker::record_failure('dblp');
        $this->assertTrue(circuit_breaker::is_open('dblp'));

        circuit_breaker::record_success('dblp');

        $this->assertFalse(circuit_breaker::is_open('dblp'));
        $this->assertSame(0, circuit_breaker::remaining('dblp'));
    }

    /**
     * The counter resets, so a later blip starts from tolerance again.
     */
    public function test_the_failure_count_resets(): void {
        circuit_breaker::record_failure('dblp');
        circuit_breaker::record_failure('dblp');
        circuit_breaker::record_success('dblp');

        // One failure after recovery should be tolerated, exactly as the first one was.
        circuit_breaker::record_failure('dblp');

        $this->assertFalse(circuit_breaker::is_open('dblp'));
    }

    /**
     * Standing one source down never affects another.
     */
    public function test_sources_are_independent(): void {
        circuit_breaker::record_failure('dblp');
        circuit_breaker::record_failure('dblp');

        $this->assertTrue(circuit_breaker::is_open('dblp'));
        $this->assertFalse(circuit_breaker::is_open('crossref'));
        $this->assertFalse(circuit_breaker::is_open('arxiv'));
    }

    /**
     * Recording a success for a healthy source does not churn the database.
     *
     * This runs after every successful request, so it must not write on the happy path.
     */
    public function test_success_on_a_healthy_source_writes_nothing(): void {
        global $DB;

        circuit_breaker::record_success('crossref');

        $this->assertFalse($DB->record_exists(rate_limiter::TABLE, ['source' => 'crossref']));
    }
}

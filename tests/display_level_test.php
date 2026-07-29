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

use assignsubmission_refchecker\local\display_level;

/**
 * Tests for the display level value object.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\display_level
 */
final class display_level_test extends \advanced_testcase {
    /**
     * The levels are ordered, which is what every `>=` comparison in the plugin relies on.
     */
    public function test_the_levels_are_ordered_from_least_to_most(): void {
        $this->assertLessThan(display_level::STATUS_ONLY, display_level::NONE);
        $this->assertLessThan(display_level::SUMMARY, display_level::STATUS_ONLY);
        $this->assertLessThan(display_level::FULL, display_level::SUMMARY);
    }

    /**
     * A known level is returned unchanged.
     *
     * @dataProvider known_level_provider
     * @param int $level
     */
    public function test_sanitise_keeps_a_known_level(int $level): void {
        $this->assertSame($level, display_level::sanitise($level));
    }

    /**
     * Data provider over every level.
     *
     * @return array[]
     */
    public static function known_level_provider(): array {
        return [
            'no information' => [display_level::NONE],
            'status only' => [display_level::STATUS_ONLY],
            'summary' => [display_level::SUMMARY],
            'full' => [display_level::FULL],
        ];
    }

    /**
     * Anything unrecognised becomes the most restrictive level rather than the most generous.
     *
     * An unset per-assignment setting arrives here as 0 through a cast of false, which is NONE, so
     * a misconfigured assignment discloses nothing.
     *
     * @dataProvider unknown_level_provider
     * @param int $level
     */
    public function test_sanitise_falls_back_to_no_information(int $level): void {
        $this->assertSame(display_level::NONE, display_level::sanitise($level));
    }

    /**
     * Data provider of values that are not levels.
     *
     * @return array[]
     */
    public static function unknown_level_provider(): array {
        return [
            'negative' => [-1],
            'past the end' => [display_level::FULL + 1],
            'far past the end' => [99],
        ];
    }

    /**
     * Only the lowest level suppresses everything.
     */
    public function test_shows_nothing_is_true_only_at_no_information(): void {
        $this->assertTrue(display_level::shows_nothing(display_level::NONE));
        $this->assertFalse(display_level::shows_nothing(display_level::STATUS_ONLY));
        $this->assertFalse(display_level::shows_nothing(display_level::SUMMARY));
        $this->assertFalse(display_level::shows_nothing(display_level::FULL));
    }

    /**
     * The settings menu offers all four levels, in order, and labels them.
     */
    public function test_the_menu_offers_every_level_in_order(): void {
        $menu = display_level::menu();

        $this->assertSame([
            display_level::NONE,
            display_level::STATUS_ONLY,
            display_level::SUMMARY,
            display_level::FULL,
        ], array_keys($menu));
        $this->assertSame('No information', $menu[display_level::NONE]);
        $this->assertSame('Status only', $menu[display_level::STATUS_ONLY]);
    }

    /**
     * There is nothing to promise a student who is being told nothing.
     */
    public function test_no_information_has_no_student_expectation(): void {
        $this->assertSame('', display_level::student_expectation(display_level::NONE));
    }

    /**
     * Every other level describes itself to the student.
     *
     * @dataProvider expectation_provider
     * @param int $level
     * @param string $fragment Wording that must appear in the sentence.
     */
    public function test_student_expectation_describes_the_level(int $level, string $fragment): void {
        $this->assertStringContainsString($fragment, display_level::student_expectation($level));
    }

    /**
     * Data provider for the expectation sentences.
     *
     * @return array[]
     */
    public static function expectation_provider(): array {
        return [
            'status only' => [display_level::STATUS_ONLY, 'how many references were found'],
            'summary' => [display_level::SUMMARY, 'a summary of how many'],
            'full' => [display_level::FULL, 'each of your references'],
        ];
    }
}

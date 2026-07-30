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

use assignsubmission_refchecker\local\issue;

/**
 * Tests for wording a stored issue at display time.
 *
 * The point of storing a code rather than a sentence is that the sentence can be produced later, in
 * the reader's language. These tests are what keeps every code able to produce one.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\issue
 */
final class issue_test extends \advanced_testcase {
    /**
     * Every code this plugin can record has wording to render it with.
     */
    public function test_every_code_can_be_worded(): void {
        // Enough placeholders to satisfy any of them; the unused ones are simply ignored.
        $a = ['names' => 'Rowatt', 'cited' => 2010, 'found' => 2008];

        foreach (issue::all() as $code) {
            $sentence = issue::format(issue::make($code, $a));

            $this->assertNotSame('', $sentence, $code . ' has no wording');
            $this->assertStringNotContainsString('{$a', $sentence, $code . ' has an unfilled placeholder');
            $this->assertStringNotContainsString('[[', $sentence, $code . ' has no language string');
        }
    }

    /**
     * The placeholder values reach the sentence.
     */
    public function test_placeholders_are_interpolated(): void {
        $this->assertStringContainsString(
            'Rowatt',
            issue::format(issue::make(issue::EXTRAAUTHORS, ['names' => 'Rowatt'])),
        );

        $year = issue::format(issue::make(issue::YEAR, ['cited' => 2010, 'found' => 2008]));
        $this->assertStringContainsString('2010', $year);
        $this->assertStringContainsString('2008', $year);
    }

    /**
     * A code this version does not know is dropped rather than shown to somebody.
     */
    public function test_an_unrecognised_code_renders_nothing(): void {
        $this->assertSame('', issue::format(['code' => 'somethingelse']));
        $this->assertSame('', issue::format(['a' => ['names' => 'Rowatt']]));
        $this->assertSame('', issue::format(42));
    }

    /**
     * A finished sentence stored by an older version of the plugin still reads.
     */
    public function test_a_bare_string_is_passed_through(): void {
        $this->assertSame(
            'The authors do not match the work that was found.',
            issue::format('The authors do not match the work that was found.'),
        );
    }

    /**
     * Formatting a list keeps the order and drops only what cannot be rendered.
     */
    public function test_format_all_keeps_order_and_drops_the_unrenderable(): void {
        $sentences = issue::format_all([
            issue::make(issue::EXTRAAUTHORS, ['names' => 'Rowatt']),
            ['code' => 'somethingelse'],
            issue::make(issue::DOI),
        ]);

        $this->assertCount(2, $sentences);
        $this->assertStringContainsString('Rowatt', $sentences[0]);
        $this->assertSame('The DOI points to a different work.', $sentences[1]);
    }

    /**
     * A list can be asked whether it holds a particular finding.
     */
    public function test_has_finds_a_code(): void {
        $issues = [issue::make(issue::EXTRAAUTHORS, ['names' => 'Rowatt']), issue::make(issue::DOI)];

        $this->assertTrue(issue::has($issues, issue::EXTRAAUTHORS));
        $this->assertTrue(issue::has($issues, issue::DOI));
        $this->assertFalse(issue::has($issues, issue::YEAR));
        $this->assertFalse(issue::has([], issue::DOI));
    }

    /**
     * Only what was recorded comes back out of the column.
     */
    public function test_make_omits_an_empty_placeholder_set(): void {
        $this->assertSame(['code' => issue::DOI], issue::make(issue::DOI));
        $this->assertSame(
            ['code' => issue::EXTRAAUTHORS, 'a' => ['names' => 'Rowatt']],
            issue::make(issue::EXTRAAUTHORS, ['names' => 'Rowatt']),
        );
    }
}

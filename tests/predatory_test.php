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

use assignsubmission_refchecker\local\predatory;

/**
 * Tests for the predatory publisher lookup.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\predatory
 */
final class predatory_test extends \advanced_testcase {
    /**
     * Reset the loaded list between tests.
     */
    protected function tearDown(): void {
        predatory::reset_caches();
        parent::tearDown();
    }

    /**
     * A listed publisher is matched even with extra words around it.
     */
    public function test_matches_a_listed_publisher(): void {
        $this->assertTrue(predatory::is_predatory('OMICS Publishing Group Ltd'));
    }

    /**
     * An ordinary journal is not flagged.
     */
    public function test_does_not_flag_an_unlisted_journal(): void {
        $this->assertFalse(predatory::is_predatory('Qualitative Research in Psychology'));
    }

    /**
     * Empty and missing names are not flagged.
     *
     * @dataProvider empty_name_provider
     * @param string|null $name
     */
    public function test_empty_names_are_not_flagged(?string $name): void {
        $this->assertFalse(predatory::is_predatory($name));
    }

    /**
     * Data provider of names that carry no information.
     *
     * @return array[]
     */
    public static function empty_name_provider(): array {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'punctuation only' => ['---'],
        ];
    }
}

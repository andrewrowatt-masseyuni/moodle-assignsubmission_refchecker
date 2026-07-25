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
 * When an assignment's references are checked.
 *
 * Checking on submit costs one pass per attempt. Checking on every save gives students formative
 * feedback while drafting but multiplies external database usage, so it is not the default.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_timing {
    /** @var string Check once, when the student submits for grading. */
    public const SUBMIT = 'submit';

    /** @var string Check on every save, including drafts. */
    public const SAVE = 'save';

    /**
     * Clamp an arbitrary stored value to a timing we know about.
     *
     * @param string $timing
     * @return string
     */
    public static function sanitise(string $timing): string {
        return $timing === self::SAVE ? self::SAVE : self::SUBMIT;
    }

    /**
     * Options for the settings select menus.
     *
     * @return array<string, string>
     */
    public static function menu(): array {
        return [
            self::SUBMIT => get_string('checktiming_submit', 'assignsubmission_refchecker'),
            self::SAVE => get_string('checktiming_save', 'assignsubmission_refchecker'),
        ];
    }
}

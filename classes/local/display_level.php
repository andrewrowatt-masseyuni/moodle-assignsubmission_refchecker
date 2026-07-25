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

use context;

/**
 * How much of a reference check result the current user may see.
 *
 * Students are governed by the per-assignment setting. Anyone holding
 * assignsubmission/refchecker:viewfullreport always gets the full report, which is what makes
 * "teachers always see everything" true regardless of how the assignment is configured.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class display_level {
    /** @var int The status line only. */
    public const STATUS_ONLY = 0;

    /** @var int The status line plus aggregate counts. No individual references. */
    public const SUMMARY = 1;

    /** @var int Everything, including the result for each individual reference. */
    public const FULL = 2;

    /** @var string Capability that unconditionally grants the full report. */
    public const CAP_VIEWFULLREPORT = 'assignsubmission/refchecker:viewfullreport';

    /**
     * Resolve the level that applies to a user in a context.
     *
     * @param context $context The assignment's module context.
     * @param int $configured The assignment's studentdisplay setting.
     * @param int|null $userid Defaults to the current user.
     * @return int One of the class constants.
     */
    public static function effective_level_for(context $context, int $configured, ?int $userid = null): int {
        if (has_capability(self::CAP_VIEWFULLREPORT, $context, $userid)) {
            return self::FULL;
        }
        return self::sanitise($configured);
    }

    /**
     * Clamp an arbitrary stored value to a level we know about.
     *
     * @param int $level
     * @return int
     */
    public static function sanitise(int $level): int {
        return in_array($level, [self::STATUS_ONLY, self::SUMMARY, self::FULL], true)
            ? $level
            : self::STATUS_ONLY;
    }

    /**
     * Options for the settings select menus.
     *
     * @return array<int, string>
     */
    public static function menu(): array {
        return [
            self::STATUS_ONLY => get_string('studentdisplay_status', 'assignsubmission_refchecker'),
            self::SUMMARY => get_string('studentdisplay_summary', 'assignsubmission_refchecker'),
            self::FULL => get_string('studentdisplay_full', 'assignsubmission_refchecker'),
        ];
    }

    /**
     * The label for a single level.
     *
     * @param int $level
     * @return string
     */
    public static function label(int $level): string {
        return self::menu()[self::sanitise($level)];
    }

    /**
     * The sentence added to the student information text describing what this user will see.
     *
     * @param int $level
     * @return string
     */
    public static function student_expectation(int $level): string {
        $key = match (self::sanitise($level)) {
            self::FULL => 'viewheader_level_full',
            self::SUMMARY => 'viewheader_level_summary',
            default => 'viewheader_level_status',
        };
        return get_string($key, 'assignsubmission_refchecker');
    }
}

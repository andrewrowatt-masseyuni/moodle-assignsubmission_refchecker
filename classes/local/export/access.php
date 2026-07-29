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

namespace assignsubmission_refchecker\local\export;

use assign;
use assign_submission_plugin;
use assignsubmission_refchecker\local\display_level;
use required_capability_exception;
use stdClass;

/**
 * Who may download the report for a submission.
 *
 * Two independent questions, and both have to be asked. "May this person see this submission at
 * all", which is the assignment's business and is what stops one student reading another's report
 * by incrementing an id; and "may this person see individual references", which is the display
 * level and is what stops a student whose assignment is set to summary reading the full detail.
 *
 * The gate lives here rather than inline in export.php so that it can be unit tested. A download
 * endpoint is otherwise only reachable through Behat, which is the wrong place to be checking
 * whether student B can read student A's results.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * Whether the current user may download the report for a submission.
     *
     * Deliberately has no $userid parameter. assign::can_view_submission() answers only for the
     * user in session, so accepting one would let a caller ask about one person's display level
     * while the visibility half of the question was still being answered about somebody else.
     * Callers wanting to ask about another user should switch user first, as the tests do.
     *
     * @param assign $assign
     * @param assign_submission_plugin $plugin The refchecker plugin instance for this assignment.
     * @param stdClass $submission An assign_submission record.
     * @return bool
     */
    public static function can_export(
        assign $assign,
        assign_submission_plugin $plugin,
        stdClass $submission,
    ): bool {
        if (!self::can_view_submission($assign, $submission)) {
            return false;
        }

        return self::level($assign, $plugin) >= display_level::FULL;
    }

    /**
     * Throw unless a user may download the report for a submission.
     *
     * @param assign $assign
     * @param assign_submission_plugin $plugin The refchecker plugin instance for this assignment.
     * @param stdClass $submission An assign_submission record.
     * @throws required_capability_exception
     */
    public static function require_export(
        assign $assign,
        assign_submission_plugin $plugin,
        stdClass $submission,
    ): void {
        // Throws in its own right, and gives the more accurate message of the two.
        self::require_view_submission($assign, $submission);

        if (self::level($assign, $plugin) < display_level::FULL) {
            throw new required_capability_exception(
                $assign->get_context(),
                display_level::CAP_VIEWFULLREPORT,
                'nopermission',
                '',
            );
        }
    }

    /**
     * The viewer's effective display level for this assignment.
     *
     * Gated on the level rather than on the capability, so that a student in an assignment
     * configured to show the full report can download their own.
     *
     * @param assign $assign
     * @param assign_submission_plugin $plugin
     * @return int One of the display_level constants.
     */
    protected static function level(assign $assign, assign_submission_plugin $plugin): int {
        // An assignment with no stored value gets false from get_config(), which casts to NONE. The
        // most restrictive level is the right default for a download endpoint.
        return display_level::effective_level_for(
            $assign->get_context(),
            (int) $plugin->get_config('studentdisplay'),
        );
    }

    /**
     * Whether the current user may see the submission at all.
     *
     * The same branch assign::view_plugin_content() takes, so that the download is reachable by
     * exactly the people the on screen report is reachable by.
     *
     * @param assign $assign
     * @param stdClass $submission
     * @return bool
     */
    protected static function can_view_submission(assign $assign, stdClass $submission): bool {
        if (empty($submission->userid)) {
            return $assign->can_view_group_submission((int) $submission->groupid);
        }

        return $assign->can_view_submission((int) $submission->userid);
    }

    /**
     * Throw unless the current user may see the submission at all.
     *
     * @param assign $assign
     * @param stdClass $submission
     * @throws required_capability_exception
     */
    protected static function require_view_submission(assign $assign, stdClass $submission): void {
        if (empty($submission->userid)) {
            // A team submission by a student in no group carries groupid 0, and the check below
            // will refuse it. That matches assign::view_plugin_content(), which the inline report
            // goes through, so the download is reachable exactly where the report is; diverging
            // here would be the surprising behaviour.
            $assign->require_view_group_submission((int) $submission->groupid);

            return;
        }

        $assign->require_view_submission((int) $submission->userid);
    }
}

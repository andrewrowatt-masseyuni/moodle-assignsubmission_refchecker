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
use stdClass;

/**
 * What a downloaded report is called, and who it says it is about.
 *
 * Shared by the filename and the PDF cover, because they have to agree: it is no use suppressing a
 * student's name on the cover page if the file it arrived in is named after them.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class naming {
    /**
     * The download filename, without an extension.
     *
     * Callers must know which of the two writers they are feeding: \core\dataformat appends the
     * extension itself, TCPDF's Output() does not.
     *
     * @param assign $assign
     * @param stdClass $submission An assign_submission record.
     * @return string
     */
    public static function filename(assign $assign, stdClass $submission): string {
        $name = format_string(
            $assign->get_instance()->name,
            true,
            ['context' => $assign->get_context()],
        );

        return clean_filename($name . ' - ' . self::subject($assign, $submission) . ' - ' . userdate(time(), '%Y%m%d'));
    }

    /**
     * Who the report is about: a student, a group, or an anonymous participant number.
     *
     * @param assign $assign
     * @param stdClass $submission An assign_submission record.
     * @return string
     */
    public static function subject(assign $assign, stdClass $submission): string {
        global $DB;

        if (empty($submission->userid)) {
            // A team submission. groupid is legitimately 0 for a student in no group.
            return $submission->groupid
                ? (string) groups_get_group_name((int) $submission->groupid)
                : get_string('defaultteam', 'assign');
        }

        if ($assign->is_blind_marking()) {
            // A marker who is deliberately being kept from the student's identity must not be
            // handed it by the download. Same substitution assign::download_rewrite_pluginfile_urls()
            // makes for submission files.
            return get_string('participant', 'assign')
                . ' ' . $assign->get_uniqueid_for_user((int) $submission->userid);
        }

        return fullname($DB->get_record('user', ['id' => $submission->userid], '*', MUST_EXIST));
    }

    /**
     * The label for whoever the report is about, matching what subject() returned.
     *
     * @param stdClass $submission An assign_submission record.
     * @return string
     */
    public static function subject_label(stdClass $submission): string {
        return empty($submission->userid)
            ? get_string('export_pdf_group', 'assignsubmission_refchecker')
            : get_string('export_pdf_student', 'assignsubmission_refchecker');
    }
}

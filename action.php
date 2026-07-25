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

/**
 * Re-run the reference check for a submission.
 *
 * The operational escape hatch: a submission whose check failed, or which was checked before a
 * configuration problem was fixed, can be put back through the pipeline without the student
 * having to resubmit.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\task\extract_references;
use core\task\manager;

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$submissionid = required_param('id', PARAM_INT);

require_sesskey();

$submission = $DB->get_record('assign_submission', ['id' => $submissionid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $submission->assignment, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);

// Re-running spends the site's external API quota, so it is limited to the people who can grade
// the assignment rather than to anyone who can see the submission.
require_capability('mod/assign:grade', $context);

$assign = new assign($context, $cm, $course);
$returnurl = new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']);

$plugin = $assign->get_submission_plugin_by_type('refchecker');
if (!$plugin || !$plugin->is_enabled() || !$plugin->is_visible()) {
    redirect(
        $returnurl,
        get_string('notconfigured', 'assignsubmission_refchecker'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$job = job_manager::reset_for_submission($submission);

$task = new extract_references();
$task->set_custom_data([
    'submissionid' => (int) $submission->id,
    'generation' => (int) $job->generation,
]);
$task->set_component('assignsubmission_refchecker');
manager::queue_adhoc_task($task, true);

redirect(
    $returnurl,
    get_string('rerunqueued', 'assignsubmission_refchecker'),
    null,
    \core\output\notification::NOTIFY_SUCCESS,
);

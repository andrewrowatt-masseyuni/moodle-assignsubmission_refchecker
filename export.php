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
 * Download the reference check report for one submission.
 *
 * The on screen report is only ever on screen: a marker who wants to keep the result alongside
 * their marking notes, or a student who wants to work through their references offline, has
 * nowhere to put it. PDF is the readable record, CSV and Excel the workable one.
 *
 * Reachable by exactly the people the full report itself is reachable by, which is not the same
 * as the people who hold the view capability: an assignment can be configured to show students
 * their own full report, and those students may download it.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The spreadsheet writers stream straight to the browser and \core\dataformat refuses to run with
// an output buffer open, so buffering has to be off before config.php sets it up. From here on any
// stray byte, including a debugging notice, lands in the middle of the download and corrupts it.
define('NO_OUTPUT_BUFFERING', true);

use assignsubmission_refchecker\local\display_level;
use assignsubmission_refchecker\local\export\access;
use assignsubmission_refchecker\local\export\naming;
use assignsubmission_refchecker\local\export\pdf_report;
use assignsubmission_refchecker\local\export\reference_rows;
use assignsubmission_refchecker\local\job_manager;

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$submissionid = required_param('id', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);

require_sesskey();

// The formats offered, which is deliberately not every dataformat writer the site has installed.
if (!in_array($format, ['pdf', 'excel', 'csv'], true)) {
    throw new moodle_exception('export_unknownformat', 'assignsubmission_refchecker');
}

$submission = $DB->get_record('assign_submission', ['id' => $submissionid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('assign', $submission->assignment, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);

// The PDF is built through render_from_template(), which needs a page with a url and a context.
// Without them the renderer emits a debugging notice, and with output buffering off that notice
// would be written into the download rather than anywhere it could be seen.
$PAGE->set_url('/mod/assign/submission/refchecker/export.php', ['id' => $submissionid, 'format' => $format]);
$PAGE->set_context($context);

$assign = new assign($context, $cm, $course);

$plugin = $assign->get_submission_plugin_by_type('refchecker');
if (!$plugin || !$plugin->is_enabled() || !$plugin->is_visible()) {
    throw new moodle_exception('notconfigured', 'assignsubmission_refchecker');
}

// May this person see this submission, and may they see individual references. Throws on either.
access::require_export($assign, $plugin, $submission);

$isteacher = has_capability(display_level::CAP_VIEWFULLREPORT, $context);

$job = job_manager::get_job($submission);
if (!$job) {
    throw new moodle_exception('export_nojob', 'assignsubmission_refchecker');
}

// The whole reference list, whatever the reader has filtered the report down to on screen: a file
// somebody files away should be the complete record.
$references = job_manager::get_references((int) $job->id);
$filename = naming::filename($assign, $submission);

// An admin can disable an individual dataformat writer, in which case the button must not work.
// Checked here rather than beside the download because everything that can throw has to happen
// before the first byte is written.
if ($format !== 'pdf') {
    $writers = \core_plugin_manager::instance()->get_plugins_of_type('dataformat');
    if (!isset($writers[$format]) || !$writers[$format]->is_enabled()) {
        throw new moodle_exception('export_unknownformat', 'assignsubmission_refchecker');
    }
}

// Nothing below this line may throw, and nothing above it may emit a byte.
if ($format === 'pdf') {
    // The dataformat API does all three of these for the spreadsheets. The PDF path has to do them
    // itself, or a long document build holds the session lock and blocks the user's other tabs.
    \core_php_time_limit::raise();
    raise_memory_limit(MEMORY_EXTRA);
    \core\session\manager::write_close();

    (new pdf_report($assign, $submission, $job, $references, $isteacher))->download($filename);

    // TCPDF's Output() returns rather than exiting.
    die;
}

$rows = new reference_rows($references, $isteacher);

// No callback: these are plain text values from external databases, and the csv and excel writers
// do not support HTML. The writer appends the extension and escapes spreadsheet formulas itself.
\core\dataformat::download_data($filename, $format, $rows->columns(), $rows->rows());

// Like TCPDF above, the writer returns rather than exiting.
die;

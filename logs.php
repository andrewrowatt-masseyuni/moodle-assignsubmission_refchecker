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
 * Read the reference checker's activity log.
 *
 * Site administrators only: the log records the text of students' references, and is written for
 * whoever is diagnosing a problem with the checker rather than for anyone teaching a course.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_refchecker\local\debug_log;

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$view = optional_param('view', '', PARAM_FILE);
$download = optional_param('download', '', PARAM_FILE);
$deleteall = optional_param('deleteall', 0, PARAM_BOOL);

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/mod/assign/submission/refchecker/logs.php');

// Serving a file writes to the output stream, so it has to be settled before any page rendering.
if ($download !== '') {
    require_sesskey();
    $path = debug_log::file_path($download);
    if ($path === null) {
        throw new moodle_exception('filenotfound', 'error', $url);
    }
    // Plain text rather than the .log extension's unknown type, and forced down rather than shown.
    send_file($path, $download, 0, 0, false, true, 'text/plain');
    die;
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('debuglogs', 'assignsubmission_refchecker'));
$PAGE->set_heading(get_string('debuglogs', 'assignsubmission_refchecker'));

if ($deleteall) {
    require_sesskey();
    $deleted = debug_log::purge_all();
    redirect(
        $url,
        get_string('debuglogdeleted', 'assignsubmission_refchecker', $deleted),
        null,
        \core\output\notification::NOTIFY_SUCCESS,
    );
}

// Reading the page is a good moment to drop whatever has expired: on a site where logging was
// switched on, used and switched off again, nothing else would run until the next scheduled task.
debug_log::purge();

echo $OUTPUT->header();

if (!debug_log::enabled()) {
    echo $OUTPUT->notification(
        get_string('debuglogdisabled', 'assignsubmission_refchecker'),
        \core\output\notification::NOTIFY_INFO,
    );
}

$files = debug_log::files();

if ($view !== '') {
    $path = debug_log::file_path($view);
    if ($path === null) {
        throw new moodle_exception('filenotfound', 'error', $url);
    }

    echo $OUTPUT->heading($view, 3);
    echo html_writer::div(
        html_writer::link($url, get_string('back')),
        'mb-2',
    );
    // Escaped and wrapped: every value in here originated outside the site, and some of it is
    // student text, so none of it may reach the browser as markup.
    echo html_writer::tag(
        'pre',
        s(file_get_contents($path)),
        ['class' => 'p-3 bg-light border rounded', 'style' => 'max-height: 40em; overflow: auto;'],
    );
    echo $OUTPUT->footer();
    die;
}

if (!$files) {
    echo $OUTPUT->notification(
        get_string('debuglogempty', 'assignsubmission_refchecker'),
        \core\output\notification::NOTIFY_INFO,
    );
    echo $OUTPUT->footer();
    die;
}

$table = new html_table();
$table->head = [
    get_string('debuglogtime', 'assignsubmission_refchecker'),
    get_string('debuglogsize', 'assignsubmission_refchecker'),
    '',
];
$table->attributes['class'] = 'generaltable';

foreach ($files as $file) {
    $table->data[] = [
        userdate($file['hour'], get_string('strftimedatetimeshort', 'langconfig')),
        display_size($file['size']),
        html_writer::link(
            new moodle_url($url, ['view' => $file['name']]),
            get_string('debuglogview', 'assignsubmission_refchecker'),
        )
        . ' &nbsp; '
        . html_writer::link(
            new moodle_url($url, ['download' => $file['name'], 'sesskey' => sesskey()]),
            get_string('download'),
        ),
    ];
}

echo html_writer::table($table);

echo $OUTPUT->single_button(
    new moodle_url($url, ['deleteall' => 1, 'sesskey' => sesskey()]),
    get_string('debuglogdelete', 'assignsubmission_refchecker'),
    'post',
);

echo $OUTPUT->footer();

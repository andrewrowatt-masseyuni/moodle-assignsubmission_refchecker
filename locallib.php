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
 * Main class for Reference Checker submission plugin
 *
 * This plugin makes no submission of its own. It reads the files a student uploaded through the
 * File submissions plugin, extracts their reference list, and looks each reference up in public
 * academic databases in the background. What it contributes to the assignment UI is a status, and
 * optionally a summary or a full report.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_refchecker\local\check_timing;
use assignsubmission_refchecker\local\display_level;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\output\report;
use assignsubmission_refchecker\output\status_summary;

/**
 * Library class for Reference Checker submission plugin extending submission plugin base class.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_refchecker extends assign_submission_plugin {
    /**
     * Get the name of the plugin.
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'assignsubmission_refchecker');
    }

    /**
     * This plugin contributes nothing a student can submit.
     *
     * Returning false keeps it out of the "have you submitted anything?" checks and out of the
     * submission form, while still letting the renderer show its status for every submission.
     *
     * @return bool
     */
    public function allow_submissions() {
        return false;
    }

    /**
     * The plugin stores no submission content of its own.
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        return true;
    }

    /**
     * Add the per-assignment settings.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {
        $mform->addElement(
            'select',
            'assignsubmission_refchecker_studentdisplay',
            get_string('studentdisplay', 'assignsubmission_refchecker'),
            display_level::menu(),
        );
        $mform->addHelpButton(
            'assignsubmission_refchecker_studentdisplay',
            'studentdisplay',
            'assignsubmission_refchecker',
        );
        $mform->setDefault(
            'assignsubmission_refchecker_studentdisplay',
            $this->setting_default('studentdisplay', 'defaultstudentdisplay', display_level::STATUS_ONLY),
        );
        $mform->hideIf(
            'assignsubmission_refchecker_studentdisplay',
            'assignsubmission_refchecker_enabled',
            'notchecked',
        );

        $mform->addElement(
            'select',
            'assignsubmission_refchecker_checktiming',
            get_string('checktiming', 'assignsubmission_refchecker'),
            check_timing::menu(),
        );
        $mform->addHelpButton(
            'assignsubmission_refchecker_checktiming',
            'checktiming',
            'assignsubmission_refchecker',
        );
        $mform->setDefault(
            'assignsubmission_refchecker_checktiming',
            $this->setting_default('checktiming', 'defaultchecktiming', check_timing::SUBMIT),
        );
        $mform->hideIf(
            'assignsubmission_refchecker_checktiming',
            'assignsubmission_refchecker_enabled',
            'notchecked',
        );

        $mform->addElement(
            'text',
            'assignsubmission_refchecker_maxreferences',
            get_string('maxreferences', 'assignsubmission_refchecker'),
            ['size' => 5],
        );
        $mform->setType('assignsubmission_refchecker_maxreferences', PARAM_INT);
        $mform->addHelpButton(
            'assignsubmission_refchecker_maxreferences',
            'maxreferences',
            'assignsubmission_refchecker',
        );
        $mform->setDefault(
            'assignsubmission_refchecker_maxreferences',
            $this->setting_default('maxreferences', 'maxreferences', 200),
        );
        $mform->hideIf(
            'assignsubmission_refchecker_maxreferences',
            'assignsubmission_refchecker_enabled',
            'notchecked',
        );

        // Without File submissions there is nothing to read references out of. Say so on the
        // settings form rather than leaving the teacher to discover it after the due date.
        if (!$this->file_plugin_available()) {
            $mform->addElement(
                'static',
                'assignsubmission_refchecker_filewarning',
                '',
                $this->assignment->get_renderer()->notification(
                    get_string('nofileplugin', 'assignsubmission_refchecker'),
                    \core\output\notification::NOTIFY_WARNING,
                ),
            );
            $mform->hideIf(
                'assignsubmission_refchecker_filewarning',
                'assignsubmission_refchecker_enabled',
                'notchecked',
            );
        }
    }

    /**
     * Save the per-assignment settings.
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        $this->set_config('studentdisplay', display_level::sanitise(
            (int) ($data->assignsubmission_refchecker_studentdisplay ?? display_level::STATUS_ONLY),
        ));
        $this->set_config('checktiming', check_timing::sanitise(
            (string) ($data->assignsubmission_refchecker_checktiming ?? check_timing::SUBMIT),
        ));

        $maxreferences = (int) ($data->assignsubmission_refchecker_maxreferences ?? 0);
        if ($maxreferences < 1) {
            $maxreferences = (int) get_config('assignsubmission_refchecker', 'maxreferences') ?: 200;
        }
        $this->set_config('maxreferences', $maxreferences);

        return true;
    }

    /**
     * Explanatory text shown on the assignment page whenever this plugin is enabled.
     *
     * Called once per page load for every user, before any submission exists, so it must not look
     * at submissions.
     *
     * @return string
     */
    public function view_header() {
        global $OUTPUT;

        $context = $this->assignment->get_context();
        $isteacher = $this->viewer_is_teacher();

        $configured = (string) get_config('assignsubmission_refchecker', 'studentinformation');
        if (trim($configured) !== '') {
            $information = format_text($configured, FORMAT_HTML, ['context' => $context]);
        } else {
            // The default wording is a lang string, so it arrives as plain text with paragraph
            // breaks rather than HTML.
            $information = format_text(
                get_string('viewheader_default', 'assignsubmission_refchecker'),
                FORMAT_MARKDOWN,
                ['context' => $context],
            );
        }

        $privacynotice = trim((string) get_config('assignsubmission_refchecker', 'privacynotice'));
        $configuredlevel = display_level::sanitise((int) $this->get_config('studentdisplay'));
        $hasfilewarning = $isteacher && !$this->file_plugin_available();

        return $OUTPUT->render_from_template('assignsubmission_refchecker/view_header', [
            'information' => $information,
            // Students are told what they personally will see. Teachers get the equivalent
            // statement about their students instead.
            'expectation' => $isteacher ? '' : display_level::student_expectation($configuredlevel),
            'hasprivacynotice' => $privacynotice !== '',
            'privacynotice' => $privacynotice !== ''
                ? format_text($privacynotice, FORMAT_HTML, ['context' => $context])
                : '',
            'isteacher' => $isteacher,
            'studentlevel' => $isteacher
                ? get_string(
                    'viewheader_teacher_level',
                    'assignsubmission_refchecker',
                    display_level::label($configuredlevel),
                )
                : '',
            'hasfilewarning' => $hasfilewarning,
            'filewarning' => $hasfilewarning
                ? get_string('nofileplugin', 'assignsubmission_refchecker')
                : '',
        ]);
    }

    /**
     * The compact status shown in the submission status table and the grading table.
     *
     * @param stdClass $submission
     * @param bool $showviewlink Set to true to offer the expanded report.
     * @return string
     */
    public function view_summary(stdClass $submission, &$showviewlink) {
        global $OUTPUT;

        $showviewlink = false;

        $job = job_manager::get_job($submission);
        $level = $this->effective_display_level();
        $summary = new status_summary($job, $level, $this->viewer_is_teacher());

        if (!$summary->has_content()) {
            return '';
        }

        // Only offer the expand control to someone who is actually allowed to see more. This is
        // presentation only: view() re-checks the level itself.
        $showviewlink = $level >= display_level::SUMMARY
            && (int) $job->totalrefs > 0
            && in_array($job->status, [job_status::CHECKING, job_status::COMPLETE], true);

        return $OUTPUT->render_from_template(
            'assignsubmission_refchecker/status',
            $summary->export_for_template($OUTPUT),
        );
    }

    /**
     * The expanded report.
     *
     * This deliberately re-derives the display level rather than trusting that it was reached via
     * the expand control. assign::view_plugin_content() is reachable directly by URL and checks
     * only whether the viewer may see the submission at all, so a student whose assignment is set
     * to "status only" could otherwise read the full report by visiting that URL.
     *
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission) {
        global $OUTPUT;

        $job = job_manager::get_job($submission);
        if (!$job) {
            return '';
        }

        $isteacher = $this->viewer_is_teacher();
        $level = $this->effective_display_level();

        if ($level < display_level::SUMMARY) {
            // Not entitled to the report: fall back to the status line.
            $summary = new status_summary($job, $level, $isteacher);
            if (!$summary->has_content()) {
                return '';
            }
            return $OUTPUT->render_from_template(
                'assignsubmission_refchecker/status',
                $summary->export_for_template($OUTPUT),
            );
        }

        // Individual references are only loaded when the viewer may actually see them.
        $references = $level >= display_level::FULL
            ? job_manager::get_references((int) $job->id)
            : [];

        $report = new report(
            $job,
            $references,
            $level,
            $isteacher,
            has_capability('mod/assign:grade', $this->assignment->get_context()),
        );

        return $OUTPUT->render_from_template(
            'assignsubmission_refchecker/report',
            $report->export_for_template($OUTPUT),
        );
    }

    /**
     * Remove the stored check for a submission.
     *
     * @param stdClass $submission
     * @return bool
     */
    public function remove(stdClass $submission) {
        job_manager::delete_for_submission((int) $submission->id);

        return true;
    }

    /**
     * Carry a completed check forward when an attempt is reopened.
     *
     * The files are copied verbatim by the File submissions plugin, so re-checking them would
     * spend external API quota to arrive at the same answer.
     *
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     * @return bool
     */
    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission) {
        job_manager::copy_to_submission($sourcesubmission, $destsubmission);

        return true;
    }

    /**
     * Clean up when the assignment is deleted.
     *
     * @return bool
     */
    public function delete_instance() {
        job_manager::delete_for_assignment((int) $this->assignment->get_instance()->id);

        return true;
    }

    /**
     * Settings exposed to mobile and other web service clients.
     *
     * Deliberately narrow: nothing operational, and nothing that could reveal the site's
     * configuration to a client.
     *
     * @return array
     */
    public function get_config_for_external() {
        return [
            'studentdisplay' => (string) display_level::sanitise((int) $this->get_config('studentdisplay')),
            'checktiming' => check_timing::sanitise((string) $this->get_config('checktiming')),
        ];
    }

    /**
     * The display level that applies to the current user on this assignment.
     *
     * @return int
     */
    protected function effective_display_level(): int {
        return display_level::effective_level_for(
            $this->assignment->get_context(),
            (int) $this->get_config('studentdisplay'),
        );
    }

    /**
     * Whether the current user may see operational detail and the full report unconditionally.
     *
     * @return bool
     */
    protected function viewer_is_teacher(): bool {
        return has_capability(display_level::CAP_VIEWFULLREPORT, $this->assignment->get_context());
    }

    /**
     * Whether File submissions is usable on this assignment.
     *
     * @return bool
     */
    protected function file_plugin_available(): bool {
        $fileplugin = $this->assignment->get_submission_plugin_by_type('file');

        return $fileplugin && $fileplugin->is_enabled() && $fileplugin->is_visible();
    }

    /**
     * The starting value for an instance setting.
     *
     * Existing assignments use their saved value; new ones fall back to the site default.
     *
     * @param string $instancename Key in the assignment's plugin config.
     * @param string $sitename Key in the plugin's site configuration.
     * @param mixed $fallback Used when neither is set.
     * @return mixed
     */
    protected function setting_default(string $instancename, string $sitename, $fallback) {
        if ($this->assignment->has_instance()) {
            $value = $this->get_config($instancename);
            if ($value !== false && $value !== null && $value !== '') {
                return $value;
            }
        }

        $value = get_config('assignsubmission_refchecker', $sitename);

        return ($value === false || $value === null || $value === '') ? $fallback : $value;
    }
}

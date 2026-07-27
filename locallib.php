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
 * By default this plugin makes no submission of its own: it reads the files a student uploaded
 * through the File submissions plugin, extracts their reference list, and looks each reference up
 * in public academic databases in the background. What it contributes to the assignment UI is a
 * status, and optionally a summary or a full report.
 *
 * The "Require text references" setting changes that. In either of its Yes modes the plugin puts a
 * plain-text References box on the submission form and checks what the student pastes in, which
 * skips file text extraction entirely and means the assignment does not need File submissions at
 * all. That is why almost everything here is conditional on {@see text_mode}.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_refchecker\local\check_timing;
use assignsubmission_refchecker\local\display_level;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\local\text_mode;
use assignsubmission_refchecker\local\text_submission;
use assignsubmission_refchecker\output\report;
use assignsubmission_refchecker\output\status_summary;
use core_external\external_value;

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
     * Whether this plugin accepts something a student submits.
     *
     * False in the default mode, where the plugin contributes nothing a student can submit: that
     * keeps it out of the "have you submitted anything?" checks and out of the submission form,
     * while still letting the renderer show its status for every submission.
     *
     * True once a References box is asked for, which is what puts the box on the form and lets a
     * pasted reference list satisfy the assignment on its own, with no file uploaded.
     *
     * @return bool
     */
    public function allow_submissions() {
        return text_mode::shows_field($this->text_mode());
    }

    /**
     * Whether this plugin holds anything for a submission.
     *
     * The renderer decides whether to show the status line from this
     * (assign_submission_plugin_submission::SUMMARY is skipped for an empty plugin that allows
     * submissions), so it has to answer for the check as well as for the pasted text.
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        if (!text_mode::shows_field($this->text_mode())) {
            // The plugin stores no submission content of its own in this mode.
            return true;
        }

        if (trim(text_submission::text_for_submission($submission)) !== '') {
            return false;
        }

        // In optional mode an empty box is legitimate and the check will have run against the
        // uploaded file instead, so its status must stay visible. Being lenient here is safe: the
        // strict "did the student submit anything?" gate is submission_is_empty(), which
        // assign::new_submission_empty() applies to the form data before anything is stored.
        $job = job_manager::get_job($submission);

        return !$job || $job->status === job_status::NOTAPPLICABLE;
    }

    /**
     * Whether the reference list is missing from data that is about to be saved.
     *
     * @param stdClass $data Submission form data.
     * @return bool
     */
    public function submission_is_empty(stdClass $data) {
        if (!text_mode::shows_field($this->text_mode())) {
            return true;
        }

        return trim((string) ($data->refchecker_references ?? '')) === '';
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

        $mform->addElement(
            'select',
            'assignsubmission_refchecker_requiretext',
            get_string('requiretext', 'assignsubmission_refchecker'),
            text_mode::menu(),
        );
        $mform->addHelpButton(
            'assignsubmission_refchecker_requiretext',
            'requiretext',
            'assignsubmission_refchecker',
        );
        $mform->setDefault(
            'assignsubmission_refchecker_requiretext',
            $this->setting_default('requiretext', 'defaultrequiretext', text_mode::NONE),
        );
        $mform->hideIf(
            'assignsubmission_refchecker_requiretext',
            'assignsubmission_refchecker_enabled',
            'notchecked',
        );

        // Without File submissions there is nothing to read references out of. Say so on the
        // settings form rather than leaving the teacher to discover it after the due date. Asking
        // for a text reference list removes the need for it, so the warning goes with it.
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
            // The warning is built from the saved setting, so also react to the select changing
            // in-page rather than only after the form has been saved.
            $mform->hideIf(
                'assignsubmission_refchecker_filewarning',
                'assignsubmission_refchecker_requiretext',
                'neq',
                text_mode::NONE,
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

        $this->set_config('requiretext', text_mode::sanitise(
            (string) ($data->assignsubmission_refchecker_requiretext ?? text_mode::NONE),
        ));

        return true;
    }

    /**
     * Add the References box to the submission form.
     *
     * @param mixed $submission stdClass|null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return bool Whether anything was added.
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
        $mode = $this->text_mode();
        if (!text_mode::shows_field($mode)) {
            return false;
        }

        $data->refchecker_references = $submission
            ? text_submission::text_for((int) $submission->id)
            : '';

        // Core core_form/element-textarea already carries form-control, which is what makes the box fill
        // its column, so no class is passed here: the template hardcodes its own class attribute
        // and a second one would simply be ignored.
        $mform->addElement(
            'textarea',
            'refchecker_references',
            get_string('references', 'assignsubmission_refchecker'),
            ['rows' => 15, 'wrap' => 'virtual'],
        );
        // PARAM_RAW rather than PARAM_TEXT: strip_tags() would silently swallow legitimate
        // fragments such as "Smith <2020>". The text is escaped where it is displayed instead.
        $mform->setType('refchecker_references', PARAM_RAW);

        // Guidance the student can see without having to open a help popover.
        $mform->addElement(
            'static',
            'refchecker_referenceshint',
            '',
            get_string('references_hint', 'assignsubmission_refchecker'),
        );

        if (text_mode::is_required($mode)) {
            $mform->addRule(
                'refchecker_references',
                get_string('references_required', 'assignsubmission_refchecker'),
                'required',
                null,
                'client',
            );
        }

        return true;
    }

    /**
     * Store the pasted reference list.
     *
     * @param stdClass $submission
     * @param stdClass $data
     * @return bool
     */
    public function save(stdClass $submission, stdClass $data) {
        if (!text_mode::shows_field($this->text_mode())) {
            // The File submissions plugin owns the content in this mode.
            return true;
        }

        $text = trim((string) ($data->refchecker_references ?? ''));
        $existing = text_submission::get((int) $submission->id);

        text_submission::save(
            (int) $this->assignment->get_instance()->id,
            (int) $submission->id,
            $text,
        );

        // Announce the change so the observer can queue a check. Only worth doing when there is
        // text to check: clearing the box in optional mode falls back to the uploaded file, and
        // the File submissions plugin's own event already covers that.
        if ($text !== '') {
            $this->trigger_submission_event($submission, $existing);
        }

        return true;
    }

    /**
     * Trigger the event that tells the observer a reference list was saved.
     *
     * Modelled on assign_submission_onlinetext. mod_assign's submission_created and
     * submission_updated are abstract, so unless a subplugin triggers a subclass of them nothing
     * fires at all: with File submissions turned off there would otherwise be no way to start a
     * check on save.
     *
     * When File submissions is also enabled, both plugins fire on the same save, so the observer
     * queues twice and the job's generation is bumped twice. The second task supersedes the first,
     * which then abandons its work on the generation guard. The cost is one no-op adhoc task.
     *
     * @param stdClass $submission
     * @param stdClass|null $existing The stored record before this save, if there was one.
     * @return void
     */
    protected function trigger_submission_event(stdClass $submission, ?stdClass $existing): void {
        global $DB, $USER;

        $groupname = null;
        $groupid = 0;
        if (empty($submission->userid) && !empty($submission->groupid)) {
            $groupid = (int) $submission->groupid;
            $groupname = $DB->get_field('groups', 'name', ['id' => $groupid], MUST_EXIST);
        }

        $record = text_submission::get((int) $submission->id);

        $params = [
            'context' => $this->assignment->get_context(),
            'courseid' => (int) $this->assignment->get_course()->id,
            'objectid' => $record ? (int) $record->id : 0,
            'other' => [
                'submissionid' => (int) $submission->id,
                'submissionattempt' => (int) $submission->attemptnumber,
                'submissionstatus' => $submission->status,
                'groupid' => $groupid,
                'groupname' => $groupname,
            ],
        ];
        if (!empty($submission->userid) && (int) $submission->userid !== (int) $USER->id) {
            $params['relateduserid'] = (int) $submission->userid;
        }
        if ($this->assignment->is_blind_marking()) {
            $params['anonymous'] = 1;
        }

        $event = $existing
            ? \assignsubmission_refchecker\event\submission_updated::create($params)
            : \assignsubmission_refchecker\event\submission_created::create($params);
        $event->set_assign($this->assignment);
        $event->trigger();
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
        $output = $this->assignment->get_renderer();

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
        // A text reference list makes File submissions unnecessary, so there is nothing to warn
        // about in that case.
        $hasfilewarning = $isteacher
            && !$this->file_plugin_available()
            && !text_mode::shows_field($this->text_mode());

        return $output->render_from_template('assignsubmission_refchecker/view_header', [
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
        $output = $this->assignment->get_renderer();

        $showviewlink = false;

        $job = job_manager::get_job($submission);
        $level = $this->effective_display_level();
        $summary = new status_summary($job, $level, $this->viewer_is_teacher(), $this->norefs_from_text($submission));

        if (!$summary->has_content()) {
            // A reference list can be sitting there with no job yet, if the assignment only checks
            // on submit and the student has saved a draft. Say so rather than rendering an empty
            // plugin row, and let the expand control reach the text itself.
            if ($this->has_submitted_text($submission)) {
                $showviewlink = true;

                return $output->container(
                    get_string('references_submitted', 'assignsubmission_refchecker'),
                    'assignsubmission_refchecker-status',
                );
            }

            return '';
        }

        // Only offer the expand control to someone who is actually allowed to see more. This is
        // presentation only: view() re-checks the level itself. A pasted reference list is the
        // student's own work, so it is always worth expanding for.
        $showviewlink = ($level >= display_level::SUMMARY
            && (int) $job->totalrefs > 0
            && in_array($job->status, [job_status::CHECKING, job_status::COMPLETE], true))
            || $this->has_submitted_text($submission);

        return $output->render_from_template(
            'assignsubmission_refchecker/status',
            $summary->export_for_template($output),
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
        $output = $this->assignment->get_renderer();

        // The student's own reference list, shown whatever the display level: that setting governs
        // how much of the *result* a student may see, and this is their own submitted work.
        // assign::view_plugin_content() has already established the viewer may see this submission.
        $prefix = '';
        $text = text_mode::shows_field($this->text_mode())
            ? text_submission::text_for_submission($submission)
            : '';
        if (trim($text) !== '') {
            $prefix = $output->render_from_template(
                'assignsubmission_refchecker/submitted_text',
                ['text' => $text],
            );
        }

        $job = job_manager::get_job($submission);
        if (!$job) {
            return $prefix;
        }

        $isteacher = $this->viewer_is_teacher();
        $level = $this->effective_display_level();

        if ($level < display_level::SUMMARY) {
            // Not entitled to the report: fall back to the status line.
            $summary = new status_summary($job, $level, $isteacher, $this->norefs_from_text($submission));
            if (!$summary->has_content()) {
                return $prefix;
            }
            return $prefix . $output->render_from_template(
                'assignsubmission_refchecker/status',
                $summary->export_for_template($output),
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

        return $prefix . $output->render_from_template(
            'assignsubmission_refchecker/report',
            $report->export_for_template($output),
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
        text_submission::delete_for_submission((int) $submission->id);

        return true;
    }

    /**
     * Carry the reference list and its completed check forward when an attempt is reopened.
     *
     * The files are copied verbatim by the File submissions plugin, so re-checking them would
     * spend external API quota to arrive at the same answer. The pasted reference list is copied
     * for the same reason, and so the student starts the new attempt from what they wrote before.
     *
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     * @return bool
     */
    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission) {
        job_manager::copy_to_submission($sourcesubmission, $destsubmission);
        text_submission::copy_to_submission($sourcesubmission, $destsubmission);

        return true;
    }

    /**
     * Clean up when the assignment is deleted.
     *
     * @return bool
     */
    public function delete_instance() {
        $assignmentid = (int) $this->assignment->get_instance()->id;

        job_manager::delete_for_assignment($assignmentid);
        text_submission::delete_for_assignment($assignmentid);

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
            'requiretext' => $this->text_mode(),
        ];
    }

    /**
     * The submission fields that can be imported and exported as text.
     *
     * Lets the pasted reference list reach "Download all submissions", the grading app and the web
     * service that reads a submission back.
     *
     * @return array<string, string>
     */
    public function get_editor_fields() {
        return ['references' => get_string('references', 'assignsubmission_refchecker')];
    }

    /**
     * The stored text for one of {@see get_editor_fields()}.
     *
     * @param string $name
     * @param int $submissionid
     * @return string
     */
    public function get_editor_text($name, $submissionid) {
        return $name === 'references' ? text_submission::text_for((int) $submissionid) : '';
    }

    /**
     * The format the stored text is in.
     *
     * @param string $name
     * @param int $submissionid
     * @return int
     */
    public function get_editor_format($name, $submissionid) {
        // Cast because FORMAT_PLAIN is defined as a string, and the base method promises an int.
        return (int) FORMAT_PLAIN;
    }

    /**
     * The parameter a web service may pass to submit a reference list.
     *
     * @return array<string, external_value>
     */
    public function get_external_parameters() {
        return [
            'refchecker_references' => new external_value(
                PARAM_RAW,
                'The reference list, as plain text',
                VALUE_OPTIONAL,
            ),
        ];
    }

    /**
     * Where this assignment's reference list comes from.
     *
     * @return string One of the {@see text_mode} constants.
     */
    protected function text_mode(): string {
        return text_mode::sanitise((string) $this->get_config('requiretext'));
    }

    /**
     * Whether the student has pasted a reference list for this submission.
     *
     * @param stdClass $submission
     * @return bool
     */
    protected function has_submitted_text(stdClass $submission): bool {
        if (!text_mode::shows_field($this->text_mode())) {
            return false;
        }

        return trim(text_submission::text_for_submission($submission)) !== '';
    }

    /**
     * Whether "no references found" should be explained in terms of pasted text rather than files.
     *
     * Required mode never reads a file, so its advice is always about the text. Optional mode falls
     * back to the file when the box is left empty, so there it depends on what the student did.
     *
     * @param stdClass $submission
     * @return bool
     */
    protected function norefs_from_text(stdClass $submission): bool {
        return $this->text_mode() === text_mode::REQUIRED
            || $this->has_submitted_text($submission);
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

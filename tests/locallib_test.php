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

use assign;
use assignsubmission_refchecker\local\display_level;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\text_mode;
use assignsubmission_refchecker\local\text_submission;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Tests for the Reference Checker submission plugin's display behaviour.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assign_submission_refchecker
 */
final class locallib_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The student who owns the submission. */
    private stdClass $student;

    /** @var stdClass The teacher. */
    private stdClass $teacher;

    /** @var assign The assignment. */
    private assign $assign;

    /** @var stdClass The student's submission. */
    private stdClass $submission;

    /**
     * Set up a course with an assignment that has both File submissions and Reference Checker on.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $this->assign = $this->create_instance($this->course, [
            'assignsubmission_file_enabled' => 1,
            // Function save_settings() on the file plugin reads both of these straight off the form
            // data, so enabling it without them is an undefined property warning, which
            // PHPUnit turns into a failure before the test body ever runs.
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'assignsubmission_refchecker_enabled' => 1,
        ]);

        $this->submission = $this->assign->get_user_submission($this->student->id, true);

        // Rendering the templates needs a page with a context.
        global $PAGE;
        $PAGE->set_url('/mod/assign/view.php', ['id' => $this->assign->get_course_module()->id]);
        $PAGE->set_context($this->assign->get_context());

        job_manager::reset_caches();
        text_submission::reset_caches();
    }

    /**
     * The plugin's own submission plugin instance.
     *
     * @return \assign_submission_refchecker
     */
    private function plugin(): \assign_submission_refchecker {
        return $this->assign->get_submission_plugin_by_type('refchecker');
    }

    /**
     * A bare form to add submission elements to.
     *
     * @return \MoodleQuickForm
     */
    private function submission_form(): \MoodleQuickForm {
        global $CFG;

        require_once($CFG->libdir . '/formslib.php');

        return new \MoodleQuickForm('refcheckertestform', 'post', '/');
    }

    /**
     * Store a pasted reference list for the student's submission.
     *
     * @return string The text that was stored.
     */
    private function seed_text_submission(): string {
        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $text = $generator->pasted_reference_list(3);
        $generator->create_text_submission($this->submission, $text);

        return $text;
    }

    /**
     * Seed a completed check with a known mix of results.
     *
     * @param string $rawref A distinctive reference string to look for in the output.
     * @return stdClass The job.
     */
    private function seed_completed_job(string $rawref = 'DISTINCTIVEREFERENCETEXT'): stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');

        $job = $generator->create_job([
            'assignment' => $this->assign->get_instance()->id,
            'submission' => $this->submission->id,
            'status' => job_status::COMPLETE,
            'totalrefs' => 2,
            'checkedrefs' => 2,
            'verifiedrefs' => 1,
            'notfoundrefs' => 1,
        ]);
        $generator->create_reference($job, ['sortorder' => 0, 'rawref' => $rawref]);
        $generator->create_reference($job, [
            'sortorder' => 1,
            'rawref' => 'Another reference',
            'matchstatus' => match_status::NOTFOUND,
            'foundtitle' => null,
        ]);

        job_manager::reset_caches();
        text_submission::reset_caches();

        return $job;
    }

    /**
     * Whether the plugin counts as something a student can submit follows the mode.
     *
     * In the default mode it must not, because it holds nothing a student wrote and would
     * otherwise make an empty submission look full. In either Yes mode it must, because that is
     * what puts the References box on the form and lets a pasted list stand in for a file.
     *
     * @dataProvider text_mode_provider
     * @param string $mode The requiretext setting.
     * @param bool $expected Whether submissions should be allowed.
     */
    public function test_allow_submissions_follows_mode(string $mode, bool $expected): void {
        $this->plugin()->set_config('requiretext', $mode);

        $this->assertSame($expected, $this->plugin()->allow_submissions());
    }

    /**
     * Data provider over the three modes, paired with whether a References box is asked for.
     *
     * @return array[]
     */
    public static function text_mode_provider(): array {
        return [
            'no text box' => [text_mode::NONE, false],
            'optional text box' => [text_mode::OPTIONAL, true],
            'required text box' => [text_mode::REQUIRED, true],
        ];
    }

    /**
     * In the default mode the plugin holds no submission content of its own.
     */
    public function test_is_empty_is_true_when_no_text_is_asked_for(): void {
        $this->seed_completed_job();

        $this->assertTrue($this->plugin()->is_empty($this->submission));
    }

    /**
     * A pasted reference list is submission content, so the plugin is no longer empty.
     */
    public function test_is_empty_is_false_once_text_is_pasted(): void {
        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $this->seed_text_submission();

        $this->assertFalse($this->plugin()->is_empty($this->submission));
    }

    /**
     * In optional mode an empty box with a finished file based check is not "empty".
     *
     * The renderer hides the status line for an empty plugin that allows submissions, so answering
     * true here would lose the result of the check that did run.
     */
    public function test_is_empty_is_false_in_optional_mode_when_a_check_exists(): void {
        $this->plugin()->set_config('requiretext', text_mode::OPTIONAL);
        $this->seed_completed_job();

        $this->assertFalse($this->plugin()->is_empty($this->submission));
    }

    /**
     * In optional mode with nothing pasted and nothing checked there is genuinely nothing here.
     */
    public function test_is_empty_is_true_in_optional_mode_with_nothing_at_all(): void {
        $this->plugin()->set_config('requiretext', text_mode::OPTIONAL);

        $this->assertTrue($this->plugin()->is_empty($this->submission));
    }

    /**
     * The pre-save emptiness check reads the form data, whatever is already stored.
     *
     * @dataProvider submission_is_empty_provider
     * @param string $mode The requiretext setting.
     * @param string|null $pasted What the student typed into the box, or null for no field at all.
     * @param bool $expected Whether the submission should be treated as empty.
     */
    public function test_submission_is_empty(string $mode, ?string $pasted, bool $expected): void {
        $this->plugin()->set_config('requiretext', $mode);

        $data = new stdClass();
        if ($pasted !== null) {
            $data->refchecker_references = $pasted;
        }

        $this->assertSame($expected, $this->plugin()->submission_is_empty($data));
    }

    /**
     * Data provider for the pre-save emptiness test.
     *
     * @return array[]
     */
    public static function submission_is_empty_provider(): array {
        return [
            'no text box, nothing submitted' => [text_mode::NONE, null, true],
            'no text box ignores stray data' => [text_mode::NONE, 'Author, A. (2020). Thing.', true],
            'required, box filled in' => [text_mode::REQUIRED, 'Author, A. (2020). Thing.', false],
            'required, box empty' => [text_mode::REQUIRED, '', true],
            'required, box all whitespace' => [text_mode::REQUIRED, "  \n\t ", true],
            'optional, box empty' => [text_mode::OPTIONAL, '', true],
            'optional, box filled in' => [text_mode::OPTIONAL, 'Author, A. (2020). Thing.', false],
        ];
    }

    /**
     * The References box only appears when the assignment asks for one.
     *
     * @dataProvider text_mode_provider
     * @param string $mode The requiretext setting.
     * @param bool $expected Whether the box should be added.
     */
    public function test_get_form_elements_follows_mode(string $mode, bool $expected): void {
        $this->plugin()->set_config('requiretext', $mode);

        $mform = $this->submission_form();
        $data = new stdClass();

        $added = $this->plugin()->get_form_elements($this->submission, $mform, $data);

        $this->assertSame($expected, $added);
        $this->assertSame($expected, $mform->elementExists('refchecker_references'));
    }

    /**
     * The box comes back pre-filled with whatever the student saved last time.
     */
    public function test_get_form_elements_prefills_the_stored_text(): void {
        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $text = $this->seed_text_submission();

        $mform = $this->submission_form();
        $data = new stdClass();

        $this->plugin()->get_form_elements($this->submission, $mform, $data);

        $this->assertSame($text, $data->refchecker_references);
    }

    /**
     * Saving stores the pasted list, and saving again replaces it.
     */
    public function test_save_stores_and_replaces_the_text(): void {
        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $this->setUser($this->student);

        $this->plugin()->save($this->submission, (object) [
            'refchecker_references' => "  Author, A. (2020). First. Journal of Things.  ",
        ]);

        // Stored trimmed, so stray whitespace does not count as a change.
        $this->assertSame(
            'Author, A. (2020). First. Journal of Things.',
            text_submission::text_for((int) $this->submission->id),
        );

        $this->plugin()->save($this->submission, (object) [
            'refchecker_references' => 'Author, B. (2021). Second. Journal of Things.',
        ]);

        $this->assertSame(
            'Author, B. (2021). Second. Journal of Things.',
            text_submission::text_for((int) $this->submission->id),
        );
    }

    /**
     * In the default mode save() must not store anything, even if data arrives.
     */
    public function test_save_stores_nothing_when_no_text_is_asked_for(): void {
        global $DB;

        $this->setUser($this->student);

        $this->plugin()->save($this->submission, (object) [
            'refchecker_references' => 'Author, A. (2020). Thing.',
        ]);

        $this->assertSame(
            0,
            $DB->count_records(text_submission::TABLE, ['submission' => $this->submission->id]),
        );
    }

    /**
     * Saving a reference list announces itself, so the observer can queue a check.
     *
     * With File submissions turned off this is the only thing that fires on save, so nothing would
     * ever be checked without it.
     */
    public function test_save_triggers_a_submission_event(): void {
        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $this->setUser($this->student);

        $sink = $this->redirectEvents();
        $this->plugin()->save($this->submission, (object) [
            'refchecker_references' => 'Author, A. (2020). First. Journal of Things.',
        ]);
        $created = $sink->get_events();

        $this->plugin()->save($this->submission, (object) [
            'refchecker_references' => 'Author, B. (2021). Second. Journal of Things.',
        ]);
        $updated = array_slice($sink->get_events(), count($created));
        $sink->close();

        $this->assertInstanceOf(\assignsubmission_refchecker\event\submission_created::class, $created[0]);
        $this->assertSame((int) $this->submission->id, (int) $created[0]->other['submissionid']);
        $this->assertInstanceOf(\assignsubmission_refchecker\event\submission_updated::class, $updated[0]);
    }

    /**
     * Clearing the box says nothing: in optional mode that hands the job back to the file, and the
     * File submissions plugin's own event already covers that.
     */
    public function test_save_with_an_empty_box_triggers_nothing(): void {
        $this->plugin()->set_config('requiretext', text_mode::OPTIONAL);
        $this->setUser($this->student);

        $sink = $this->redirectEvents();
        $this->plugin()->save($this->submission, (object) ['refchecker_references' => '']);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame([], $events);
        $this->assertSame('', text_submission::text_for((int) $this->submission->id));
    }

    /**
     * The pasted list is the student's own work, so it is shown at every display level.
     *
     * Unlike the check results, which display_level governs, there is nothing to withhold from the
     * person who wrote it.
     *
     * @dataProvider display_level_provider
     * @param int $level The assignment's studentdisplay setting.
     */
    public function test_view_shows_the_pasted_text_at_every_level(int $level): void {
        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $this->plugin()->set_config('studentdisplay', $level);
        $text = $this->seed_text_submission();
        $this->seed_completed_job();

        $this->setUser($this->student);

        $output = $this->plugin()->view($this->submission);

        $this->assertStringContainsString('Submitted reference list', $output);
        $this->assertStringContainsString($text, $output);
    }

    /**
     * Data provider over every display level.
     *
     * @return array[]
     */
    public static function display_level_provider(): array {
        return [
            'status only' => [display_level::STATUS_ONLY],
            'summary' => [display_level::SUMMARY],
            'full' => [display_level::FULL],
        ];
    }

    /**
     * The pasted list is reachable even before any check has been queued.
     */
    public function test_view_summary_offers_the_text_before_a_check_exists(): void {
        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $text = $this->seed_text_submission();

        $this->setUser($this->student);

        $showviewlink = false;
        $summary = $this->plugin()->view_summary($this->submission, $showviewlink);

        $this->assertStringContainsString('A reference list has been submitted.', $summary);
        $this->assertTrue($showviewlink);
        $this->assertStringContainsString($text, $this->plugin()->view($this->submission));
    }

    /**
     * With no job at all there is nothing to show and nothing to expand.
     */
    public function test_view_summary_with_no_job_is_blank(): void {
        $this->setUser($this->student);

        $showviewlink = true;
        $output = $this->plugin()->view_summary($this->submission, $showviewlink);

        $this->assertSame('', $output);
        $this->assertFalse($showviewlink);
    }

    /**
     * Each job state produces its own status line.
     *
     * @dataProvider status_line_provider
     * @param string $status The job status to seed.
     * @param string $expected A fragment that must appear in the rendered status.
     */
    public function test_view_summary_status_lines(string $status, string $expected): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $generator->create_job([
            'assignment' => $this->assign->get_instance()->id,
            'submission' => $this->submission->id,
            'status' => $status,
            'totalrefs' => 16,
            'checkedrefs' => 15,
        ]);
        job_manager::reset_caches();
        text_submission::reset_caches();

        $this->setUser($this->student);

        $showviewlink = false;
        $output = $this->plugin()->view_summary($this->submission, $showviewlink);

        $this->assertStringContainsString($expected, $output);
    }

    /**
     * Data provider for the status line test.
     *
     * @return array[]
     */
    public static function status_line_provider(): array {
        return [
            'pending' => [job_status::PENDING, 'Pending checks'],
            'extracting' => [job_status::EXTRACTING, 'Reading your references'],
            'checking' => [job_status::CHECKING, '15 / 16 (94%) checks complete'],
            'complete' => [job_status::COMPLETE, 'Complete'],
            'norefs' => [job_status::NOREFS, 'No references found'],
            'failed' => [job_status::FAILED, 'Checking could not be completed'],
        ];
    }

    /**
     * A student told nothing gets no expand control; one told more does.
     *
     * @dataProvider showviewlink_provider
     * @param int $level The assignment's studentdisplay setting.
     * @param bool $expected Whether the expand control should be offered.
     */
    public function test_view_summary_showviewlink_follows_level(int $level, bool $expected): void {
        $this->seed_completed_job();
        $this->plugin()->set_config('studentdisplay', $level);

        $this->setUser($this->student);

        $showviewlink = false;
        $this->plugin()->view_summary($this->submission, $showviewlink);

        $this->assertSame($expected, $showviewlink);
    }

    /**
     * Data provider for the expand control test.
     *
     * @return array[]
     */
    public static function showviewlink_provider(): array {
        return [
            'status only' => [display_level::STATUS_ONLY, false],
            'summary' => [display_level::SUMMARY, true],
            'full' => [display_level::FULL, true],
        ];
    }

    /**
     * Counts are withheld from a student who is only meant to see the status.
     */
    public function test_view_summary_hides_counts_at_status_only(): void {
        $this->seed_completed_job();
        $this->plugin()->set_config('studentdisplay', display_level::STATUS_ONLY);

        $this->setUser($this->student);

        $showviewlink = false;
        $output = $this->plugin()->view_summary($this->submission, $showviewlink);

        $this->assertStringContainsString('Complete', $output);
        $this->assertStringNotContainsString('Verified', $output);
    }

    /**
     * view() must not leak the report to a student below full level.
     *
     * assign::view_plugin_content() is reachable directly by URL and checks only whether the
     * viewer may see the submission, so view() has to enforce the display level itself rather
     * than relying on the expand control being hidden.
     *
     * @dataProvider view_leak_provider
     * @param int $level The assignment's studentdisplay setting.
     * @param bool $shouldsee Whether the student should see their own reference text.
     */
    public function test_view_does_not_leak_references_below_full_level(int $level, bool $shouldsee): void {
        $rawref = 'DISTINCTIVEREFERENCETEXT';
        $this->seed_completed_job($rawref);
        $this->plugin()->set_config('studentdisplay', $level);

        $this->setUser($this->student);

        $output = $this->plugin()->view($this->submission);

        if ($shouldsee) {
            $this->assertStringContainsString($rawref, $output);
        } else {
            $this->assertStringNotContainsString($rawref, $output);
        }
    }

    /**
     * Data provider for the leak test.
     *
     * @return array[]
     */
    public static function view_leak_provider(): array {
        return [
            'status only sees nothing' => [display_level::STATUS_ONLY, false],
            'summary sees no reference text' => [display_level::SUMMARY, false],
            'full sees reference text' => [display_level::FULL, true],
        ];
    }

    /**
     * A teacher sees the full report even when students are told nothing.
     */
    public function test_teacher_sees_full_report_regardless_of_setting(): void {
        $rawref = 'DISTINCTIVEREFERENCETEXT';
        $this->seed_completed_job($rawref);
        $this->plugin()->set_config('studentdisplay', display_level::STATUS_ONLY);

        $this->setUser($this->teacher);

        $output = $this->plugin()->view($this->submission);

        $this->assertStringContainsString($rawref, $output);
    }

    /**
     * The summary level shows aggregate counts but never an individual reference.
     */
    public function test_summary_level_shows_dashboard_without_references(): void {
        $rawref = 'DISTINCTIVEREFERENCETEXT';
        $this->seed_completed_job($rawref);
        $this->plugin()->set_config('studentdisplay', display_level::SUMMARY);

        $this->setUser($this->student);

        $output = $this->plugin()->view($this->submission);

        $this->assertStringContainsString('Reference check report', $output);
        $this->assertStringNotContainsString($rawref, $output);
    }

    /**
     * The explanatory header appears when the plugin is on, and says what this viewer will see.
     */
    public function test_view_header_describes_what_the_student_will_see(): void {
        $this->plugin()->set_config('studentdisplay', display_level::STATUS_ONLY);

        $this->setUser($this->student);

        $output = $this->plugin()->view_header();

        $this->assertStringContainsString('checked automatically', $output);
        $this->assertStringContainsString('but not the detailed results', $output);
    }

    /**
     * A site configured message replaces the default wording.
     */
    public function test_view_header_uses_configured_information(): void {
        set_config('studentinformation', '<p>Massey specific wording.</p>', 'assignsubmission_refchecker');

        $this->setUser($this->student);

        $output = $this->plugin()->view_header();

        $this->assertStringContainsString('Massey specific wording.', $output);
        $this->assertStringNotContainsString('checked automatically', $output);
    }

    /**
     * Removing a submission clears everything the plugin holds for it.
     */
    public function test_remove_deletes_job_references_and_text(): void {
        global $DB;

        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $this->seed_text_submission();
        $this->seed_completed_job();

        $this->assertSame(1, $DB->count_records(job_manager::TABLE_JOB, ['submission' => $this->submission->id]));
        $this->assertSame(2, $DB->count_records(job_manager::TABLE_REFS, ['submission' => $this->submission->id]));
        $this->assertSame(1, $DB->count_records(text_submission::TABLE, ['submission' => $this->submission->id]));

        $this->plugin()->remove($this->submission);

        $this->assertSame(0, $DB->count_records(job_manager::TABLE_JOB, ['submission' => $this->submission->id]));
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_REFS, ['submission' => $this->submission->id]));
        $this->assertSame(0, $DB->count_records(text_submission::TABLE, ['submission' => $this->submission->id]));
    }

    /**
     * Deleting the assignment clears everything belonging to it.
     */
    public function test_delete_instance_clears_the_assignment(): void {
        global $DB;

        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $this->seed_text_submission();
        $this->seed_completed_job();

        $this->plugin()->delete_instance();

        $assignmentid = $this->assign->get_instance()->id;
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_JOB, ['assignment' => $assignmentid]));
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_REFS, ['submission' => $this->submission->id]));
        $this->assertSame(0, $DB->count_records(text_submission::TABLE, ['assignment' => $assignmentid]));
    }

    /**
     * Reopening an attempt carries the finished check forward rather than re-checking.
     */
    public function test_copy_submission_carries_results_forward(): void {
        global $DB;

        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $text = $this->seed_text_submission();
        $job = $this->seed_completed_job();

        $destination = (object) [
            'id' => $this->submission->id + 1000,
            'assignment' => $this->assign->get_instance()->id,
        ];

        $this->plugin()->copy_submission($this->submission, $destination);

        $copied = $DB->get_record(job_manager::TABLE_JOB, ['submission' => $destination->id]);
        $this->assertNotFalse($copied);
        $this->assertSame($job->contenthash, $copied->contenthash);
        $this->assertSame(2, $DB->count_records(job_manager::TABLE_REFS, ['jobid' => $copied->id]));

        // The student starts the reopened attempt from what they wrote last time.
        $this->assertSame($text, text_submission::text_for((int) $destination->id));
    }

    /**
     * Only the settings a client needs to render the submission form are exposed.
     */
    public function test_get_config_for_external_is_narrow(): void {
        $config = $this->plugin()->get_config_for_external();

        $this->assertSame(['studentdisplay', 'checktiming', 'requiretext'], array_keys($config));
        $this->assertSame(text_mode::NONE, $config['requiretext']);
    }

    /**
     * The pasted list is exported as text, so it reaches downloads and web services.
     */
    public function test_editor_fields_expose_the_pasted_text(): void {
        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $text = $this->seed_text_submission();
        $submissionid = (int) $this->submission->id;

        $this->assertSame(['references'], array_keys($this->plugin()->get_editor_fields()));
        $this->assertSame($text, $this->plugin()->get_editor_text('references', $submissionid));
        $this->assertSame((int) FORMAT_PLAIN, $this->plugin()->get_editor_format('references', $submissionid));
    }
}

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
use assignsubmission_refchecker\local\check_timing;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\text_mode;
use assignsubmission_refchecker\local\text_submission;
use assignsubmission_refchecker\task\extract_references;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Tests for queueing a check when a student submits.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\event\observer
 */
final class observer_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The student. */
    private stdClass $student;

    /** @var assign The assignment. */
    private assign $assign;

    /** @var stdClass The student's submission. */
    private stdClass $submission;

    /**
     * Set up an assignment with both File submissions and Reference Checker enabled.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->assign = $this->create_instance($this->course, [
            'assignsubmission_file_enabled' => 1,
            // save_settings() on the file plugin reads both of these straight off the form
            // data, so enabling it without them is an undefined property warning, which
            // PHPUnit turns into a failure before the test body ever runs.
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'assignsubmission_refchecker_enabled' => 1,
        ]);

        $this->submission = $this->assign->get_user_submission($this->student->id, true);

        job_manager::reset_caches();
        text_submission::reset_caches();
    }

    /**
     * The reference checker plugin instance.
     *
     * @return \assign_submission_refchecker
     */
    private function plugin(): \assign_submission_refchecker {
        return $this->assign->get_submission_plugin_by_type('refchecker');
    }

    /**
     * Rebuild the assignment object.
     *
     * assign_plugin memoises is_enabled() and is_visible() for the life of the object, so any
     * test that changes those settings has to start again with a fresh one.
     *
     * @return void
     */
    private function reload_assign(): void {
        $cm = $this->assign->get_course_module();
        $this->assign = new assign(\context_module::instance($cm->id), $cm, $this->course);
    }

    /**
     * How many extraction tasks are queued.
     *
     * @return int
     */
    private function queued_tasks(): int {
        global $DB;

        return $DB->count_records('task_adhoc', ['classname' => '\\' . extract_references::class]);
    }

    /**
     * Trigger the File submissions plugin's own submission_created event.
     *
     * The plugin observes the abstract \mod_assign\event\submission_created rather than this
     * concrete class, so this is what proves that indirection works.
     *
     * @return void
     */
    private function trigger_file_submission_created(): void {
        global $DB;

        $fileid = $DB->insert_record('assignsubmission_file', (object) [
            'assignment' => $this->assign->get_instance()->id,
            'submission' => $this->submission->id,
            'numfiles' => 1,
        ]);

        $event = \assignsubmission_file\event\submission_created::create([
            'context' => $this->assign->get_context(),
            'objectid' => $fileid,
            'relateduserid' => $this->student->id,
            'other' => [
                'submissionid' => $this->submission->id,
                'submissionattempt' => 0,
                'submissionstatus' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
                'filesubmissioncount' => 1,
            ],
        ]);
        $event->set_assign($this->assign);
        $event->trigger();
    }

    /**
     * Trigger this plugin's own submission_created event for a pasted reference list.
     *
     * @return void
     */
    private function trigger_text_submission_created(): void {
        $record = $this->getDataGenerator()
            ->get_plugin_generator('assignsubmission_refchecker')
            ->create_text_submission(
                $this->submission,
                'Author, A. (2020). A study of things. Journal of Things, 1(1), 1-10.',
            );

        $event = \assignsubmission_refchecker\event\submission_created::create([
            'context' => $this->assign->get_context(),
            'objectid' => $record->id,
            'relateduserid' => $this->student->id,
            'other' => [
                'submissionid' => $this->submission->id,
                'submissionattempt' => 0,
                'submissionstatus' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            ],
        ]);
        $event->set_assign($this->assign);
        $event->trigger();
    }

    /**
     * Trigger a submit for grading.
     *
     * @return void
     */
    private function trigger_assessable_submitted(): void {
        $event = \mod_assign\event\assessable_submitted::create_from_submission(
            $this->assign,
            $this->submission,
            false,
        );
        $event->trigger();
    }

    /**
     * Submitting for grading queues exactly one extraction task.
     */
    public function test_submit_for_grading_queues_a_check(): void {
        $this->plugin()->set_config('checktiming', check_timing::SUBMIT);

        $this->trigger_assessable_submitted();

        $this->assertSame(1, $this->queued_tasks());

        $job = job_manager::load((int) $this->submission->id);
        $this->assertNotNull($job);
        $this->assertSame(1, (int) $job->generation);
    }

    /**
     * The File submissions plugin's own event is caught through its abstract parent.
     *
     * This is the single most load-bearing assumption in the whole pipeline: the plugin never
     * names another subplugin's event class, and relies on core resolving the ancestry.
     */
    public function test_file_plugin_event_is_caught_via_its_abstract_parent(): void {
        $this->plugin()->set_config('checktiming', check_timing::SAVE);

        $this->trigger_file_submission_created();

        $this->assertSame(1, $this->queued_tasks());
    }

    /**
     * Each submission gets its own task, distinguished by generation.
     *
     * Task deduplication matches on custom data, which carries the generation, so a second
     * submission is correctly treated as new work rather than as a duplicate. The task queued for
     * the superseded generation still exists, and abandons itself when it runs.
     */
    public function test_each_submission_gets_its_own_task(): void {
        $this->plugin()->set_config('checktiming', check_timing::SUBMIT);

        $this->trigger_assessable_submitted();
        $this->trigger_assessable_submitted();

        $job = job_manager::load((int) $this->submission->id);
        $this->assertSame(2, (int) $job->generation);
        $this->assertSame(2, $this->queued_tasks());
    }

    /**
     * Re-triggering without a new generation does not queue a second identical task.
     */
    public function test_identical_work_is_not_queued_twice(): void {
        global $DB;

        $this->plugin()->set_config('checktiming', check_timing::SUBMIT);
        $this->trigger_assessable_submitted();

        $before = $DB->get_records('task_adhoc');
        $this->assertCount(1, $before);

        // Queue the very same work again, as the reconcile task would.
        $task = new extract_references();
        $task->set_custom_data([
            'submissionid' => (int) $this->submission->id,
            'generation' => (int) job_manager::load((int) $this->submission->id)->generation,
        ]);
        $task->set_component('assignsubmission_refchecker');
        \core\task\manager::queue_adhoc_task($task, true);

        $this->assertSame(1, $this->queued_tasks());
    }

    /**
     * Resubmitting bumps the generation and discards the previous results.
     *
     * Without this a student who edits their submission mid-check would be shown results
     * describing the files they replaced.
     */
    public function test_resubmission_bumps_generation_and_clears_results(): void {
        global $DB;

        $this->plugin()->set_config('checktiming', check_timing::SUBMIT);
        $this->trigger_assessable_submitted();

        $job = job_manager::load((int) $this->submission->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $generator->create_reference($job, ['rawref' => 'A reference from the first attempt.']);

        $this->assertSame(1, $DB->count_records(job_manager::TABLE_REFS, ['jobid' => $job->id]));

        $this->trigger_assessable_submitted();

        $updated = job_manager::load((int) $this->submission->id);
        $this->assertSame(2, (int) $updated->generation);
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_REFS, ['jobid' => $updated->id]));
    }

    /**
     * Nothing is queued when the plugin, or what it depends on, is unavailable.
     *
     * @dataProvider disabled_provider
     * @param string $disable Which part of the setup to switch off.
     */
    public function test_nothing_is_queued_when_unavailable(string $disable): void {
        $this->plugin()->set_config('checktiming', check_timing::SUBMIT);

        switch ($disable) {
            case 'plugin':
                $this->plugin()->disable();
                break;
            case 'fileplugin':
                $this->assign->get_submission_plugin_by_type('file')->disable();
                break;
            case 'sitewide':
                set_config('disabled', 1, 'assignsubmission_refchecker');
                break;
        }

        // Both is_enabled() and is_visible() are memoised on the plugin object.
        $this->reload_assign();

        $this->trigger_assessable_submitted();

        $this->assertSame(0, $this->queued_tasks());
    }

    /**
     * Data provider of ways the check should not run.
     *
     * @return array[]
     */
    public static function disabled_provider(): array {
        return [
            'plugin disabled on the assignment' => ['plugin'],
            'file submissions disabled' => ['fileplugin'],
            'plugin hidden site wide' => ['sitewide'],
        ];
    }

    /**
     * An assignment asking for a pasted reference list does not need File submissions.
     *
     * Without this the plugin could not be used on its own, because the observer's file plugin
     * guard would swallow every trigger.
     *
     * @dataProvider text_mode_provider
     * @param string $mode The requiretext setting.
     */
    public function test_check_is_queued_with_no_file_plugin_in_text_mode(string $mode): void {
        $this->plugin()->set_config('checktiming', check_timing::SUBMIT);
        $this->plugin()->set_config('requiretext', $mode);
        $this->assign->get_submission_plugin_by_type('file')->disable();

        // Both is_enabled() and is_visible() are memoised on the plugin object.
        $this->reload_assign();

        $this->trigger_assessable_submitted();

        $this->assertSame(1, $this->queued_tasks());
    }

    /**
     * Data provider over the two modes that offer a References box.
     *
     * @return array[]
     */
    public static function text_mode_provider(): array {
        return [
            'optional text box' => [text_mode::OPTIONAL],
            'required text box' => [text_mode::REQUIRED],
        ];
    }

    /**
     * The plugin's own save event queues a check, which is the only trigger in text-only mode.
     */
    public function test_pasted_list_event_queues_a_check(): void {
        $this->plugin()->set_config('checktiming', check_timing::SAVE);
        $this->plugin()->set_config('requiretext', text_mode::REQUIRED);
        $this->assign->get_submission_plugin_by_type('file')->disable();
        $this->reload_assign();

        $this->trigger_text_submission_created();

        $this->assertSame(1, $this->queued_tasks());
    }

    /**
     * An assignment set to check on submit ignores draft saves.
     */
    public function test_save_does_not_queue_when_timing_is_submit(): void {
        $this->plugin()->set_config('checktiming', check_timing::SUBMIT);

        $this->trigger_file_submission_created();

        $this->assertSame(0, $this->queued_tasks());
    }

    /**
     * An assignment set to check on save is not checked twice when the student submits.
     */
    public function test_submit_does_not_double_queue_when_timing_is_save(): void {
        $this->plugin()->set_config('checktiming', check_timing::SAVE);

        $this->trigger_assessable_submitted();

        $this->assertSame(0, $this->queued_tasks());
    }
}

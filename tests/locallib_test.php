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
            'assignsubmission_refchecker_enabled' => 1,
        ]);

        $this->submission = $this->assign->get_user_submission($this->student->id, true);

        // Rendering the templates needs a page with a context.
        global $PAGE;
        $PAGE->set_url('/mod/assign/view.php', ['id' => $this->assign->get_course_module()->id]);
        $PAGE->set_context($this->assign->get_context());

        job_manager::reset_caches();
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

        return $job;
    }

    /**
     * The plugin must not count as something a student can submit.
     */
    public function test_allow_submissions_is_false(): void {
        $this->assertFalse($this->plugin()->allow_submissions());
    }

    /**
     * The plugin holds no submission content, so it must never make an empty submission look full.
     */
    public function test_is_empty_is_always_true(): void {
        $this->seed_completed_job();

        $this->assertTrue($this->plugin()->is_empty($this->submission));
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
     * Removing a submission clears both of the plugin's tables.
     */
    public function test_remove_deletes_job_and_references(): void {
        global $DB;

        $this->seed_completed_job();

        $this->assertSame(1, $DB->count_records(job_manager::TABLE_JOB, ['submission' => $this->submission->id]));
        $this->assertSame(2, $DB->count_records(job_manager::TABLE_REFS, ['submission' => $this->submission->id]));

        $this->plugin()->remove($this->submission);

        $this->assertSame(0, $DB->count_records(job_manager::TABLE_JOB, ['submission' => $this->submission->id]));
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_REFS, ['submission' => $this->submission->id]));
    }

    /**
     * Deleting the assignment clears everything belonging to it.
     */
    public function test_delete_instance_clears_the_assignment(): void {
        global $DB;

        $this->seed_completed_job();

        $this->plugin()->delete_instance();

        $assignmentid = $this->assign->get_instance()->id;
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_JOB, ['assignment' => $assignmentid]));
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_REFS, ['submission' => $this->submission->id]));
    }

    /**
     * Reopening an attempt carries the finished check forward rather than re-checking.
     */
    public function test_copy_submission_carries_results_forward(): void {
        global $DB;

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
    }

    /**
     * Only the two display settings are exposed to web service clients.
     */
    public function test_get_config_for_external_is_narrow(): void {
        $config = $this->plugin()->get_config_for_external();

        $this->assertSame(['studentdisplay', 'checktiming'], array_keys($config));
    }
}

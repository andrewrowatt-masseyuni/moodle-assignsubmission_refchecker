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
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\text_submission;
use assignsubmission_refchecker\privacy\provider;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use mod_assign\privacy\assign_plugin_request_data;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Tests for the privacy provider.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\privacy\provider
 */
final class privacy_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The student whose data is under test. */
    private stdClass $student;

    /** @var stdClass Another student, whose data must survive. */
    private stdClass $otherstudent;

    /** @var assign The assignment. */
    private assign $assign;

    /**
     * Set up an assignment with two students, each with a checked submission.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->otherstudent = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->assign = $this->create_instance($this->course, [
            'assignsubmission_file_enabled' => 1,
            // Function save_settings() on the file plugin reads both of these straight off the form
            // data, so enabling it without them is an undefined property warning, which
            // PHPUnit turns into a failure before the test body ever runs.
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'assignsubmission_refchecker_enabled' => 1,
        ]);

        // The export writer accumulates across a request, so start each test with an empty one.
        writer::reset();

        job_manager::reset_caches();
        text_submission::reset_caches();
    }

    /**
     * Seed a completed check for a student.
     *
     * @param stdClass $user
     * @param string $rawref
     * @return stdClass The submission.
     */
    private function seed_for(stdClass $user, string $rawref): stdClass {
        $submission = $this->assign->get_user_submission($user->id, true);

        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $job = $generator->create_job([
            'assignment' => $this->assign->get_instance()->id,
            'submission' => $submission->id,
            'totalrefs' => 1,
            'checkedrefs' => 1,
            'verifiedrefs' => 1,
        ]);
        $generator->create_reference($job, ['rawref' => $rawref]);
        $generator->create_text_submission($submission, 'PASTED ' . $rawref);

        job_manager::reset_caches();
        text_submission::reset_caches();

        return $submission;
    }

    /**
     * The metadata declares both tables and, critically, both external services.
     *
     * Reference text leaves the institution. If that is not declared the plugin is not compliant,
     * whatever else it does correctly.
     */
    public function test_metadata_declares_storage_and_external_transmission(): void {
        $collection = provider::get_metadata(new collection('assignsubmission_refchecker'));

        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
        }

        $this->assertContains(job_manager::TABLE_JOB, $names);
        $this->assertContains(job_manager::TABLE_REFS, $names);
        $this->assertContains(job_manager::TABLE_CACHE, $names);
        $this->assertContains(text_submission::TABLE, $names);
        $this->assertContains('crossref', $names);
        $this->assertContains('openalex', $names);
    }

    /**
     * A user's export contains their references and the results.
     */
    public function test_export_includes_the_references(): void {
        $submission = $this->seed_for($this->student, 'EXPORTEDREFERENCETEXT');

        $context = $this->assign->get_context();
        $exportdata = new assign_plugin_request_data(
            $context,
            $this->assign,
            $submission,
            ['Reference check test'],
            $this->student,
        );

        provider::export_submission_user_data($exportdata);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $exported = $writer->get_data([
            'Reference check test',
            get_string('privacy:path', 'assignsubmission_refchecker'),
        ]);

        $this->assertSame(1, $exported->referencesfound);
        $this->assertSame('EXPORTEDREFERENCETEXT', $exported->references[0]->reference);
        $this->assertSame('PASTED EXPORTEDREFERENCETEXT', $exported->submittedreferences);
    }

    /**
     * A pasted list is exported even when no check has run against it yet.
     */
    public function test_export_includes_a_pasted_list_with_no_job(): void {
        $submission = $this->assign->get_user_submission($this->student->id, true);
        $this->getDataGenerator()
            ->get_plugin_generator('assignsubmission_refchecker')
            ->create_text_submission($submission, 'UNCHECKEDPASTEDLIST');

        $context = $this->assign->get_context();
        provider::export_submission_user_data(new assign_plugin_request_data(
            $context,
            $this->assign,
            $submission,
            ['Reference check test'],
            $this->student,
        ));

        $exported = writer::with_context($context)->get_data([
            'Reference check test',
            get_string('privacy:path', 'assignsubmission_refchecker'),
        ]);

        $this->assertSame('UNCHECKEDPASTEDLIST', $exported->submittedreferences);
    }

    /**
     * Deleting for a context clears the whole assignment.
     */
    public function test_delete_for_context_clears_everything(): void {
        global $DB;

        $this->seed_for($this->student, 'One');
        $this->seed_for($this->otherstudent, 'Two');

        $requestdata = new assign_plugin_request_data($this->assign->get_context(), $this->assign);
        provider::delete_submission_for_context($requestdata);

        $this->assertSame(0, $DB->count_records(job_manager::TABLE_JOB));
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_REFS));
        $this->assertSame(0, $DB->count_records(text_submission::TABLE));
    }

    /**
     * Deleting one user's data leaves everyone else's alone.
     */
    public function test_delete_for_userid_leaves_others_intact(): void {
        global $DB;

        $submission = $this->seed_for($this->student, 'Mine');
        $this->seed_for($this->otherstudent, 'Theirs');

        $deletedata = new assign_plugin_request_data(
            $this->assign->get_context(),
            $this->assign,
            $submission,
            [],
            $this->student,
        );
        provider::delete_submission_for_userid($deletedata);

        $this->assertSame(0, $DB->count_records(job_manager::TABLE_JOB, ['submission' => $submission->id]));
        $this->assertSame(0, $DB->count_records(job_manager::TABLE_REFS, ['submission' => $submission->id]));
        $this->assertSame(0, $DB->count_records(text_submission::TABLE, ['submission' => $submission->id]));

        // The other student is untouched.
        $this->assertSame(1, $DB->count_records(job_manager::TABLE_JOB));
        $this->assertSame(1, $DB->count_records(job_manager::TABLE_REFS));
        $this->assertSame(1, $DB->count_records(text_submission::TABLE));
    }

    /**
     * Deleting a set of users' submissions removes exactly those.
     */
    public function test_delete_submissions_removes_only_the_named_ones(): void {
        global $DB;

        $submission = $this->seed_for($this->student, 'Mine');
        $this->seed_for($this->otherstudent, 'Theirs');

        $deletedata = new assign_plugin_request_data($this->assign->get_context(), $this->assign);
        $deletedata->set_userids([$this->student->id]);
        $deletedata->populate_submissions_and_grades();

        provider::delete_submissions($deletedata);

        $this->assertSame(0, $DB->count_records(job_manager::TABLE_JOB, ['submission' => $submission->id]));
        $this->assertSame(0, $DB->count_records(text_submission::TABLE, ['submission' => $submission->id]));
        $this->assertSame(1, $DB->count_records(job_manager::TABLE_JOB));
        $this->assertSame(1, $DB->count_records(job_manager::TABLE_REFS));
        $this->assertSame(1, $DB->count_records(text_submission::TABLE));
    }
}

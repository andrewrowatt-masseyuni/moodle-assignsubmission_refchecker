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
use assignsubmission_refchecker\local\export\access;
use assignsubmission_refchecker\local\export\naming;
use assignsubmission_refchecker\local\export\pdf_report;
use assignsubmission_refchecker\local\export\reference_rows;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\text_submission;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Tests for downloading the reference check report.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\export\access
 * @covers     \assignsubmission_refchecker\local\export\naming
 * @covers     \assignsubmission_refchecker\local\export\pdf_report
 * @covers     \assignsubmission_refchecker\local\export\reference_rows
 */
final class export_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The student who owns the submission. */
    private stdClass $student;

    /** @var stdClass Another student, enrolled on the same course. */
    private stdClass $otherstudent;

    /** @var stdClass The teacher. */
    private stdClass $teacher;

    /** @var assign The assignment. */
    private assign $assign;

    /** @var stdClass The student's submission. */
    private stdClass $submission;

    /**
     * Set up a course with an assignment that has Reference Checker on.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->otherstudent = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $this->assign = $this->create_instance($this->course, [
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_refchecker_enabled' => 1,
        ]);

        $this->submission = $this->assign->get_user_submission($this->student->id, true);

        // Rendering the templates, including the PDF's, needs a page with a context.
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
     * Set the assignment's student display level.
     *
     * @param int $level One of the display_level constants.
     */
    private function set_display_level(int $level): void {
        $this->plugin()->set_config('studentdisplay', $level);
    }

    /**
     * Seed a completed job with a spread of results.
     *
     * @param array $statuses One match status per reference. Defaults to one of each.
     * @return stdClass The job.
     */
    private function seed_job(array $statuses = []): stdClass {
        $statuses = $statuses ?: match_status::all();

        return $this->getDataGenerator()
            ->get_plugin_generator('assignsubmission_refchecker')
            ->create_job_with_references([
                'submission' => $this->submission->id,
                'assignment' => $this->assign->get_instance()->id,
            ], $statuses);
    }

    /**
     * The reference rows for the seeded job.
     *
     * @param bool $isteacher
     * @return reference_rows
     */
    private function rows_for(bool $isteacher): reference_rows {
        $job = $this->seed_job();

        return new reference_rows(job_manager::get_references((int) $job->id), $isteacher);
    }

    /**
     * Whether the current user may export the seeded submission.
     *
     * @return bool
     */
    private function can_export(): bool {
        return access::can_export($this->assign, $this->plugin(), $this->submission);
    }

    // Who may download.

    /**
     * A student whose assignment shows them the full report may download it.
     *
     * This is the requirement: the gate is the display level, not the view capability.
     */
    public function test_student_at_full_level_can_export(): void {
        $this->set_display_level(display_level::FULL);
        $this->setUser($this->student);

        $this->assertTrue($this->can_export());
    }

    /**
     * A student who only gets the summary may not download the references.
     */
    public function test_student_at_summary_level_cannot_export(): void {
        $this->set_display_level(display_level::SUMMARY);
        $this->setUser($this->student);

        $this->assertFalse($this->can_export());
    }

    /**
     * A student who only gets the status line may not download anything.
     */
    public function test_student_at_status_only_cannot_export(): void {
        $this->set_display_level(display_level::STATUS_ONLY);
        $this->setUser($this->student);

        $this->assertFalse($this->can_export());
    }

    /**
     * The capability grants the full report however the assignment is configured.
     */
    public function test_teacher_can_export_at_status_only(): void {
        $this->set_display_level(display_level::STATUS_ONLY);
        $this->setUser($this->teacher);

        $this->assertTrue($this->can_export());
    }

    /**
     * Being entitled to your own full report is not being entitled to somebody else's.
     *
     * This fails if the endpoint checks the display level but forgets to ask the assignment
     * whether the viewer may see the submission at all.
     */
    public function test_another_student_cannot_export_someone_elses_report(): void {
        $this->set_display_level(display_level::FULL);
        $this->setUser($this->otherstudent);

        $this->assertFalse($this->can_export());
    }

    /**
     * Somebody who is not on the course may not download anything.
     */
    public function test_unenrolled_user_cannot_export(): void {
        $this->set_display_level(display_level::FULL);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse($this->can_export());
    }

    /**
     * require_export() throws for a student who is not entitled to the references.
     */
    public function test_require_export_throws_for_a_summary_level_student(): void {
        $this->set_display_level(display_level::SUMMARY);
        $this->setUser($this->student);

        $this->expectException(\required_capability_exception::class);
        access::require_export($this->assign, $this->plugin(), $this->submission);
    }

    /**
     * require_export() returns quietly for someone who is entitled.
     */
    public function test_require_export_passes_for_a_teacher(): void {
        $this->setUser($this->teacher);

        access::require_export($this->assign, $this->plugin(), $this->submission);
        $this->assertTrue(true);
    }

    /**
     * A team submission carries no userid, so the group check has to be the one that runs.
     */
    public function test_group_submission_uses_the_group_check(): void {
        $assign = $this->create_instance($this->course, [
            'assignsubmission_refchecker_enabled' => 1,
            'teamsubmission' => 1,
        ]);
        $plugin = $assign->get_submission_plugin_by_type('refchecker');

        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid' => $this->student->id,
        ]);

        $submission = $assign->get_group_submission($this->student->id, $group->id, true);
        $this->assertEmpty($submission->userid);

        $this->setUser($this->teacher);
        $this->assertTrue(access::can_export($assign, $plugin, $submission));

        // The other student is not in the group, and the assignment does not show students the
        // full report, so both halves of the gate refuse them.
        $this->setUser($this->otherstudent);
        $this->assertFalse(access::can_export($assign, $plugin, $submission));
    }

    // Flattening the references for a spreadsheet.

    /**
     * Every row must carry every column, in order.
     *
     * The spreadsheet writers take values positionally and ignore the keys, so a row that omits
     * one shifts the whole row left underneath correct looking headings.
     */
    public function test_row_keys_match_column_keys(): void {
        $rows = $this->rows_for(true);
        $expected = array_keys($rows->columns());

        $count = 0;
        foreach ($rows->rows() as $row) {
            $this->assertSame($expected, array_keys($row));
            $count++;
        }

        $this->assertSame(count(match_status::all()), $count);
    }

    /**
     * Which database answered, and which file the reference came from, are for teachers only.
     */
    public function test_source_columns_are_teacher_only(): void {
        $teacherrows = $this->rows_for(true);
        $this->assertArrayHasKey('source', $teacherrows->columns());
        $this->assertArrayHasKey('sourcefile', $teacherrows->columns());

        $studentrows = $this->rows_for(false);
        $this->assertArrayNotHasKey('source', $studentrows->columns());
        $this->assertArrayNotHasKey('sourcefile', $studentrows->columns());

        foreach ($studentrows->rows() as $row) {
            $this->assertArrayNotHasKey('source', $row);
            $this->assertArrayNotHasKey('sourcefile', $row);
        }
    }

    /**
     * Flags read as words. A boolean would reach the spreadsheet as TRUE or FALSE.
     */
    public function test_booleans_render_as_yes_and_no(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $job = $generator->create_job([
            'submission' => $this->submission->id,
            'assignment' => $this->assign->get_instance()->id,
        ]);
        $generator->create_reference($job, ['retracted' => 1, 'predatory' => 0]);

        $rows = new reference_rows(job_manager::get_references((int) $job->id), false);
        $row = iterator_to_array($rows->rows(), false)[0];

        $this->assertSame(get_string('yes'), $row['retracted']);
        $this->assertSame(get_string('no'), $row['predatory']);
    }

    /**
     * A not-found reference has no confidence, rather than a confidence of zero.
     */
    public function test_confidence_is_blank_for_not_found(): void {
        $rows = $this->rows_for(true);
        $notfoundlabel = match_status::label(match_status::NOTFOUND);

        $seen = false;
        foreach ($rows->rows() as $row) {
            if ($row['matchstatus'] === $notfoundlabel) {
                $this->assertNull($row['matchconfidence']);
                $seen = true;
            } else {
                $this->assertIsInt($row['matchconfidence']);
            }
        }

        $this->assertTrue($seen, 'The seeded job should contain a not-found reference.');
    }

    /**
     * Numbers stay numbers, so that a spreadsheet sorts and averages them.
     */
    public function test_numeric_columns_stay_numeric(): void {
        $rows = $this->rows_for(false);
        $row = iterator_to_array($rows->rows(), false)[0];

        $this->assertIsInt($row['number']);
        $this->assertIsInt($row['foundyear']);
        $this->assertIsInt($row['citations']);
    }

    /**
     * The report numbers references from one; the stored sort order starts at zero.
     */
    public function test_rows_are_numbered_from_one(): void {
        $rows = $this->rows_for(false);
        $numbers = array_column(iterator_to_array($rows->rows(), false), 'number');

        $this->assertSame(range(1, count(match_status::all())), $numbers);
    }

    /**
     * A JSON column that failed to encode must not take the download down with it.
     */
    public function test_malformed_json_columns_degrade_to_empty(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $job = $generator->create_job([
            'submission' => $this->submission->id,
            'assignment' => $this->assign->get_instance()->id,
        ]);
        $generator->create_reference($job, ['foundauthors' => 'not json at all', 'issues' => '{']);

        $rows = new reference_rows(job_manager::get_references((int) $job->id), false);
        $row = iterator_to_array($rows->rows(), false)[0];

        $this->assertSame('', $row['foundauthors']);
        $this->assertSame('', $row['issues']);
    }

    /**
     * Author lists and issue lists arrive as one cell each.
     */
    public function test_authors_and_issues_are_joined(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $job = $generator->create_job([
            'submission' => $this->submission->id,
            'assignment' => $this->assign->get_instance()->id,
        ]);
        $generator->create_reference($job, [
            'foundauthors' => json_encode(['Ann Author', 'Bo Boffin']),
            'issues' => json_encode(['Year differs', 'Journal differs']),
        ]);

        $rows = new reference_rows(job_manager::get_references((int) $job->id), false);
        $row = iterator_to_array($rows->rows(), false)[0];

        $this->assertSame('Ann Author; Bo Boffin', $row['foundauthors']);
        $this->assertSame("Year differs\nJournal differs", $row['issues']);
    }

    /**
     * The download is the whole reference list, whatever the report is filtered to on screen.
     */
    public function test_every_reference_is_exported(): void {
        $statuses = [
            match_status::VERIFIED,
            match_status::VERIFIED,
            match_status::NOTFOUND,
            match_status::PARTIAL,
            match_status::MISMATCH,
        ];
        $job = $this->seed_job($statuses);
        $rows = new reference_rows(job_manager::get_references((int) $job->id), true);

        $this->assertCount(count($statuses), iterator_to_array($rows->rows(), false));
    }

    // Handing the rows to the spreadsheet writers.

    /**
     * The CSV writer accepts the columns and rows, and the result contains the data.
     *
     * write_data() rather than download_data(): the two share the columns, iterator and callback
     * contract exactly, but this one writes to a request directory instead of standard output and
     * so cannot be tripped by whatever else the test run has buffered.
     *
     * @param string $format
     * @dataProvider spreadsheet_format_provider
     */
    public function test_rows_can_be_written_to_a_spreadsheet(string $format): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('assignsubmission_refchecker');
        $job = $generator->create_job([
            'submission' => $this->submission->id,
            'assignment' => $this->assign->get_instance()->id,
        ]);
        $generator->create_reference($job, ['rawref' => 'DISTINCTIVEREFERENCETEXT']);

        $rows = new reference_rows(job_manager::get_references((int) $job->id), true);
        $path = \core\dataformat::write_data('report', $format, $rows->columns(), $rows->rows());

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        if ($format === 'csv') {
            $csv = file_get_contents($path);
            $this->assertStringContainsString('DISTINCTIVEREFERENCETEXT', $csv);
            $this->assertStringContainsString(
                get_string('report_doi', 'assignsubmission_refchecker'),
                $csv,
            );
        }
    }

    /**
     * The spreadsheet formats offered.
     *
     * @return array<string, array{string}>
     */
    public static function spreadsheet_format_provider(): array {
        return [
            'csv' => ['csv'],
            'excel' => ['excel'],
        ];
    }

    // The PDF.

    /**
     * A document is produced, and it is a PDF.
     */
    public function test_pdf_is_generated(): void {
        $this->setUser($this->teacher);
        $job = $this->seed_job();

        $bytes = (new pdf_report(
            $this->assign,
            $this->submission,
            $job,
            job_manager::get_references((int) $job->id),
            true,
        ))->to_string();

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    /**
     * A reference list long enough to span pages still produces one sound document.
     *
     * Keeping references off page boundaries means writing each one speculatively and rolling the
     * document back when it spills, so this exercises the rollback path repeatedly. A rollback
     * that restored the wrong object, or left the transaction copy behind, shows up here as a
     * truncated or unopenable file rather than as a wrong page count.
     *
     * Whether a block actually straddles a break can only be judged by eye; what this pins is that
     * the mechanism for avoiding it does not corrupt the document.
     */
    public function test_pdf_spanning_several_pages_is_sound(): void {
        $this->setUser($this->teacher);
        // Enough to spill onto a third page, and no more: each block is written inside a
        // transaction, and a transaction clones the whole document.
        $job = $this->seed_job(array_fill(0, 20, match_status::PARTIAL));
        $pdf = new pdf_report(
            $this->assign,
            $this->submission,
            $job,
            job_manager::get_references((int) $job->id),
            true,
        );

        $document = $pdf->build();
        $this->assertGreaterThan(1, $document->getNumPages());

        $bytes = (string) $document->Output('report.pdf', 'S');
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
    }

    /**
     * A report where nothing matched has null everywhere, which is where a designed layout breaks.
     */
    public function test_pdf_survives_a_report_with_no_matches(): void {
        $this->setUser($this->teacher);
        $job = $this->seed_job([match_status::NOTFOUND, match_status::NOTFOUND]);

        $bytes = (new pdf_report(
            $this->assign,
            $this->submission,
            $job,
            job_manager::get_references((int) $job->id),
            true,
        ))->to_string();

        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    /**
     * The student's document carries no operational detail.
     *
     * Asserted on the template context rather than the bytes: a compressed PDF stream cannot be
     * searched reliably, and the context is where the decision is actually made.
     */
    public function test_pdf_context_omits_teacher_data_for_a_student(): void {
        $job = $this->seed_job([match_status::VERIFIED]);
        $references = job_manager::get_references((int) $job->id);
        $reference = reset($references);
        $sourcelabel = get_string('report_source', 'assignsubmission_refchecker');

        $forteacher = new pdf_report($this->assign, $this->submission, $job, $references, true);
        $teacherlabels = array_column($forteacher->reference_context($reference)['details'], 'label');
        $this->assertContains($sourcelabel, $teacherlabels);

        $forstudent = new pdf_report($this->assign, $this->submission, $job, $references, false);
        $studentlabels = array_column($forstudent->reference_context($reference)['details'], 'label');
        $this->assertNotContains($sourcelabel, $studentlabels);
    }

    /**
     * The cover names the assignment and whoever the report is about.
     */
    public function test_pdf_header_context_identifies_the_submission(): void {
        $job = $this->seed_job();
        $context = (new pdf_report(
            $this->assign,
            $this->submission,
            $job,
            job_manager::get_references((int) $job->id),
            true,
        ))->header_context();

        $this->assertSame($this->assign->get_instance()->name, $context['assignment']);
        $this->assertSame(fullname($this->student), $context['subject']);
        $this->assertSame(get_string('export_pdf_student', 'assignsubmission_refchecker'), $context['subjectlabel']);
    }

    // Naming the file.

    /**
     * The filename says which assignment and which student, and carries no extension.
     */
    public function test_filename_names_the_assignment_and_student(): void {
        $filename = naming::filename($this->assign, $this->submission);

        $this->assertStringContainsString($this->assign->get_instance()->name, $filename);
        $this->assertStringContainsString(fullname($this->student), $filename);
        $this->assertStringNotContainsString('.pdf', $filename);
    }

    /**
     * Blind marking hides the student's identity from the download too.
     */
    public function test_blind_marking_suppresses_the_student_name(): void {
        $assign = $this->create_instance($this->course, [
            'assignsubmission_refchecker_enabled' => 1,
            'blindmarking' => 1,
        ]);
        $submission = $assign->get_user_submission($this->student->id, true);

        $subject = naming::subject($assign, $submission);

        $this->assertStringNotContainsString(fullname($this->student), $subject);
        $this->assertStringContainsString(get_string('participant', 'assign'), $subject);
    }

    /**
     * A team submission is named after the group.
     */
    public function test_group_submission_is_named_after_the_group(): void {
        $assign = $this->create_instance($this->course, [
            'assignsubmission_refchecker_enabled' => 1,
            'teamsubmission' => 1,
        ]);
        $group = $this->getDataGenerator()->create_group([
            'courseid' => $this->course->id,
            'name' => 'The Reference Wranglers',
        ]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid' => $this->student->id,
        ]);
        $submission = $assign->get_group_submission($this->student->id, $group->id, true);

        $this->assertSame('The Reference Wranglers', naming::subject($assign, $submission));
        $this->assertSame(
            get_string('export_pdf_group', 'assignsubmission_refchecker'),
            naming::subject_label($submission),
        );
    }

    // The controls on the report itself.

    /**
     * A student who can see the full report is offered the downloads.
     */
    public function test_report_offers_downloads_at_full_level(): void {
        $this->set_display_level(display_level::FULL);
        $this->seed_job();
        $this->setUser($this->student);

        $html = $this->plugin()->view($this->submission);

        $this->assertStringContainsString('export.php', $html);
        $this->assertStringContainsString('format=pdf', $html);
        $this->assertStringContainsString('format=excel', $html);
        $this->assertStringContainsString('format=csv', $html);
    }

    /**
     * A student who only gets the summary is not offered them.
     */
    public function test_report_hides_downloads_at_summary_level(): void {
        $this->set_display_level(display_level::SUMMARY);
        $this->seed_job();
        $this->setUser($this->student);

        $html = $this->plugin()->view($this->submission);

        $this->assertStringNotContainsString('export.php', $html);
    }

    /**
     * The links carry their own submission id, so that several expanded reports on one grading
     * page do not download each other's data.
     */
    public function test_download_urls_are_scoped_to_the_submission(): void {
        $this->seed_job();
        $this->setUser($this->teacher);

        $html = $this->plugin()->view($this->submission);

        $this->assertStringContainsString('id=' . $this->submission->id . '&amp;format=pdf', $html);
    }
}

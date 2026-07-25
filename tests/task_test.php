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
use assignsubmission_refchecker\local\exception\transient_exception;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\task\check_references;
use assignsubmission_refchecker\task\extract_references;
use core\task\adhoc_task;
use GuzzleHttp\Psr7\Response;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Tests for the background tasks that extract and check references.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\task\extract_references
 * @covers     \assignsubmission_refchecker\task\check_references
 * @covers     \assignsubmission_refchecker\local\job_manager
 */
final class task_test extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The student. */
    private stdClass $student;

    /** @var assign The assignment. */
    private assign $assign;

    /** @var stdClass The student's submission. */
    private stdClass $submission;

    /** @var array The request and response history of the mocked client. */
    private array $history = [];

    /**
     * Set up an assignment with a submission.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->assign = $this->create_instance($this->course, [
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_refchecker_enabled' => 1,
        ]);

        $this->submission = $this->assign->get_user_submission($this->student->id, true);

        // Plain text needs neither pdftotext nor a document converter, so these tests exercise the
        // pipeline without depending on anything installed on the machine running them.
        set_config('supportedtypes', 'txt', 'assignsubmission_refchecker');
        set_config('sources', 'crossref', 'assignsubmission_refchecker');
        set_config('chunksize', 2, 'assignsubmission_refchecker');

        job_manager::reset_caches();
    }

    /**
     * Attach a plain text file containing a reference list to the submission.
     *
     * @param int $references How many references to write.
     * @return void
     */
    private function attach_submission_file(int $references = 3): void {
        $lines = ["An essay about things.", "", "References", ""];
        for ($i = 1; $i <= $references; $i++) {
            $lines[] = "Author{$i}, A. (201{$i}). The study of subject number {$i} in context. "
                . "Journal of Things, {$i}(1), 1-10.";
            $lines[] = '';
        }

        get_file_storage()->create_file_from_string([
            'contextid' => $this->assign->get_context()->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $this->submission->id,
            'filepath' => '/',
            'filename' => 'essay.txt',
        ], implode("\n", $lines));
    }

    /**
     * Start a job for the submission.
     *
     * @return stdClass
     */
    private function start_job(): stdClass {
        return job_manager::reset_for_submission($this->submission);
    }

    /**
     * Build a task of the given class for a job.
     *
     * @param string $classname
     * @param stdClass $job
     * @param int|null $generation Override the generation, to simulate a superseded task.
     * @return adhoc_task
     */
    private function task(string $classname, stdClass $job, ?int $generation = null): adhoc_task {
        $task = new $classname();
        $task->set_custom_data([
            'submissionid' => (int) $job->submission,
            'generation' => $generation ?? (int) $job->generation,
        ]);
        $task->set_component('assignsubmission_refchecker');

        return $task;
    }

    /**
     * Run a task, discarding its progress output.
     *
     * @param adhoc_task $task
     * @return void
     */
    private function run_task(adhoc_task $task): void {
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Queue the given HTTP responses.
     *
     * @param Response[] $responses
     * @return void
     */
    private function mock_responses(array $responses): void {
        $this->history = [];
        $mocked = $this->get_mocked_http_client($this->history);
        foreach ($responses as $response) {
            $mocked['mock']->append($response);
        }
    }

    /**
     * A CrossRef response that matches whatever it is asked about.
     *
     * @param string $title
     * @return Response
     */
    private static function crossref_hit(string $title): Response {
        return new Response(200, [], json_encode(['message' => ['items' => [[
            'DOI' => '10.5555/' . md5($title),
            'title' => [$title],
            'author' => [['given' => 'A', 'family' => 'Author1']],
            'container-title' => ['Journal of Things'],
            'issued' => ['date-parts' => [[2011]]],
            'is-referenced-by-count' => 5,
        ]]]]));
    }

    /**
     * A submission with nothing readable in it is closed off, not left pending.
     */
    public function test_extract_with_no_files_is_not_applicable(): void {
        $job = $this->start_job();

        $this->run_task($this->task(extract_references::class, $job));

        $this->assertSame(job_status::NOTAPPLICABLE, job_manager::load((int) $this->submission->id)->status);
    }

    /**
     * A superseded task abandons its work without touching the job.
     *
     * This is the guard that stops a student who edits their submission mid-check from being
     * shown results describing the files they replaced.
     */
    public function test_superseded_task_does_nothing(): void {
        $this->attach_submission_file();
        $job = $this->start_job();

        // Pretend the student resubmitted after this task was queued.
        $this->run_task($this->task(extract_references::class, $job, (int) $job->generation - 1));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::PENDING, $reloaded->status);
        $this->assertSame(0, (int) $reloaded->totalrefs);
    }

    /**
     * Extraction stores one row per reference and moves the job to checking.
     */
    public function test_extract_stores_references(): void {
        global $DB;

        $this->attach_submission_file(3);
        $job = $this->start_job();

        $this->run_task($this->task(extract_references::class, $job));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::CHECKING, $reloaded->status);
        $this->assertSame(3, (int) $reloaded->totalrefs);
        $this->assertSame(0, (int) $reloaded->checkedrefs);
        $this->assertSame('References', $reloaded->sectionheading);
        $this->assertSame(3, $DB->count_records(job_manager::TABLE_REFS, ['jobid' => $reloaded->id]));

        // And it queued the checking task.
        $this->assertSame(
            1,
            $DB->count_records('task_adhoc', ['classname' => '\\' . check_references::class]),
        );
    }

    /**
     * A file with no recognisable reference list produces a distinct state.
     *
     * "No references found" and "everything checked out" must never look the same to a student.
     */
    public function test_extract_with_no_reference_list(): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $this->assign->get_context()->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $this->submission->id,
            'filepath' => '/',
            'filename' => 'essay.txt',
        ], str_repeat("Just prose with no reference list anywhere in it at all. ", 30));

        $job = $this->start_job();
        $this->run_task($this->task(extract_references::class, $job));

        $this->assertSame(job_status::NOREFS, job_manager::load((int) $this->submission->id)->status);
    }

    /**
     * The reference cap is honoured and the truncation recorded.
     */
    public function test_extract_truncates_at_the_cap(): void {
        $this->attach_submission_file(5);
        $this->assign->get_submission_plugin_by_type('refchecker')->set_config('maxreferences', 2);

        $job = $this->start_job();
        $this->run_task($this->task(extract_references::class, $job));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(2, (int) $reloaded->totalrefs);
        $this->assertSame(1, (int) $reloaded->truncated);
    }

    /**
     * References already in the shared cache complete the job without any external request.
     *
     * This is the main protection against rate limits at cohort scale, so it is worth proving
     * that a cache hit really does mean no HTTP.
     */
    public function test_cached_references_need_no_requests(): void {
        $this->attach_submission_file(2);
        $job = $this->start_job();

        // Seed the cache with the exact references the file contains.
        foreach ([1, 2] as $i) {
            $raw = "Author{$i}, A. (201{$i}). The study of subject number {$i} in context. "
                . "Journal of Things, {$i}(1), 1-10.";
            job_manager::cache_put(job_manager::reference_hash($raw), [
                'matchstatus' => match_status::VERIFIED,
                'confidence' => 95,
                'titlescore' => 98,
                'authorscore' => 100,
                'journalscore' => 90,
                'issues' => [],
                'source' => 'crossref',
                'record' => [
                    'title' => "The study of subject number {$i} in context",
                    'authors' => ['A Author' . $i],
                    'journal' => 'Journal of Things',
                    'year' => 2010 + $i,
                    'doi' => '10.5555/cached' . $i,
                    'url' => '',
                    'citations' => 3,
                    'retracted' => false,
                ],
            ]);
        }

        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::COMPLETE, $reloaded->status);
        $this->assertSame(2, (int) $reloaded->verifiedrefs);
        $this->assertSame([], $this->history, 'A cache hit must not produce an HTTP request.');
    }

    /**
     * Checking works through the references a chunk at a time, queueing itself again.
     */
    public function test_check_processes_a_chunk_and_requeues(): void {
        global $DB;

        $this->attach_submission_file(3);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $DB->delete_records('task_adhoc');
        $job = job_manager::load((int) $this->submission->id);

        // Chunk size is 2, so the first run leaves one reference outstanding.
        $this->mock_responses([
            self::crossref_hit('The study of subject number 1 in context'),
            self::crossref_hit('The study of subject number 2 in context'),
        ]);
        $this->run_task($this->task(check_references::class, $job));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(2, (int) $reloaded->checkedrefs);
        $this->assertSame(job_status::CHECKING, $reloaded->status);
        $this->assertSame(
            1,
            $DB->count_records('task_adhoc', ['classname' => '\\' . check_references::class]),
        );
    }

    /**
     * Running to exhaustion completes the job and records the outcome.
     */
    public function test_check_completes_the_job(): void {
        $this->attach_submission_file(2);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([
            self::crossref_hit('The study of subject number 1 in context'),
            self::crossref_hit('The study of subject number 2 in context'),
        ]);
        $this->run_task($this->task(check_references::class, $job));

        // A second run finds nothing queued and closes the job.
        $job = job_manager::load((int) $this->submission->id);
        $this->run_task($this->task(check_references::class, $job));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::COMPLETE, $reloaded->status);
        $this->assertSame(2, (int) $reloaded->checkedrefs);
        $this->assertSame(2, (int) $reloaded->verifiedrefs);
        $this->assertGreaterThan(0, (int) $reloaded->timecompleted);
    }

    /**
     * Being rate limited reschedules rather than throwing.
     *
     * Throwing would work, but it would also inflate Moodle's fail delay for something the
     * service told us was routine and told us exactly how long to wait for.
     */
    public function test_rate_limiting_reschedules_without_throwing(): void {
        global $DB;

        $this->attach_submission_file(2);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $DB->delete_records('task_adhoc');
        $job = job_manager::load((int) $this->submission->id);

        $this->mock_responses([new Response(429, ['Retry-After' => '120'], '')]);
        $this->run_task($this->task(check_references::class, $job));

        $queued = $DB->get_record('task_adhoc', ['classname' => '\\' . check_references::class]);
        $this->assertNotFalse($queued, 'The task should have rescheduled itself.');
        $this->assertGreaterThanOrEqual(time() + 100, (int) $queued->nextruntime);

        // The job is untouched and still checking, not failed.
        $this->assertSame(job_status::CHECKING, job_manager::load((int) $this->submission->id)->status);
    }

    /**
     * A server side fault propagates, so the task API applies its backoff.
     */
    public function test_server_error_propagates(): void {
        $this->attach_submission_file(1);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([new Response(503, [], '')]);

        $this->expectException(transient_exception::class);
        $this->task(check_references::class, $job)->execute();
    }

    /**
     * Work already done survives a failure part way through.
     */
    public function test_progress_survives_a_failure(): void {
        $this->attach_submission_file(3);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([
            self::crossref_hit('The study of subject number 1 in context'),
            self::crossref_hit('The study of subject number 2 in context'),
        ]);
        $this->run_task($this->task(check_references::class, $job));

        $this->assertSame(2, (int) job_manager::load((int) $this->submission->id)->checkedrefs);

        // The next run fails outright, but must not undo what has already been recorded.
        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([new Response(503, [], '')]);
        try {
            $this->task(check_references::class, $job)->execute();
            $this->fail('Expected the failing chunk to throw.');
        } catch (transient_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(2, (int) job_manager::load((int) $this->submission->id)->checkedrefs);
    }

    /**
     * An unchanged resubmission is not checked again.
     */
    public function test_unchanged_resubmission_is_skipped(): void {
        global $DB;

        $this->attach_submission_file(2);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([
            self::crossref_hit('The study of subject number 1 in context'),
            self::crossref_hit('The study of subject number 2 in context'),
        ]);
        $this->run_task($this->task(check_references::class, $job));
        $job = job_manager::load((int) $this->submission->id);
        $this->run_task($this->task(check_references::class, $job));

        $contenthash = job_manager::load((int) $this->submission->id)->contenthash;

        // Resubmit without changing anything.
        $job = job_manager::reset_for_submission($this->submission);
        $job->contenthash = $contenthash;
        $job->totalrefs = 2;
        $DB->update_record(job_manager::TABLE_JOB, $job);

        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $this->assertSame(job_status::COMPLETE, job_manager::load((int) $this->submission->id)->status);
        $this->assertSame([], $this->history);
    }
}

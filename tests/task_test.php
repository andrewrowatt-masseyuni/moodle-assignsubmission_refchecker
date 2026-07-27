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
use assignsubmission_refchecker\local\rate_limiter;
use assignsubmission_refchecker\local\text_mode;
use assignsubmission_refchecker\local\text_submission;
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
            // Function save_settings() on the file plugin reads both of these straight off the form
            // data, so enabling it without them is an undefined property warning, which
            // PHPUnit turns into a failure before the test body ever runs.
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'assignsubmission_refchecker_enabled' => 1,
        ]);

        $this->submission = $this->assign->get_user_submission($this->student->id, true);

        // Plain text needs neither pdftotext nor a document converter, so these tests exercise the
        // pipeline without depending on anything installed on the machine running them.
        set_config('supportedtypes', 'txt', 'assignsubmission_refchecker');
        set_config('sources', 'crossref', 'assignsubmission_refchecker');
        set_config('chunksize', 2, 'assignsubmission_refchecker');

        job_manager::reset_caches();
        text_submission::reset_caches();
    }

    /**
     * Attach a plain text file containing a reference list to the submission.
     *
     * The body is padded out because extractor::extract() reports anything shorter than
     * MIN_USEFUL_CHARS as having no text layer, the way a scanned PDF does. A real essay is
     * comfortably over that; a one line stand-in is not, and every reference count below five
     * would otherwise be skipped before the parser ever saw it.
     *
     * @param int $references How many references to write.
     * @return void
     */
    private function attach_submission_file(int $references = 3): void {
        $body = str_repeat(
            'This paragraph stands in for the body of the essay, which the parser steps over on '
            . 'its way to the reference list. ',
            5,
        );

        $lines = [$body, "", "References", ""];
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
     * Ask this assignment for a pasted reference list.
     *
     * @param string $mode One of the text_mode constants.
     * @return void
     */
    private function set_text_mode(string $mode): void {
        $this->assign->get_submission_plugin_by_type('refchecker')->set_config('requiretext', $mode);
    }

    /**
     * Store a pasted reference list against the submission, in the shape a student would type.
     *
     * Deliberately with no "References" heading, which is the normal case for a pasted list and the
     * one the file based parser would reject.
     *
     * @param int $references How many references to write. Zero stores an empty box.
     * @return void
     */
    private function paste_reference_list(int $references = 3): void {
        $lines = [];
        for ($i = 1; $i <= $references; $i++) {
            $lines[] = "Author{$i}, A. (201{$i}). The study of subject number {$i} in context. "
                . "Journal of Things, {$i}(1), 1-10.";
        }

        text_submission::save(
            (int) $this->assign->get_instance()->id,
            (int) $this->submission->id,
            implode("\n", $lines),
        );
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
     * The author and year track the numbered reference the title belongs to. Answering for
     * reference 2 in reference 1's name is not a match the plugin should accept: the matcher
     * scores the author surname, so it would come back partial rather than verified.
     *
     * @param string $title
     * @return Response
     */
    private static function crossref_hit(string $title): Response {
        $n = preg_match('/number (\d+)/', $title, $matches) ? (int) $matches[1] : 1;

        return new Response(200, [], json_encode(['message' => ['items' => [[
            'DOI' => '10.5555/' . md5($title),
            'title' => [$title],
            'author' => [['given' => 'A', 'family' => 'Author' . $n]],
            'container-title' => ['Journal of Things'],
            'issued' => ['date-parts' => [[2010 + $n]]],
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
     * A pasted reference list is used, and the uploaded file is not read at all.
     *
     * The file here holds five references and the pasted list three, so the count alone proves
     * which source was used.
     *
     * @dataProvider text_source_provider
     * @param string $mode The requiretext setting.
     */
    public function test_extract_prefers_the_pasted_list(string $mode): void {
        $this->set_text_mode($mode);
        $this->attach_submission_file(5);
        $this->paste_reference_list(3);

        $job = $this->start_job();
        $this->run_task($this->task(extract_references::class, $job));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::CHECKING, $reloaded->status);
        $this->assertSame(3, (int) $reloaded->totalrefs);
        // A pasted list has no document section, so there is no heading to report back.
        $this->assertNull($reloaded->sectionheading);

        foreach (job_manager::get_references((int) $reloaded->id) as $reference) {
            $this->assertNull($reference->sourcefile);
        }
    }

    /**
     * Data provider over the two modes that offer a References box.
     *
     * @return array[]
     */
    public static function text_source_provider(): array {
        return [
            'optional text box' => [text_mode::OPTIONAL],
            'required text box' => [text_mode::REQUIRED],
        ];
    }

    /**
     * A pasted list with no heading is still read, which is the point of the whole mode.
     */
    public function test_extract_reads_a_pasted_list_with_no_heading(): void {
        $this->set_text_mode(text_mode::REQUIRED);
        $this->paste_reference_list(3);

        $job = $this->start_job();
        $this->run_task($this->task(extract_references::class, $job));

        $this->assertSame(3, (int) job_manager::load((int) $this->submission->id)->totalrefs);
    }

    /**
     * Optional mode falls back to the uploaded file when the box was left empty.
     */
    public function test_extract_falls_back_to_the_file_in_optional_mode(): void {
        $this->set_text_mode(text_mode::OPTIONAL);
        $this->attach_submission_file(5);
        $this->paste_reference_list(0);

        $job = $this->start_job();
        $this->run_task($this->task(extract_references::class, $job));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(5, (int) $reloaded->totalrefs);
        $this->assertSame('References', $reloaded->sectionheading);
    }

    /**
     * A text mode assignment with an empty box and no file has nothing to do.
     */
    public function test_extract_with_no_text_and_no_file_is_not_applicable(): void {
        $this->set_text_mode(text_mode::REQUIRED);

        $job = $this->start_job();
        $this->run_task($this->task(extract_references::class, $job));

        $this->assertSame(
            job_status::NOTAPPLICABLE,
            job_manager::load((int) $this->submission->id)->status,
        );
    }

    /**
     * Text that has not changed since the last check is not checked again.
     */
    public function test_extract_skips_an_unchanged_pasted_list(): void {
        $this->set_text_mode(text_mode::REQUIRED);
        $this->paste_reference_list(2);

        $job = $this->start_job();
        $this->mock_responses([
            self::crossref_hit('The study of subject number 1 in context'),
            self::crossref_hit('The study of subject number 2 in context'),
        ]);
        $this->run_task($this->task(extract_references::class, $job));
        // The chunk that empties the queue still requeues; the run after it is the one that finds
        // nothing outstanding and closes the job.
        $this->run_task($this->task(check_references::class, job_manager::load((int) $this->submission->id)));
        $this->run_task($this->task(check_references::class, job_manager::load((int) $this->submission->id)));

        $this->assertSame(job_status::COMPLETE, job_manager::load((int) $this->submission->id)->status);

        // Re-saving the same text starts a new generation but must not re-extract.
        $requeued = $this->start_job();
        $this->run_task($this->task(extract_references::class, $requeued));

        $reloaded = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::COMPLETE, $reloaded->status);
        $this->assertSame(2, (int) $reloaded->totalrefs);
    }

    /**
     * The reference cap applies to a pasted list too.
     */
    public function test_extract_truncates_a_pasted_list_at_the_cap(): void {
        $this->set_text_mode(text_mode::REQUIRED);
        $this->paste_reference_list(5);
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
     * A database refusing a request does not stop the task.
     *
     * A 429 from these services is not reliably about our request rate: Semantic Scholar answers
     * one while overloaded even to a keyed caller pacing itself well inside the allowance. So it is
     * treated as that source being unwell and handled where every other source failure is, against
     * the reference, whose attempt budget bounds it.
     */
    public function test_a_refused_request_does_not_pause_the_task(): void {
        global $DB;

        $this->attach_submission_file(2);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $DB->delete_records('task_adhoc');
        $job = job_manager::load((int) $this->submission->id);

        // One response is enough: the refusal stands crossref down, so the second reference finds
        // it already skipped rather than asking again.
        $this->mock_responses([new Response(429, ['Retry-After' => '120'], '')]);
        $this->run_task($this->task(check_references::class, $job));

        // Requeued for the ordinary inter-chunk delay, not parked for the two minutes the service
        // named. Nothing about one database being unwell justifies stalling the whole submission.
        $queued = $DB->get_record('task_adhoc', ['classname' => '\\' . check_references::class]);
        $this->assertNotFalse($queued, 'The task should have rescheduled itself.');
        $this->assertLessThan(time() + 100, (int) $queued->nextruntime);

        $job = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::CHECKING, $job->status);
        $this->assertSame(0, (int) $job->deferrals, 'A refusal is not the pacer, so nothing was deferred.');

        foreach (job_manager::get_references((int) $job->id) as $reference) {
            $this->assertSame(1, (int) $reference->attempts);
            $this->assertSame(job_status::REF_QUEUED, $reference->status);
        }
    }

    /**
     * A source that refuses every time still lets the job finish.
     *
     * The regression this guards is a submission stuck at "23 of 26" indefinitely: a refusal that
     * pauses the whole task spends no attempt and refreshes the job's timemodified on its way past,
     * so nothing in the plugin can see that it has stopped making progress and it reschedules
     * itself every minute forever.
     */
    public function test_a_persistently_refused_source_still_finishes_the_job(): void {
        global $DB;

        $this->attach_submission_file(1);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([new Response(429, [], '')]);

        // The stand-down means only the first run gets as far as a request; the rest skip crossref
        // and record the same "nothing could be consulted" outcome without one.
        for ($attempt = 1; $attempt <= job_manager::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            $this->run_task($this->task(check_references::class, job_manager::load((int) $this->submission->id)));

            $reference = $DB->get_record(job_manager::TABLE_REFS, ['jobid' => $job->id]);
            $this->assertSame($attempt, (int) $reference->attempts);

            $DB->set_field(
                job_manager::TABLE_REFS,
                'timechecked',
                time() - job_manager::RETRY_DELAY - 1,
                ['id' => $reference->id],
            );
        }

        // Out of attempts, so the reference is settled rather than tried a fourth time.
        $reference = $DB->get_record(job_manager::TABLE_REFS, ['jobid' => $job->id]);
        $this->assertSame(job_status::REF_ERROR, $reference->status);
        $this->assertSame(match_status::NOTFOUND, $reference->matchstatus);

        // And the next run closes the job, which is the part that never happened before.
        $this->run_task($this->task(check_references::class, job_manager::load((int) $this->submission->id)));
        $this->assertSame(job_status::COMPLETE, job_manager::load((int) $this->submission->id)->status);
    }

    /**
     * The pacer declining to send anything pauses the task and leaves the references alone.
     *
     * The one case that is genuinely about our own request rate. Nothing was sent, so no reference
     * may spend an attempt or be given an error to show: three of those and it is reported to the
     * student as not found, with an internal message underneath it.
     */
    public function test_the_pacer_pauses_without_touching_the_references(): void {
        global $DB;

        $this->attach_submission_file(2);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $DB->delete_records('task_adhoc');

        // Claim crossref's next slot and put the one after it far enough out that the pacer hands
        // the wait back instead of sleeping through it.
        set_config('rateinterval_crossref', 60000, 'assignsubmission_refchecker');
        rate_limiter::throttle('crossref');

        $this->run_task($this->task(check_references::class, job_manager::load((int) $this->submission->id)));

        $queued = $DB->get_record('task_adhoc', ['classname' => '\\' . check_references::class]);
        $this->assertNotFalse($queued, 'The task should have rescheduled itself.');
        $this->assertGreaterThan(time() + 30, (int) $queued->nextruntime);

        $job = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::CHECKING, $job->status);
        $this->assertSame(1, (int) $job->deferrals);

        foreach (job_manager::get_references((int) $job->id) as $reference) {
            $this->assertSame(0, (int) $reference->attempts, 'A pause must not spend an attempt.');
            $this->assertNull($reference->errormessage, 'A pause must not be recorded against a reference.');
            $this->assertSame(job_status::REF_QUEUED, $reference->status);
        }
    }

    /**
     * A pacer that never lets a run through fails the job rather than looping forever.
     *
     * Pausing spends no attempt and leaves the job looking freshly modified, so this counter is the
     * only thing standing between a misconfigured interval and a submission that reschedules itself
     * indefinitely.
     */
    public function test_repeated_pauses_eventually_fail_the_job(): void {
        global $DB;

        $this->attach_submission_file(1);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $DB->delete_records('task_adhoc');

        set_config('rateinterval_crossref', 60000, 'assignsubmission_refchecker');
        rate_limiter::throttle('crossref');

        // Start one short of the limit rather than running the task twenty times over.
        $DB->set_field(
            job_manager::TABLE_JOB,
            'deferrals',
            check_references::MAX_CONSECUTIVE_DEFERRALS - 1,
            ['id' => $job->id],
        );
        job_manager::reset_caches();

        $this->run_task($this->task(check_references::class, job_manager::load((int) $this->submission->id)));

        $job = job_manager::load((int) $this->submission->id);
        $this->assertSame(job_status::FAILED, $job->status);
        $this->assertSame('pacerstalled', $job->errorcode);
        $this->assertFalse(
            $DB->record_exists('task_adhoc', ['classname' => '\\' . check_references::class]),
            'A failed job must not leave a successor queued.',
        );
    }

    /**
     * A run that gets through clears the pauses before it.
     *
     * Otherwise a job that paused occasionally over a long submission would accumulate its way to
     * the limit and be failed for something that was only ever a delay.
     */
    public function test_a_successful_chunk_clears_the_deferral_count(): void {
        global $DB;

        $this->attach_submission_file(2);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $DB->set_field(job_manager::TABLE_JOB, 'deferrals', 5, ['id' => $job->id]);
        job_manager::reset_caches();

        $this->mock_responses([
            self::crossref_hit('The study of subject number 1 in context'),
            self::crossref_hit('The study of subject number 2 in context'),
        ]);
        $this->run_task($this->task(check_references::class, job_manager::load((int) $this->submission->id)));

        $this->assertSame(0, (int) job_manager::load((int) $this->submission->id)->deferrals);
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
     * A flaky database no longer fails the whole chunk.
     *
     * The behaviour seen in production: a reference no database indexes, plus DBLP returning 500s,
     * used to fail the task, back off, retry twelve times and eventually mark the entire
     * submission as failed. It must now leave the task healthy.
     */
    public function test_a_flaky_source_does_not_fail_the_task(): void {
        set_config('sources', 'crossref,dblp', 'assignsubmission_refchecker');

        $this->attach_submission_file(1);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([
            new Response(200, [], json_encode(['message' => ['items' => []]])),
            new Response(500, [], '<html>error</html>'),
        ]);

        // The key assertion is simply that this does not throw.
        $this->run_task($this->task(check_references::class, $job));

        $this->assertSame(job_status::CHECKING, job_manager::load((int) $this->submission->id)->status);
    }

    /**
     * A result reached while a database was down is never written to the shared cache.
     *
     * The cache is read by every submission on the site, so caching a "not found" that was reached
     * with a source missing would teach every later student that a real work does not exist.
     */
    public function test_a_degraded_result_is_not_cached(): void {
        global $DB;

        set_config('sources', 'crossref,dblp', 'assignsubmission_refchecker');

        $this->attach_submission_file(1);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([
            new Response(200, [], json_encode(['message' => ['items' => []]])),
            new Response(500, [], ''),
        ]);
        $this->run_task($this->task(check_references::class, $job));

        $this->assertSame(0, $DB->count_records(job_manager::TABLE_CACHE));
    }

    /**
     * A reference that could not be resolved cleanly is retried, then settled.
     *
     * It must not go round forever, and it must not be picked straight back up within the same
     * second either, or the whole attempt budget would be spent before the database had any chance
     * to recover.
     */
    public function test_a_degraded_reference_is_retried_then_settled(): void {
        global $DB;

        set_config('sources', 'crossref,dblp', 'assignsubmission_refchecker');

        $this->attach_submission_file(1);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([
            new Response(200, [], json_encode(['message' => ['items' => []]])),
            new Response(500, [], ''),
        ]);
        $this->run_task($this->task(check_references::class, $job));

        $reference = $DB->get_record(job_manager::TABLE_REFS, ['jobid' => $job->id]);
        $this->assertSame(job_status::REF_QUEUED, $reference->status);
        $this->assertSame(1, (int) $reference->attempts);

        // Held back rather than retried immediately.
        $this->assertSame([], job_manager::next_queued_references((int) $job->id, 10));

        // Once the delay has passed it becomes eligible again.
        $DB->set_field(
            job_manager::TABLE_REFS,
            'timechecked',
            time() - job_manager::RETRY_DELAY - 1,
            ['id' => $reference->id],
        );
        $this->assertCount(1, job_manager::next_queued_references((int) $job->id, 10));
    }

    /**
     * A job whose references are all waiting on a retry is not reported as finished.
     */
    public function test_waiting_references_do_not_complete_the_job(): void {
        set_config('sources', 'crossref,dblp', 'assignsubmission_refchecker');

        $this->attach_submission_file(1);
        $job = $this->start_job();
        $this->mock_responses([]);
        $this->run_task($this->task(extract_references::class, $job));

        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([
            new Response(200, [], json_encode(['message' => ['items' => []]])),
            new Response(500, [], ''),
        ]);
        $this->run_task($this->task(check_references::class, $job));

        // The reference is now held back, so the next run sees an empty batch.
        $job = job_manager::load((int) $this->submission->id);
        $this->mock_responses([]);
        $this->run_task($this->task(check_references::class, $job));

        $this->assertSame(
            job_status::CHECKING,
            job_manager::load((int) $this->submission->id)->status,
            'A job with references still queued must not be reported complete.',
        );
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

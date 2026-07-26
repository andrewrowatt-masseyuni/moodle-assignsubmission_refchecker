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

namespace assignsubmission_refchecker\local;

use core_text;
use stdClass;

/**
 * All reads and writes of the reference checker's own tables go through here.
 *
 * Centralising them is what keeps the generation invariant enforceable. Every job carries a
 * generation number that is incremented whenever a submission is re-checked; background tasks
 * carry the generation they were queued with and abandon their work the moment the two disagree.
 * Without that, a student editing a submission while a check is in flight would end up with
 * results describing the files they replaced.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_manager {
    /** @var string The job table. */
    public const TABLE_JOB = 'assignsubmission_refchecker';

    /** @var string The per-reference table. */
    public const TABLE_REFS = 'assignsubmission_refchecker_refs';

    /** @var string The shared lookup cache table. */
    public const TABLE_CACHE = 'assignsubmission_refchecker_cache';

    /** @var int Give up on a single reference after this many failed attempts. */
    public const MAX_REFERENCE_ATTEMPTS = 3;

    /**
     * How long a failed reference waits before being tried again, in seconds.
     *
     * Comfortably longer than the circuit breaker's first stand-down, so that a database which
     * was briefly unwell has actually had the chance to recover before its second attempt.
     *
     * @var int
     */
    public const RETRY_DELAY = 120;

    /**
     * Per-request cache of jobs, keyed by submission id.
     *
     * The grading table calls view_summary() once per row, so without this a 300 student
     * assignment would issue 300 queries to render one page.
     *
     * @var array<int, stdClass|null>
     */
    protected static array $jobcache = [];

    /**
     * Assignment ids whose jobs have already been bulk loaded into the cache.
     *
     * @var array<int, true>
     */
    protected static array $primed = [];

    /**
     * Fetch the job for a submission, priming the whole assignment on first use.
     *
     * @param stdClass $submission An assign_submission record, needing at least id and assignment.
     * @return stdClass|null The job, or null when the submission has never been checked.
     */
    public static function get_job(stdClass $submission): ?stdClass {
        global $DB;

        $assignmentid = (int) ($submission->assignment ?? 0);
        $submissionid = (int) $submission->id;

        if ($assignmentid && !isset(self::$primed[$assignmentid])) {
            $jobs = $DB->get_records(self::TABLE_JOB, ['assignment' => $assignmentid]);
            foreach ($jobs as $job) {
                self::$jobcache[(int) $job->submission] = $job;
            }
            self::$primed[$assignmentid] = true;
        }

        if (array_key_exists($submissionid, self::$jobcache)) {
            return self::$jobcache[$submissionid];
        }

        $job = $DB->get_record(self::TABLE_JOB, ['submission' => $submissionid]) ?: null;
        self::$jobcache[$submissionid] = $job;

        return $job;
    }

    /**
     * Fetch a job straight from the database, bypassing the per-request cache.
     *
     * Background tasks must use this: they mutate jobs and re-read them, and a stale read would
     * defeat the generation guard.
     *
     * @param int $submissionid
     * @return stdClass|null
     */
    public static function load(int $submissionid): ?stdClass {
        global $DB;

        return $DB->get_record(self::TABLE_JOB, ['submission' => $submissionid]) ?: null;
    }

    /**
     * The references belonging to a job, in bibliography order.
     *
     * @param int $jobid
     * @return stdClass[]
     */
    public static function get_references(int $jobid): array {
        global $DB;

        return $DB->get_records(self::TABLE_REFS, ['jobid' => $jobid], 'sortorder ASC, id ASC');
    }

    /**
     * The next batch of references still waiting to be checked.
     *
     * The cursor lives in the data rather than in the task's custom data, which is what lets a
     * retried task resume exactly where it left off, and keeps task deduplication exact.
     *
     * @param int $jobid
     * @param int $limit
     * @return stdClass[]
     */
    public static function next_queued_references(int $jobid, int $limit): array {
        global $DB;

        // A reference put back after a failure waits before being tried again. Without this it
        // would be picked straight back up by the very next chunk and burn its whole attempt
        // budget within seconds, which defeats the point of retrying at all: the database that was
        // unavailable needs time to come back.
        $cutoff = time() - self::RETRY_DELAY;

        return $DB->get_records_select(
            self::TABLE_REFS,
            'jobid = :jobid AND status = :status AND timechecked <= :cutoff',
            ['jobid' => $jobid, 'status' => job_status::REF_QUEUED, 'cutoff' => $cutoff],
            'sortorder ASC, id ASC',
            '*',
            0,
            $limit,
        );
    }

    /**
     * How many references are still waiting to be checked, whatever their retry delay.
     *
     * Distinguishes "this job is finished" from "everything left is serving out a retry delay",
     * which look identical from the chunk query alone.
     *
     * @param int $jobid
     * @return int
     */
    public static function count_queued_references(int $jobid): int {
        global $DB;

        return $DB->count_records(self::TABLE_REFS, [
            'jobid' => $jobid,
            'status' => job_status::REF_QUEUED,
        ]);
    }

    /**
     * Discard the per-request cache.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$jobcache = [];
        self::$primed = [];
    }

    /**
     * Start a fresh check for a submission, discarding anything already recorded.
     *
     * Runs in one transaction so a task can never observe a half-reset job.
     *
     * @param stdClass $submission An assign_submission record.
     * @return stdClass The job, with its new generation.
     */
    public static function reset_for_submission(stdClass $submission): stdClass {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $now = time();
        $job = $DB->get_record(self::TABLE_JOB, ['submission' => $submission->id]);

        if ($job) {
            $DB->delete_records(self::TABLE_REFS, ['jobid' => $job->id]);
            $job->generation = (int) $job->generation + 1;
        } else {
            $job = (object) [
                'submission' => $submission->id,
                'generation' => 1,
            ];
        }

        $job->assignment = $submission->assignment;
        $job->status = job_status::PENDING;
        $job->requeues = 0;
        $job->contenthash = null;
        $job->sectionheading = null;
        $job->truncated = 0;
        $job->errorcode = null;
        $job->errormessage = null;
        $job->timequeued = $now;
        $job->timestarted = 0;
        $job->timecompleted = 0;
        $job->timemodified = $now;

        foreach (self::counter_fields() as $field) {
            $job->{$field} = 0;
        }
        foreach (['oldestyear', 'newestyear', 'avgyear', 'avgcitations'] as $field) {
            $job->{$field} = null;
        }

        if (isset($job->id)) {
            $DB->update_record(self::TABLE_JOB, $job);
        } else {
            $job->id = $DB->insert_record(self::TABLE_JOB, $job);
        }

        $transaction->allow_commit();

        self::reset_caches();

        return $job;
    }

    /**
     * Whether a task's generation still matches the job it was queued for.
     *
     * @param stdClass|null $job
     * @param int $generation
     * @return bool
     */
    public static function is_current(?stdClass $job, int $generation): bool {
        return $job !== null && (int) $job->generation === $generation;
    }

    /**
     * Move a job to a new status.
     *
     * @param stdClass $job
     * @param string $status
     * @param array $extra Any other job columns to set at the same time.
     * @return void
     */
    public static function set_status(stdClass $job, string $status, array $extra = []): void {
        global $DB;

        $now = time();
        $job->status = $status;
        $job->timemodified = $now;

        if ($status === job_status::EXTRACTING && empty($job->timestarted)) {
            $job->timestarted = $now;
        }
        if (job_status::is_terminal($status)) {
            $job->timecompleted = $now;
        }

        foreach ($extra as $field => $value) {
            $job->{$field} = $value;
        }

        $DB->update_record(self::TABLE_JOB, $job);
        unset(self::$jobcache[(int) $job->submission]);
    }

    /**
     * Record that a job could not be completed.
     *
     * @param stdClass $job
     * @param string $errorcode
     * @param string $errormessage
     * @return void
     */
    public static function fail(stdClass $job, string $errorcode, string $errormessage = ''): void {
        self::set_status($job, job_status::FAILED, [
            'errorcode' => $errorcode,
            'errormessage' => core_text::substr($errormessage, 0, 1000),
        ]);
    }

    /**
     * Store the references extracted from a submission.
     *
     * @param stdClass $job
     * @param array $references Each with raw, title, authors, journal, year, doi, sourcefile.
     * @param array $meta heading, truncated and contenthash.
     * @return void
     */
    public static function store_references(stdClass $job, array $references, array $meta = []): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $DB->delete_records(self::TABLE_REFS, ['jobid' => $job->id]);

        $rows = [];
        foreach (array_values($references) as $index => $reference) {
            $raw = (string) $reference['raw'];
            $rows[] = (object) [
                'jobid' => $job->id,
                'submission' => $job->submission,
                'sortorder' => $index,
                'rawref' => $raw,
                // Null, not '', when there is no source file: a pasted reference list has none.
                'sourcefile' => core_text::substr((string) ($reference['sourcefile'] ?? ''), 0, 255) ?: null,
                'refhash' => self::reference_hash($raw),
                'status' => job_status::REF_QUEUED,
                'attempts' => 0,
                'retracted' => 0,
                'predatory' => 0,
                'numissues' => 0,
                'timechecked' => 0,
            ];
        }

        if ($rows) {
            $DB->insert_records(self::TABLE_REFS, $rows);
        }

        $job->totalrefs = count($rows);
        $job->checkedrefs = 0;
        $job->sectionheading = core_text::substr((string) ($meta['heading'] ?? ''), 0, 255) ?: null;
        $job->truncated = !empty($meta['truncated']) ? 1 : 0;
        $job->contenthash = $meta['contenthash'] ?? null;
        $job->timemodified = time();

        $DB->update_record(self::TABLE_JOB, $job);

        $transaction->allow_commit();

        unset(self::$jobcache[(int) $job->submission]);
    }

    /**
     * The cache key for a reference.
     *
     * Deliberately a one way hash of the normalised text: the cache is shared across every
     * submission on the site, and must not become a store of student written material.
     *
     * @param string $rawreference
     * @return string
     */
    public static function reference_hash(string $rawreference): string {
        return sha1(matcher::normalize($rawreference));
    }

    /**
     * Write the outcome of checking one reference.
     *
     * @param stdClass $reference The reference row.
     * @param array $result As returned by the source chain.
     * @return void
     */
    public static function record_result(stdClass $reference, array $result): void {
        global $DB;

        $record = $result['record'] ?? null;

        $update = (object) [
            'id' => $reference->id,
            'status' => job_status::REF_CHECKED,
            'attempts' => (int) $reference->attempts + 1,
            'matchstatus' => $result['matchstatus'],
            'matchconfidence' => $result['confidence'],
            'titlescore' => $result['titlescore'],
            'authorscore' => $result['authorscore'],
            'journalscore' => $result['journalscore'],
            'source' => $result['source'],
            'foundtitle' => $record ? core_text::substr((string) $record['title'], 0, 1333) : null,
            'foundauthors' => $record ? json_encode(array_values((array) $record['authors'])) : null,
            'foundyear' => $record ? $record['year'] : null,
            'foundjournal' => $record ? core_text::substr((string) $record['journal'], 0, 255) : null,
            'doi' => $record ? core_text::substr((string) $record['doi'], 0, 255) : null,
            'url' => $record ? core_text::substr((string) $record['url'], 0, 1333) : null,
            'citations' => $record ? $record['citations'] : null,
            'retracted' => $record && !empty($record['retracted']) ? 1 : 0,
            'predatory' => $record && !empty($record['predatory']) ? 1 : 0,
            'numissues' => count($result['issues'] ?? []),
            'issues' => json_encode(array_values($result['issues'] ?? [])),
            'errormessage' => null,
            'timechecked' => time(),
        ];

        $DB->update_record(self::TABLE_REFS, $update);
    }

    /**
     * Record that a reference could not be checked.
     *
     * Below the attempt limit the reference goes back into the queue; at the limit it is given up
     * on, so that one stubborn reference cannot hold a whole submission at 15 of 16 forever.
     *
     * @param stdClass $reference
     * @param string $message
     * @return void
     */
    public static function record_reference_failure(stdClass $reference, string $message): void {
        global $DB;

        $attempts = (int) $reference->attempts + 1;
        $exhausted = $attempts >= self::MAX_REFERENCE_ATTEMPTS;

        $DB->update_record(self::TABLE_REFS, (object) [
            'id' => $reference->id,
            'attempts' => $attempts,
            'status' => $exhausted ? job_status::REF_ERROR : job_status::REF_QUEUED,
            'matchstatus' => $exhausted ? match_status::NOTFOUND : null,
            'errormessage' => core_text::substr($message, 0, 255),
            // Always stamped, including on a requeue: this is what the retry delay measures.
            'timechecked' => time(),
        ]);
    }

    /**
     * Recompute a job's counters from its references.
     *
     * One aggregate query rather than a running tally, so a retried or partially applied chunk
     * cannot leave the counters drifting away from the rows they describe.
     *
     * @param stdClass $job
     * @return stdClass The updated job.
     */
    public static function recalculate_counters(stdClass $job): stdClass {
        global $DB;

        $sql = "SELECT
                    COUNT(*) AS totalrefs,
                    SUM(CASE WHEN status <> :queued THEN 1 ELSE 0 END) AS checkedrefs,
                    SUM(CASE WHEN matchstatus = :verified THEN 1 ELSE 0 END) AS verifiedrefs,
                    SUM(CASE WHEN matchstatus = :partial THEN 1 ELSE 0 END) AS partialrefs,
                    SUM(CASE WHEN matchstatus = :mismatch THEN 1 ELSE 0 END) AS mismatchrefs,
                    SUM(CASE WHEN matchstatus = :notfound THEN 1 ELSE 0 END) AS notfoundrefs,
                    SUM(CASE WHEN numissues > 0 THEN 1 ELSE 0 END) AS issuerefs,
                    SUM(CASE WHEN retracted > 0 THEN 1 ELSE 0 END) AS retractedrefs,
                    SUM(CASE WHEN predatory > 0 THEN 1 ELSE 0 END) AS predatoryrefs,
                    MIN(foundyear) AS oldestyear,
                    MAX(foundyear) AS newestyear,
                    AVG(foundyear) AS avgyear,
                    SUM(citations) AS totalcitations,
                    AVG(citations) AS avgcitations
                  FROM {" . self::TABLE_REFS . "}
                 WHERE jobid = :jobid";

        $totals = $DB->get_record_sql($sql, [
            'jobid' => $job->id,
            'queued' => job_status::REF_QUEUED,
            'verified' => match_status::VERIFIED,
            'partial' => match_status::PARTIAL,
            'mismatch' => match_status::MISMATCH,
            'notfound' => match_status::NOTFOUND,
        ]);

        foreach (self::counter_fields() as $field) {
            $job->{$field} = (int) ($totals->{$field} ?? 0);
        }

        $job->oldestyear = $totals->oldestyear !== null ? (int) $totals->oldestyear : null;
        $job->newestyear = $totals->newestyear !== null ? (int) $totals->newestyear : null;
        $job->avgyear = $totals->avgyear !== null ? round((float) $totals->avgyear, 2) : null;
        $job->avgcitations = $totals->avgcitations !== null ? round((float) $totals->avgcitations, 2) : null;
        $job->timemodified = time();

        $DB->update_record(self::TABLE_JOB, $job);
        unset(self::$jobcache[(int) $job->submission]);

        return $job;
    }

    /**
     * The job columns that are plain counts.
     *
     * @return string[]
     */
    protected static function counter_fields(): array {
        return [
            'totalrefs', 'checkedrefs', 'verifiedrefs', 'partialrefs', 'mismatchrefs',
            'notfoundrefs', 'issuerefs', 'retractedrefs', 'predatoryrefs', 'totalcitations',
        ];
    }

    /**
     * Look a reference up in the shared cache.
     *
     * @param string $refhash
     * @return array|null The cached result, or null on a miss.
     */
    public static function cache_get(string $refhash): ?array {
        global $DB;

        $entry = $DB->get_record_select(
            self::TABLE_CACHE,
            'refhash = :refhash AND timeexpiry > :now',
            ['refhash' => $refhash, 'now' => time()],
        );

        if (!$entry) {
            return null;
        }

        $payload = json_decode($entry->payload, true);
        if (!is_array($payload)) {
            return null;
        }

        $DB->set_field(self::TABLE_CACHE, 'hits', (int) $entry->hits + 1, ['id' => $entry->id]);

        return $payload;
    }

    /**
     * Store a result in the shared cache.
     *
     * @param string $refhash
     * @param array $result
     * @return void
     */
    public static function cache_put(string $refhash, array $result): void {
        global $DB;

        $ttl = (int) get_config('assignsubmission_refchecker', 'cachettl') ?: (30 * DAYSECS);
        $now = time();

        // The payload is the outcome and the record that was found. It must never carry the
        // reference text the student wrote, because this table is shared across the whole site.
        unset($result['raw'], $result['rawref'], $result['query']);

        $entry = $DB->get_record(self::TABLE_CACHE, ['refhash' => $refhash]);

        if ($entry) {
            $DB->update_record(self::TABLE_CACHE, (object) [
                'id' => $entry->id,
                'payload' => json_encode($result),
                'matchstatus' => $result['matchstatus'],
                'source' => $result['source'],
                'timecreated' => $now,
                'timeexpiry' => $now + $ttl,
            ]);
            return;
        }

        $DB->insert_record(self::TABLE_CACHE, (object) [
            'refhash' => $refhash,
            'payload' => json_encode($result),
            'matchstatus' => $result['matchstatus'],
            'source' => $result['source'],
            'hits' => 0,
            'timecreated' => $now,
            'timeexpiry' => $now + $ttl,
        ]);
    }

    /**
     * Remove expired cache entries.
     *
     * @return int How many were removed.
     */
    public static function purge_expired_cache(): int {
        global $DB;

        $expired = $DB->count_records_select(self::TABLE_CACHE, 'timeexpiry <= ?', [time()]);
        $DB->delete_records_select(self::TABLE_CACHE, 'timeexpiry <= ?', [time()]);

        return $expired;
    }

    /**
     * Remove the job and references for a single submission.
     *
     * @param int $submissionid
     * @return void
     */
    public static function delete_for_submission(int $submissionid): void {
        global $DB;

        $DB->delete_records(self::TABLE_REFS, ['submission' => $submissionid]);
        $DB->delete_records(self::TABLE_JOB, ['submission' => $submissionid]);

        unset(self::$jobcache[$submissionid]);
    }

    /**
     * Remove every job and reference belonging to an assignment.
     *
     * @param int $assignmentid
     * @return void
     */
    public static function delete_for_assignment(int $assignmentid): void {
        global $DB;

        $jobids = $DB->get_fieldset_select(self::TABLE_JOB, 'id', 'assignment = ?', [$assignmentid]);
        if ($jobids) {
            [$insql, $params] = $DB->get_in_or_equal($jobids);
            $DB->delete_records_select(self::TABLE_REFS, "jobid $insql", $params);
        }
        $DB->delete_records(self::TABLE_JOB, ['assignment' => $assignmentid]);

        self::reset_caches();
    }

    /**
     * Copy a completed job and its references onto another submission.
     *
     * Used when an attempt is reopened and the previous attempt's files are copied forward: the
     * files are identical, so re-checking them would burn external API quota for no benefit.
     *
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     * @return void
     */
    public static function copy_to_submission(stdClass $sourcesubmission, stdClass $destsubmission): void {
        global $DB;

        $source = $DB->get_record(self::TABLE_JOB, ['submission' => $sourcesubmission->id]);
        if (!$source) {
            return;
        }

        $references = self::get_references((int) $source->id);

        $job = clone $source;
        unset($job->id);
        $job->submission = $destsubmission->id;
        $job->assignment = $destsubmission->assignment;
        $job->timemodified = time();
        $jobid = $DB->insert_record(self::TABLE_JOB, $job);

        $rows = [];
        foreach ($references as $reference) {
            $row = clone $reference;
            unset($row->id);
            $row->jobid = $jobid;
            $row->submission = $destsubmission->id;
            $rows[] = $row;
        }
        if ($rows) {
            $DB->insert_records(self::TABLE_REFS, $rows);
        }

        unset(self::$jobcache[(int) $destsubmission->id]);
    }
}

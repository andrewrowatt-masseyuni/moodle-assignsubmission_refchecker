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

namespace assignsubmission_refchecker\task;

use assignsubmission_refchecker\local\debug_log;
use assignsubmission_refchecker\local\exception\permanent_exception;
use assignsubmission_refchecker\local\exception\rate_limited_exception;
use assignsubmission_refchecker\local\exception\transient_exception;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\local\reference_parser;
use assignsubmission_refchecker\local\source\chain;
use core\task\adhoc_task;
use stdClass;

/**
 * Checks a batch of a submission's references, then queues itself again if any remain.
 *
 * Working in bounded batches and re-queueing keeps each task run short, makes progress durable
 * after every batch, and means a retry resumes where the last attempt stopped rather than starting
 * the submission over.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_references extends adhoc_task {
    use job_task;

    /**
     * Runs that may be put off in a row before the job is called failed.
     *
     * Only reached when the pacer refuses to release a slot run after run, which means a
     * configuration a site can fix rather than a service having a bad afternoon. Generous enough
     * that ordinary contention between cron workers never approaches it, since giving up early
     * would report a submission as failed that only needed to wait.
     *
     * @var int
     */
    public const MAX_CONSECUTIVE_DEFERRALS = 20;

    /**
     * The task's name, as shown in the task administration screens.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_check', 'assignsubmission_refchecker');
    }

    /**
     * How many of these may run at once across the site.
     *
     * This is the site's hard ceiling on how fast it talks to the external databases, and the
     * main protection against a deadline putting the institution's address in a penalty box.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return (int) get_config('assignsubmission_refchecker', 'maxconcurrency') ?: 2;
    }

    /**
     * Check the next batch of references.
     *
     * @return void
     */
    public function execute(): void {
        [$job, $submission] = $this->load_current_job();
        if (!$job) {
            return;
        }

        $assign = $this->load_assign($submission);
        if (!$assign) {
            job_manager::set_status($job, job_status::CANCELLED);
            return;
        }

        $chunksize = (int) get_config('assignsubmission_refchecker', 'chunksize') ?: 10;
        $references = job_manager::next_queued_references((int) $job->id, $chunksize);

        if (!$references) {
            // An empty batch does not necessarily mean the job is done: references put back after
            // a failure are held for a while before being retried. Completing here would report a
            // submission as finished with references still outstanding.
            $waiting = job_manager::count_queued_references((int) $job->id);
            if ($waiting > 0) {
                mtrace('  ' . $waiting . ' reference(s) waiting to be retried.');
                job_manager::clear_deferrals($job);
                job_manager::recalculate_counters($job);
                $this->queue_next(self::class, $job, job_manager::RETRY_DELAY, false);
                return;
            }

            $job = job_manager::recalculate_counters($job);
            job_manager::set_status($job, job_status::COMPLETE);
            mtrace('  Complete: ' . $job->checkedrefs . ' of ' . $job->totalrefs . ' checked.');
            debug_log::log('job.complete', [
                'submission' => (int) $job->submission,
                'total' => (int) $job->totalrefs,
                'verified' => (int) $job->verifiedrefs,
                'partial' => (int) $job->partialrefs,
                'mismatch' => (int) $job->mismatchrefs,
                'notfound' => (int) $job->notfoundrefs,
            ]);
            $this->trigger_completed($job, $assign);
            return;
        }

        $chain = chain::from_config();
        if (!$chain->get_sources()) {
            job_manager::fail($job, 'nosources', 'No bibliographic databases are enabled.');
            return;
        }

        debug_log::log('chunk.start', [
            'submission' => (int) $job->submission,
            'references' => count($references),
            'checked' => (int) $job->checkedrefs,
            'total' => (int) $job->totalrefs,
            'sources' => array_map(static fn($source) => $source->get_name(), $chain->get_sources()),
        ]);

        foreach ($references as $reference) {
            try {
                $this->check_one($chain, $reference);
            } catch (rate_limited_exception $e) {
                $this->pause($job, $e);
                return;
            }
        }

        job_manager::clear_deferrals($job);
        $job = job_manager::recalculate_counters($job);
        mtrace('  Checked ' . $job->checkedrefs . ' of ' . $job->totalrefs . '.');

        $delay = (int) get_config('assignsubmission_refchecker', 'interchunkdelay');
        $this->queue_next(self::class, $job, $delay, false);
    }

    /**
     * Put the whole task down for a while, because the pacer will not release a slot yet.
     *
     * Reserved for this plugin's own pacing decision, which is about our request rate rather than
     * any one database, and so applies to every reference queued behind this one. A service
     * refusing a request is not this: that is one source being unwell, and the chain stands it down
     * and carries on without it.
     *
     * Nothing was sent, so no reference has anything recorded against it and none spends an
     * attempt. That leaves the run with no way to bound itself, which is what the deferral count is
     * for: a pacer that never lets a run through is a misconfiguration, and the job is failed with
     * something an administrator can act on rather than left rescheduling itself indefinitely.
     *
     * @param stdClass $job
     * @param rate_limited_exception $e
     * @return void
     */
    protected function pause(stdClass $job, rate_limited_exception $e): void {
        $wait = $e->get_retry_after();
        $deferrals = job_manager::record_deferral($job);

        if ($deferrals >= self::MAX_CONSECUTIVE_DEFERRALS) {
            mtrace('  Paused ' . $deferrals . ' times without sending a request; giving up.');
            debug_log::log('chunk.stalled', [
                'submission' => (int) $job->submission,
                'deferrals' => $deferrals,
                'reason' => $e->getMessage(),
            ]);
            job_manager::fail($job, 'pacerstalled', get_string(
                'error_pacerstalled',
                'assignsubmission_refchecker',
            ));
            return;
        }

        // Says which of the two rate limits this is, since the cron log is where anyone
        // investigating a slow submission starts and the two want opposite responses.
        mtrace('  Paced: no request slot for ' . $wait . 's; pausing '
            . '(' . $deferrals . ' of ' . self::MAX_CONSECUTIVE_DEFERRALS . ').');
        debug_log::log('chunk.paused', [
            'submission' => (int) $job->submission,
            'seconds' => $wait,
            'deferral' => $deferrals,
            'of' => self::MAX_CONSECUTIVE_DEFERRALS,
            'reason' => $e->getMessage(),
        ]);
        job_manager::recalculate_counters($job);
        $this->queue_next(self::class, $job, $wait, false);
    }

    /**
     * Check a single reference and record the outcome.
     *
     * No failure here is allowed to bring the task down. One reference that cannot be resolved,
     * for whatever reason, must not stall the ones queued behind it, and a database being briefly
     * unwell must not be able to mark a whole submission as failed. Everything is instead recorded
     * against the reference itself, where the attempt budget bounds how long it can go on.
     *
     * The pacer declining to send anything is the one exception, and propagates: no request was
     * made, so it says nothing about this reference and applies equally to every reference queued
     * behind it. A database refusing a request is not that, and does not come this far: the chain
     * stands that source down and answers from the ones that did reply.
     *
     * @param chain $chain
     * @param stdClass $reference
     * @return void
     * @throws rate_limited_exception When the pacer would not release a slot.
     */
    protected function check_one(chain $chain, stdClass $reference): void {
        $parsed = array_merge(
            ['raw' => $reference->rawref],
            reference_parser::parse_metadata($reference->rawref),
        );

        debug_log::log('reference.check', [
            'refid' => (int) $reference->id,
            'n' => (int) $reference->sortorder + 1,
            'attempt' => (int) $reference->attempts + 1,
            'title' => $parsed['title'] ?? '',
            'year' => $parsed['year'] ?? '',
            'doi' => $parsed['doi'] ?? '',
            'raw' => $reference->rawref,
        ]);

        try {
            $result = $chain->check($parsed);
        } catch (rate_limited_exception $e) {
            // Must come before the transient catch. rate_limited_exception is a subclass of it, so
            // ordering these the other way round silently swallows every pause: the caller would
            // never reschedule, the reference would spend one of its three attempts on a search
            // that never happened, and after three it would be recorded as "not found" with an
            // internal message shown against it.
            //
            // Nothing was sent, so there is nothing to record. Hand it to the caller, which puts
            // the whole task down until the slot comes free.
            throw $e;
        } catch (permanent_exception $e) {
            $this->log_reference_failure($reference, 'permanent', $e->getMessage());
            job_manager::record_reference_failure($reference, $e->getMessage());
            return;
        } catch (transient_exception $e) {
            // Every database was unreachable, so nothing was actually searched. Put the reference
            // back in the queue rather than throwing: failing the task would stall the references
            // behind it too, and the attempt budget already stops this going on forever.
            $this->log_reference_failure($reference, 'transient', $e->getMessage());
            job_manager::record_reference_failure($reference, $e->getMessage());
            return;
        }

        if (!empty($result['degraded'])) {
            // Not found, but at least one database could not be consulted. Give the missing one
            // another chance on a later run before accepting the answer, and never write it to the
            // shared cache in the meantime.
            if ((int) $reference->attempts + 1 < job_manager::MAX_REFERENCE_ATTEMPTS) {
                $this->log_reference_failure($reference, 'degraded', $this->degraded_message($result));
                job_manager::record_reference_failure($reference, $this->degraded_message($result));
                return;
            }

            // Out of attempts. Record what the databases that did answer told us, but keep it out
            // of the cache: this answer is weaker than a clean one and must not be shared.
            debug_log::log('reference.result', [
                'refid' => (int) $reference->id,
                'matchstatus' => $result['matchstatus'] ?? '',
                'confidence' => $result['confidence'] ?? '',
                'source' => $result['source'] ?? '',
                'degraded' => true,
                'cached' => false,
                'unavailable' => (array) ($result['unavailable'] ?? []),
            ]);
            job_manager::record_result($reference, $result);
            return;
        }

        debug_log::log('reference.result', [
            'refid' => (int) $reference->id,
            'matchstatus' => $result['matchstatus'] ?? '',
            'confidence' => $result['confidence'] ?? '',
            'source' => $result['source'] ?? '',
            'degraded' => false,
            'cached' => true,
        ]);

        job_manager::record_result($reference, $result);
        job_manager::cache_put($reference->refhash, $result);
    }

    /**
     * Record that a reference could not be resolved this time round.
     *
     * Says whether the attempt budget has now run out, because that is the difference between a
     * reference that will be tried again and one the student is about to be told was not found.
     *
     * @param stdClass $reference
     * @param string $kind permanent, transient or degraded.
     * @param string $message
     * @return void
     */
    protected function log_reference_failure(stdClass $reference, string $kind, string $message): void {
        $attempts = (int) $reference->attempts + 1;
        $exhausted = $attempts >= job_manager::MAX_REFERENCE_ATTEMPTS;

        debug_log::log($exhausted ? 'reference.gaveup' : 'reference.requeued', [
            'refid' => (int) $reference->id,
            'kind' => $kind,
            'attempts' => $attempts,
            'of' => job_manager::MAX_REFERENCE_ATTEMPTS,
            'reason' => $message,
        ]);
    }

    /**
     * Describe which databases were missing from a search.
     *
     * @param array $result
     * @return string
     */
    protected function degraded_message(array $result): string {
        return 'Not found, but these were unavailable: '
            . implode(', ', (array) ($result['unavailable'] ?? []));
    }
}

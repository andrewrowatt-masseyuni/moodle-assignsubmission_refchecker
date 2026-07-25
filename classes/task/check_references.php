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
                job_manager::recalculate_counters($job);
                $this->queue_next(self::class, $job, job_manager::RETRY_DELAY, false);
                return;
            }

            $job = job_manager::recalculate_counters($job);
            job_manager::set_status($job, job_status::COMPLETE);
            mtrace('  Complete: ' . $job->checkedrefs . ' of ' . $job->totalrefs . ' checked.');
            $this->trigger_completed($job, $assign);
            return;
        }

        $chain = chain::from_config();
        if (!$chain->get_sources()) {
            job_manager::fail($job, 'nosources', 'No bibliographic databases are enabled.');
            return;
        }

        foreach ($references as $reference) {
            try {
                $this->check_one($chain, $reference);
            } catch (rate_limited_exception $e) {
                // Backpressure is routine, not a fault. Honour the wait the service asked for and
                // come back then, rather than throwing and inflating Moodle's own fail delay.
                $wait = $e->get_retry_after();
                mtrace('  Rate limited; pausing for ' . $wait . 's.');
                job_manager::recalculate_counters($job);
                $this->queue_next(self::class, $job, $wait, false);
                return;
            }
        }

        $job = job_manager::recalculate_counters($job);
        mtrace('  Checked ' . $job->checkedrefs . ' of ' . $job->totalrefs . '.');

        $delay = (int) get_config('assignsubmission_refchecker', 'interchunkdelay');
        $this->queue_next(self::class, $job, $delay, false);
    }

    /**
     * Check a single reference and record the outcome.
     *
     * No failure here is allowed to bring the task down. One reference that cannot be resolved,
     * for whatever reason, must not stall the ones queued behind it, and a database being briefly
     * unwell must not be able to mark a whole submission as failed. Everything is instead recorded
     * against the reference itself, where the attempt budget bounds how long it can go on.
     *
     * @param chain $chain
     * @param stdClass $reference
     * @return void
     */
    protected function check_one(chain $chain, stdClass $reference): void {
        $parsed = array_merge(
            ['raw' => $reference->rawref],
            reference_parser::parse_metadata($reference->rawref),
        );

        try {
            $result = $chain->check($parsed);
        } catch (permanent_exception $e) {
            job_manager::record_reference_failure($reference, $e->getMessage());
            return;
        } catch (transient_exception $e) {
            // Every database was unreachable, so nothing was actually searched. Put the reference
            // back in the queue rather than throwing: failing the task would stall the references
            // behind it too, and the attempt budget already stops this going on forever.
            job_manager::record_reference_failure($reference, $e->getMessage());
            return;
        }

        if (!empty($result['degraded'])) {
            // Not found, but at least one database could not be consulted. Give the missing one
            // another chance on a later run before accepting the answer, and never write it to the
            // shared cache in the meantime.
            if ((int) $reference->attempts + 1 < job_manager::MAX_REFERENCE_ATTEMPTS) {
                job_manager::record_reference_failure($reference, $this->degraded_message($result));
                return;
            }

            // Out of attempts. Record what the databases that did answer told us, but keep it out
            // of the cache: this answer is weaker than a clean one and must not be shared.
            job_manager::record_result($reference, $result);
            return;
        }

        job_manager::record_result($reference, $result);
        job_manager::cache_put($reference->refhash, $result);
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

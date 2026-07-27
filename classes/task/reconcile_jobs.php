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
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\job_status;
use core\task\manager;
use core\task\scheduled_task;

/**
 * Housekeeping for reference checking.
 *
 * Its main job is to close the one hole the task API leaves open: an adhoc task that exhausts its
 * retries is simply dropped, which would leave a submission showing "15 / 16 checks complete"
 * forever with nothing left to move it on. Anything stalled is either restarted once or reported
 * as failed, so the status a student sees is always the truth.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_jobs extends scheduled_task {
    /** @var int A job may only be revived this many times before it is called failed. */
    protected const MAX_REQUEUES = 1;

    /**
     * The task's name, as shown in the task administration screens.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reconcile', 'assignsubmission_refchecker');
    }

    /**
     * Revive or fail stalled jobs, and purge expired cache entries.
     *
     * @return void
     */
    public function execute(): void {
        $purged = job_manager::purge_expired_cache();
        if ($purged) {
            mtrace('  Purged ' . $purged . ' expired cache entr(ies).');
        }

        // The activity log drops its own expired files as each hour rolls over, but only while it
        // is being written to. This is what clears the last few files after logging is switched
        // off, so student reference text does not sit in dataroot indefinitely.
        $logs = debug_log::purge();
        if ($logs) {
            mtrace('  Deleted ' . $logs . ' expired activity log file(s).');
        }

        $this->blank_expired_reference_text();
        $this->reconcile_stalled_jobs();
    }

    /**
     * Restart or fail jobs that have stopped making progress.
     *
     * @return void
     */
    protected function reconcile_stalled_jobs(): void {
        global $DB;

        $timeout = (int) get_config('assignsubmission_refchecker', 'staletimeout') ?: (6 * HOURSECS);
        $cutoff = time() - $timeout;

        [$insql, $params] = $DB->get_in_or_equal(job_status::in_progress_states(), SQL_PARAMS_NAMED);
        $params['cutoff'] = $cutoff;

        $stalled = $DB->get_records_select(
            job_manager::TABLE_JOB,
            "status $insql AND timemodified < :cutoff",
            $params,
        );

        foreach ($stalled as $job) {
            if ($this->has_pending_task($job)) {
                // Still queued, just waiting behind a long backoff. Leave it alone.
                continue;
            }

            if ((int) $job->requeues >= self::MAX_REQUEUES) {
                mtrace('  Abandoning submission ' . $job->submission . ' after ' . $job->requeues . ' retries.');
                job_manager::fail($job, 'abandoned', get_string(
                    'error_abandoned',
                    'assignsubmission_refchecker',
                ));
                continue;
            }

            mtrace('  Restarting submission ' . $job->submission . '.');

            $job->requeues = (int) $job->requeues + 1;
            $DB->update_record(job_manager::TABLE_JOB, $job);

            // Resume from wherever it stopped: extraction if nothing was ever stored, otherwise
            // straight back to checking whatever is still queued.
            $classname = (int) $job->totalrefs > 0
                ? check_references::class
                : extract_references::class;

            $task = new $classname();
            $task->set_custom_data([
                'submissionid' => (int) $job->submission,
                'generation' => (int) $job->generation,
            ]);
            $task->set_component('assignsubmission_refchecker');

            manager::queue_adhoc_task($task, true);
        }

        job_manager::reset_caches();
    }

    /**
     * Whether one of this plugin's tasks is still queued for a job.
     *
     * @param \stdClass $job
     * @return bool
     */
    protected function has_pending_task(\stdClass $job): bool {
        global $DB;

        // Must match exactly how the tasks encode their custom data, key order included.
        $customdata = json_encode([
            'submissionid' => (int) $job->submission,
            'generation' => (int) $job->generation,
        ]);

        // Text comparison truncates to 32 characters unless told otherwise, which would make two
        // different submissions' custom data look identical. This mirrors how core's own task
        // deduplication compares the column.
        $comparison = $DB->sql_compare_text('customdata', \core_text::strlen($customdata) + 1);

        return $DB->record_exists_select(
            'task_adhoc',
            "component = :component AND {$comparison} = :customdata",
            ['component' => 'assignsubmission_refchecker', 'customdata' => $customdata],
        );
    }

    /**
     * Blank stored reference text once it is older than the site is willing to keep it.
     *
     * The result of the check is kept; only the copy of what the student wrote goes. This is the
     * data minimisation lever for sites that would rather not retain student text indefinitely.
     *
     * @return void
     */
    protected function blank_expired_reference_text(): void {
        global $DB;

        $days = (int) get_config('assignsubmission_refchecker', 'retaindays');
        if ($days <= 0) {
            return;
        }

        $cutoff = time() - ($days * DAYSECS);

        $sql = "UPDATE {" . job_manager::TABLE_REFS . "}
                   SET rawref = :blank
                 WHERE rawref <> :alreadyblank
                   AND timechecked > 0
                   AND timechecked < :cutoff";

        $DB->execute($sql, ['blank' => '', 'alreadyblank' => '', 'cutoff' => $cutoff]);
    }
}

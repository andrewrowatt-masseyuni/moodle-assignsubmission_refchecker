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

use assign;
use assignsubmission_refchecker\event\check_completed;
use assignsubmission_refchecker\local\job_manager;
use core\task\adhoc_task;
use core\task\manager;
use stdClass;

/**
 * Shared behaviour for the tasks that work through a checking job.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait job_task {
    /**
     * Load the job this task was queued for, if it is still the current one.
     *
     * Returns nothing when the submission has been deleted, or when the student has resubmitted
     * since this task was queued. In the second case a newer task already owns the work, and
     * carrying on would overwrite its results with stale ones.
     *
     * @return array{0: stdClass|null, 1: stdClass|null} The job and its submission.
     */
    protected function load_current_job(): array {
        global $DB;

        $data = (object) (array) $this->get_custom_data();
        $submissionid = (int) ($data->submissionid ?? 0);
        $generation = (int) ($data->generation ?? 0);

        if (!$submissionid) {
            return [null, null];
        }

        $job = job_manager::load($submissionid);
        if (!job_manager::is_current($job, $generation)) {
            mtrace('  Superseded or removed: submission ' . $submissionid . ', generation ' . $generation . '.');
            return [null, null];
        }

        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission) {
            mtrace('  Submission ' . $submissionid . ' no longer exists.');
            job_manager::delete_for_submission($submissionid);
            return [null, null];
        }

        return [$job, $submission];
    }

    /**
     * Rebuild the assignment a submission belongs to.
     *
     * @param stdClass $submission
     * @return assign|null Null when the assignment or its course module has gone.
     */
    protected function load_assign(stdClass $submission): ?assign {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $cm = get_coursemodule_from_instance('assign', $submission->assignment, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }

        $context = \context_module::instance($cm->id);
        $assign = new assign($context, $cm, $cm->course);

        // The plugin may have been turned off since this task was queued.
        $plugin = $assign->get_submission_plugin_by_type('refchecker');
        if (!$plugin || !$plugin->is_enabled() || !$plugin->is_visible()) {
            return null;
        }

        return $assign;
    }

    /**
     * Queue the next task in the pipeline for this job.
     *
     * @param string $classname The adhoc task class to queue.
     * @param stdClass $job
     * @param int $delay Seconds to wait before it runs.
     * @param bool $dedupe Whether to skip queueing if an identical task is already waiting.
     * @return void
     */
    protected function queue_next(string $classname, stdClass $job, int $delay = 0, bool $dedupe = true): void {
        /** @var adhoc_task $task */
        $task = new $classname();
        $task->set_custom_data([
            'submissionid' => (int) $job->submission,
            'generation' => (int) $job->generation,
        ]);
        $task->set_component('assignsubmission_refchecker');

        if ($delay > 0) {
            $task->set_next_run_time(time() + $delay);
        }

        // Deduplication matches on class, component and custom data, and the custom data carries
        // the generation, so a resubmission is never mistaken for a duplicate of the check it
        // replaced. It must be off when a task queues its own successor, because the running
        // task's own queue row still exists and would match.
        manager::queue_adhoc_task($task, $dedupe);
    }

    /**
     * Announce that a job has finished.
     *
     * @param stdClass $job
     * @param assign $assign
     * @return void
     */
    protected function trigger_completed(stdClass $job, assign $assign): void {
        check_completed::create_from_job($job, $assign)->trigger();
    }
}

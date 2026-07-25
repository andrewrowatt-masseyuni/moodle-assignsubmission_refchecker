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

namespace assignsubmission_refchecker\event;

use assign;
use assignsubmission_refchecker\local\check_timing;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\task\extract_references;
use core\event\base;
use core\task\manager;
use Throwable;

/**
 * Queues a reference check when a student submits.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A student submitted for grading.
     *
     * @param base $event
     * @return void
     */
    public static function assessable_submitted(base $event): void {
        self::queue($event, check_timing::SUBMIT);
    }

    /**
     * A submission was saved, which may be a draft.
     *
     * @param base $event
     * @return void
     */
    public static function submission_changed(base $event): void {
        self::queue($event, check_timing::SAVE);
    }

    /**
     * Queue a check if this assignment wants one at this point in the submission's life.
     *
     * This runs inside the request that saved the student's submission, and Moodle's event
     * manager does not catch observer exceptions. Anything thrown here would surface to the
     * student as a failed submission, so the whole body is guarded. That is the opposite of the
     * rule the background tasks follow, where throwing is how a retry is requested.
     *
     * @param base $event
     * @param string $timing The timing this observer represents.
     * @return void
     */
    protected static function queue(base $event, string $timing): void {
        try {
            $assign = $event->get_assign();
            if (!$assign instanceof assign) {
                return;
            }

            $plugin = $assign->get_submission_plugin_by_type('refchecker');
            if (!$plugin || !$plugin->is_enabled() || !$plugin->is_visible()) {
                return;
            }

            // Without File submissions there is nothing to read references out of.
            $fileplugin = $assign->get_submission_plugin_by_type('file');
            if (!$fileplugin || !$fileplugin->is_enabled() || !$fileplugin->is_visible()) {
                return;
            }

            $configured = check_timing::sanitise((string) $plugin->get_config('checktiming'));
            if ($configured !== $timing) {
                return;
            }

            // Checking on every save also fires on submit, and the submit observer would then
            // queue a second, identical check. Let the save observer own that case entirely.
            if ($timing === check_timing::SUBMIT && $configured === check_timing::SAVE) {
                return;
            }

            $submission = self::resolve_submission($event);
            if (!$submission) {
                return;
            }

            $job = job_manager::reset_for_submission($submission);

            $task = new extract_references();
            $task->set_custom_data([
                'submissionid' => (int) $submission->id,
                'generation' => (int) $job->generation,
            ]);
            $task->set_component('assignsubmission_refchecker');

            manager::queue_adhoc_task($task, true);
        } catch (Throwable $e) {
            // Never let a checking problem break a student's submission.
            debugging(
                'assignsubmission_refchecker could not queue a check: ' . $e->getMessage(),
                DEBUG_DEVELOPER,
            );
        }
    }

    /**
     * Find the assign_submission record the event refers to.
     *
     * assessable_submitted carries it as objectid; the submission_created and submission_updated
     * family carry it in other data instead, because their objectid is the subplugin's own row.
     *
     * @param base $event
     * @return \stdClass|null
     */
    protected static function resolve_submission(base $event): ?\stdClass {
        global $DB;

        $submissionid = (int) ($event->other['submissionid'] ?? 0);
        if (!$submissionid && $event->objecttable === 'assign_submission') {
            $submissionid = (int) $event->objectid;
        }

        if (!$submissionid) {
            return null;
        }

        return $DB->get_record('assign_submission', ['id' => $submissionid]) ?: null;
    }
}

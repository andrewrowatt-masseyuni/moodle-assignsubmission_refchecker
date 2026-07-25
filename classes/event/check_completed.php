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
use assignsubmission_refchecker\local\job_manager;
use core\event\base;
use stdClass;

/**
 * Fired when a submission's references have all been checked.
 *
 * Exists so that reference checking leaves a trail in the standard logs, and so anything wanting
 * to react to a finished check has something to observe.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_completed extends base {
    /**
     * Build the event from a finished job.
     *
     * @param stdClass $job
     * @param assign $assign
     * @return self
     */
    public static function create_from_job(stdClass $job, assign $assign): self {
        $data = [
            'context' => $assign->get_context(),
            'objectid' => $job->id,
            'other' => [
                'submissionid' => (int) $job->submission,
                'totalrefs' => (int) $job->totalrefs,
                'verifiedrefs' => (int) $job->verifiedrefs,
                'notfoundrefs' => (int) $job->notfoundrefs,
            ],
        ];

        /** @var self $event */
        $event = self::create($data);
        $event->add_record_snapshot(job_manager::TABLE_JOB, $job);

        return $event;
    }

    /**
     * Initialise the event's fixed properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = job_manager::TABLE_JOB;
    }

    /**
     * The event's name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_check_completed', 'assignsubmission_refchecker');
    }

    /**
     * A description of what happened, for the logs.
     *
     * @return string
     */
    public function get_description(): string {
        return "Reference checking finished for submission '{$this->other['submissionid']}' "
            . "with {$this->other['verifiedrefs']} of {$this->other['totalrefs']} reference(s) verified.";
    }

    /**
     * Where to go to see the result.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/assign/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validate the data the event was created with.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data(): void {
        parent::validate_data();

        if (!isset($this->other['submissionid'])) {
            throw new \coding_exception('The \'submissionid\' value must be set in other.');
        }
    }

    /**
     * Mapping used when restoring logs into another site.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        // The job table is not mapped during restore, so its ids cannot be translated.
        return ['db' => job_manager::TABLE_JOB, 'restore' => base::NOT_MAPPED];
    }

    /**
     * Mapping for the other data used when restoring logs.
     *
     * @return array
     */
    public static function get_other_mapping(): array {
        return ['submissionid' => ['db' => 'assign_submission', 'restore' => 'submission']];
    }
}

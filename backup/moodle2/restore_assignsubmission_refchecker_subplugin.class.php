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

/**
 * Provides the information to restore Reference Checker submissions and results
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignsubmission_refchecker_subplugin extends restore_subplugin {
    /**
     * The id of the job most recently restored.
     *
     * Held so the reference rows can be attached to it. Nested elements are processed depth
     * first, immediately after their parent, so this is set before it is read.
     *
     * @var int
     */
    protected $currentjobid = 0;

    /**
     * The paths this subplugin handles beneath a submission.
     *
     * @return array
     */
    protected function define_submission_subplugin_structure() {
        return [
            new restore_path_element(
                $this->get_namefor('text'),
                $this->get_pathfor('/submission_refchecker_text'),
            ),
            new restore_path_element(
                $this->get_namefor('submission'),
                $this->get_pathfor('/submission_refchecker'),
            ),
            new restore_path_element(
                $this->get_namefor('reference'),
                $this->get_pathfor('/submission_refchecker/references/reference'),
            ),
        ];
    }

    /**
     * Restore the reference list a student pasted in.
     *
     * @param mixed $data
     * @return void
     */
    public function process_assignsubmission_refchecker_text($data) {
        global $DB;

        $data = (object) $data;
        unset($data->id);

        $data->assignment = $this->get_new_parentid('assign');
        // The mapping is set by the core assign restore when it processes the submission.
        $data->submission = $this->get_mappingid('submission', $data->submission);

        $DB->insert_record('assignsubmission_refchecker_text', $data);
    }

    /**
     * Restore one checking job.
     *
     * @param mixed $data
     * @return void
     */
    public function process_assignsubmission_refchecker_submission($data) {
        global $DB;

        $data = (object) $data;
        unset($data->id);

        $data->assignment = $this->get_new_parentid('assign');
        // The mapping is set by the core assign restore when it processes the submission.
        $data->submission = $this->get_mappingid('submission', $data->submission);

        $this->currentjobid = $DB->insert_record('assignsubmission_refchecker', $data);
    }

    /**
     * Restore one checked reference.
     *
     * @param mixed $data
     * @return void
     */
    public function process_assignsubmission_refchecker_reference($data) {
        global $DB;

        if (!$this->currentjobid) {
            return;
        }

        $data = (object) $data;
        unset($data->id);

        $data->jobid = $this->currentjobid;
        $data->submission = $DB->get_field(
            'assignsubmission_refchecker',
            'submission',
            ['id' => $this->currentjobid],
        );

        $DB->insert_record('assignsubmission_refchecker_refs', $data);
    }
}

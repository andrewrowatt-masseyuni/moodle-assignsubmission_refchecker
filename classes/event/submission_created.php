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

/**
 * A student saved a reference list for the first time on this submission.
 *
 * mod_assign's submission_created is abstract, so unless a subplugin triggers a subclass of it
 * nothing fires at all. With File submissions turned off this is the only thing that tells
 * {@see observer} a check is needed on save.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_created extends \mod_assign\event\submission_created {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        parent::init();
        $this->data['objecttable'] = 'assignsubmission_refchecker_text';
    }

    /**
     * Returns non-localised description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $description = "The user with id '$this->userid' submitted a reference list for the "
            . "assignment with course module id '$this->contextinstanceid'";

        if (!empty($this->other['groupid'])) {
            return $description . " for the group with id '{$this->other['groupid']}'.";
        }

        return $description . '.';
    }

    /**
     * The mapping used when this event is restored.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        // No mapping available for 'assignsubmission_refchecker_text'.
        return ['db' => 'assignsubmission_refchecker_text', 'restore' => \core\event\base::NOT_MAPPED];
    }
}

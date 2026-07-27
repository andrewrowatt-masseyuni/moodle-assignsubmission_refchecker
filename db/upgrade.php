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
 * Upgrade steps for Reference Checker
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_assignsubmission_refchecker_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072501) {
        // Replace the generated plugin skeleton schema with the real one.
        //
        // The skeleton table held a single placeholder 'value' column and was never released, so
        // there is no data worth migrating. Detect it by that column and rebuild all three tables
        // from install.xml rather than adding two dozen fields one at a time.
        $xmlfile = $CFG->dirroot . '/mod/assign/submission/refchecker/db/install.xml';
        $jobtable = new xmldb_table('assignsubmission_refchecker');

        if ($dbman->table_exists($jobtable) && $dbman->field_exists($jobtable, new xmldb_field('value'))) {
            $dbman->drop_table($jobtable);
        }

        foreach (
            [
                'assignsubmission_refchecker',
                'assignsubmission_refchecker_refs',
                'assignsubmission_refchecker_cache',
            ] as $tablename
        ) {
            if (!$dbman->table_exists(new xmldb_table($tablename))) {
                $dbman->install_one_table_from_xmldb_file($xmlfile, $tablename);
            }
        }

        upgrade_plugin_savepoint(true, 2026072501, 'assignsubmission', 'refchecker');
    }

    if ($oldversion < 2026072502) {
        // Count the issues alongside storing them, so the job level counters can be aggregated
        // in SQL rather than by decoding every reference's JSON.
        $table = new xmldb_table('assignsubmission_refchecker_refs');
        $field = new xmldb_field('numissues', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'predatory');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072502, 'assignsubmission', 'refchecker');
    }

    if ($oldversion < 2026072503) {
        // Pace requests to each external database. Needed once arXiv, DBLP and Semantic Scholar
        // are searchable: all three rate limit far more tightly than CrossRef and OpenAlex.
        $table = new xmldb_table('assignsubmission_refchecker_rate');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(
                $CFG->dirroot . '/mod/assign/submission/refchecker/db/install.xml',
                'assignsubmission_refchecker_rate',
            );
        }

        upgrade_plugin_savepoint(true, 2026072503, 'assignsubmission', 'refchecker');
    }

    if ($oldversion < 2026072504) {
        // Stand a repeatedly failing database down for a while instead of asking it once per
        // reference while it is unwell. DBLP in particular answers with 500s and empty replies
        // under load.
        $table = new xmldb_table('assignsubmission_refchecker_rate');

        $failures = new xmldb_field('failures', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'nextallowedms');
        if (!$dbman->field_exists($table, $failures)) {
            $dbman->add_field($table, $failures);
        }

        $skipuntil = new xmldb_field('skipuntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'failures');
        if (!$dbman->field_exists($table, $skipuntil)) {
            $dbman->add_field($table, $skipuntil);
        }

        upgrade_plugin_savepoint(true, 2026072504, 'assignsubmission', 'refchecker');
    }

    if ($oldversion < 2026072505) {
        // Hold the reference list a student pastes in, for assignments that ask for one instead of
        // reading the bibliography out of the submitted file.
        $table = new xmldb_table('assignsubmission_refchecker_text');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(
                $CFG->dirroot . '/mod/assign/submission/refchecker/db/install.xml',
                'assignsubmission_refchecker_text',
            );
        }

        upgrade_plugin_savepoint(true, 2026072505, 'assignsubmission', 'refchecker');
    }

    if ($oldversion < 2026072702) {
        // Bound the runs the pacer puts off. A deferral spends no reference attempt and refreshes
        // the job's timemodified on its way past, so without a count of its own a job could
        // reschedule itself indefinitely with nothing able to notice.
        $table = new xmldb_table('assignsubmission_refchecker');

        $deferrals = new xmldb_field('deferrals', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'requeues');
        if (!$dbman->field_exists($table, $deferrals)) {
            $dbman->add_field($table, $deferrals);
        }

        upgrade_plugin_savepoint(true, 2026072702, 'assignsubmission', 'refchecker');
    }

    return true;
}

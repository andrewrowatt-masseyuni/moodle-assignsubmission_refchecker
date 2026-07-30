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
 * Provides the information to backup Reference Checker results
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_assignsubmission_refchecker_subplugin extends backup_subplugin {
    /**
     * Attach the pasted reference list, the checking job and its references to each submission.
     *
     * The plugin owns no file areas and stores no user ids of its own, so there is nothing to
     * annotate: sitting under mod_assign's submission element is enough for a backup without
     * user data to exclude all of this automatically.
     *
     * @return backup_subplugin_element
     */
    protected function define_submission_subplugin_structure() {
        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());

        // Both of these carry the submission they belong to, which the restore turns back into the
        // id the new submission was given. The parent element supplies it on the way out but not on
        // the way back in, so leaving it out of the backup makes the restore unable to place them.
        $text = new backup_nested_element('submission_refchecker_text', ['id'], [
            'submission', 'referencetext', 'timecreated', 'timemodified',
        ]);

        $job = new backup_nested_element('submission_refchecker', ['id'], [
            'submission', 'status', 'generation', 'contenthash', 'totalrefs', 'checkedrefs',
            'verifiedrefs', 'partialrefs', 'mismatchrefs', 'notfoundrefs', 'issuerefs',
            'retractedrefs', 'predatoryrefs', 'oldestyear', 'newestyear', 'avgyear',
            'totalcitations', 'avgcitations', 'truncated', 'sectionheading',
            'timequeued', 'timestarted', 'timecompleted', 'timemodified',
        ]);

        $references = new backup_nested_element('references');
        $reference = new backup_nested_element('reference', ['id'], [
            'sortorder', 'rawref', 'sourcefile', 'refhash', 'status', 'attempts',
            'matchstatus', 'matchconfidence', 'titlescore', 'authorscore', 'journalscore',
            'source', 'sourcesconsulted', 'sourcesunavailable',
            'foundtitle', 'foundauthors', 'foundyear', 'foundjournal', 'doi', 'url',
            'citations', 'retracted', 'predatory', 'numissues', 'issues', 'timechecked',
        ]);

        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($text);
        $subpluginwrapper->add_child($job);
        $job->add_child($references);
        $references->add_child($reference);

        $text->set_source_table(
            'assignsubmission_refchecker_text',
            ['submission' => backup::VAR_PARENTID],
        );
        $job->set_source_table(
            'assignsubmission_refchecker',
            ['submission' => backup::VAR_PARENTID],
        );
        $reference->set_source_table(
            'assignsubmission_refchecker_refs',
            ['jobid' => backup::VAR_PARENTID],
        );

        return $subplugin;
    }
}

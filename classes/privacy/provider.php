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

namespace assignsubmission_refchecker\privacy;

use assignsubmission_refchecker\local\job_manager;
use core_privacy\local\metadata\collection;
// Aliased because this class is itself called provider.
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_assign\privacy\assign_plugin_request_data;
use mod_assign\privacy\assignsubmission_provider;
use mod_assign\privacy\assignsubmission_user_provider;
use mod_assign\privacy\useridlist;

/**
 * Privacy Subsystem for assignsubmission_refchecker.
 *
 * The plugin stores the reference list extracted from a student's submission and the result of
 * checking each entry, and it sends reference text to bibliographic databases outside the
 * institution. Both of those are declared here.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements assignsubmission_provider, assignsubmission_user_provider, metadata_provider {
    /**
     * Describe everything this plugin stores or transmits.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            job_manager::TABLE_JOB,
            [
                'status' => 'privacy:metadata:job:status',
                'totalrefs' => 'privacy:metadata:job:totalrefs',
                'sectionheading' => 'privacy:metadata:job:sectionheading',
                'timecompleted' => 'privacy:metadata:job:timecompleted',
            ],
            'privacy:metadata:job',
        );

        $collection->add_database_table(
            job_manager::TABLE_REFS,
            [
                'rawref' => 'privacy:metadata:refs:rawref',
                'matchstatus' => 'privacy:metadata:refs:matchstatus',
                'foundtitle' => 'privacy:metadata:refs:foundtitle',
                'timechecked' => 'privacy:metadata:refs:timechecked',
            ],
            'privacy:metadata:refs',
        );

        // Shared across the whole site and holding no student written text, but declared anyway:
        // a one way hash of something a person wrote is still derived from their data.
        $collection->add_database_table(
            job_manager::TABLE_CACHE,
            [
                'refhash' => 'privacy:metadata:cache:refhash',
                'payload' => 'privacy:metadata:cache:payload',
            ],
            'privacy:metadata:cache',
        );

        // Reference text leaves the institution. This is the disclosure that matters most, and
        // the reason the plugin ships a configurable privacy notice for students.
        $collection->add_external_location_link(
            'crossref',
            ['reference' => 'privacy:metadata:crossref:reference'],
            'privacy:metadata:crossref',
        );

        $collection->add_external_location_link(
            'openalex',
            ['reference' => 'privacy:metadata:openalex:reference'],
            'privacy:metadata:openalex',
        );

        return $collection;
    }

    /**
     * No additional contexts.
     *
     * Everything this plugin stores hangs off an assign submission, which mod_assign has already
     * accounted for by the time this is called.
     *
     * @param int $userid
     * @param contextlist $contextlist
     * @return void
     */
    public static function get_context_for_userid_within_submission(int $userid, contextlist $contextlist) {
    }

    /**
     * No additional users, for the same reason.
     *
     * @param useridlist $useridlist
     * @return void
     */
    public static function get_student_user_ids(useridlist $useridlist) {
    }

    /**
     * No additional users, for the same reason.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_userids_from_context(userlist $userlist) {
    }

    /**
     * Export the reference check for one submission.
     *
     * @param assign_plugin_request_data $exportdata
     * @return void
     */
    public static function export_submission_user_data(assign_plugin_request_data $exportdata) {
        global $DB;

        $submission = $exportdata->get_pluginobject();
        if (!$submission) {
            return;
        }

        $job = $DB->get_record(job_manager::TABLE_JOB, ['submission' => $submission->id]);
        if (!$job) {
            return;
        }

        $export = (object) [
            'status' => $job->status,
            'referencesfound' => (int) $job->totalrefs,
            'referenceschecked' => (int) $job->checkedrefs,
            'verified' => (int) $job->verifiedrefs,
            'partial' => (int) $job->partialrefs,
            'mismatch' => (int) $job->mismatchrefs,
            'notfound' => (int) $job->notfoundrefs,
            'sectionheading' => $job->sectionheading,
            'timecompleted' => $job->timecompleted ? transform::datetime($job->timecompleted) : null,
            'references' => [],
        ];

        foreach (job_manager::get_references((int) $job->id) as $reference) {
            $export->references[] = (object) [
                'reference' => $reference->rawref,
                'sourcefile' => $reference->sourcefile,
                'result' => $reference->matchstatus,
                'confidence' => $reference->matchconfidence,
                'matchedtitle' => $reference->foundtitle,
                'matchedauthors' => $reference->foundauthors,
                'matchedyear' => $reference->foundyear,
                'matchedjournal' => $reference->foundjournal,
                'doi' => $reference->doi,
                'issues' => $reference->issues,
                'searchedin' => $reference->source,
                'timechecked' => $reference->timechecked
                    ? transform::datetime($reference->timechecked)
                    : null,
            ];
        }

        $subcontext = array_merge(
            $exportdata->get_subcontext(),
            [get_string('privacy:path', 'assignsubmission_refchecker')],
        );

        writer::with_context($exportdata->get_context())->export_data($subcontext, $export);
    }

    /**
     * Delete every reference check belonging to an assignment.
     *
     * @param assign_plugin_request_data $requestdata
     * @return void
     */
    public static function delete_submission_for_context(assign_plugin_request_data $requestdata) {
        job_manager::delete_for_assignment((int) $requestdata->get_assignid());
    }

    /**
     * Delete the reference check for one user's submission.
     *
     * @param assign_plugin_request_data $deletedata
     * @return void
     */
    public static function delete_submission_for_userid(assign_plugin_request_data $deletedata) {
        $submission = $deletedata->get_pluginobject();
        if (!$submission) {
            return;
        }

        job_manager::delete_for_submission((int) $submission->id);
    }

    /**
     * Delete the reference checks for a set of submissions.
     *
     * @param assign_plugin_request_data $deletedata
     * @return void
     */
    public static function delete_submissions(assign_plugin_request_data $deletedata) {
        global $DB;

        $submissionids = $deletedata->get_submissionids();
        if (!$submissionids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED);

        $DB->delete_records_select(job_manager::TABLE_REFS, "submission $insql", $params);
        $DB->delete_records_select(job_manager::TABLE_JOB, "submission $insql", $params);

        job_manager::reset_caches();
    }
}

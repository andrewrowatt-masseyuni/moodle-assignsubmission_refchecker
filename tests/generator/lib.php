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

use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\text_submission;

/**
 * Test data generator for Reference Checker.
 *
 * Lets tests and behat scenarios seed a finished, part-finished or failed check without touching
 * the network, which is what makes the display side independently testable.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignsubmission_refchecker_generator extends component_generator_base {
    /**
     * Create a checking job for a submission.
     *
     * @param array $record Any job column may be overridden; submission is required.
     * @return stdClass The created job.
     */
    public function create_job(array $record): stdClass {
        global $DB;

        if (empty($record['submission'])) {
            throw new coding_exception('A submission id is required to create a refchecker job.');
        }

        $now = time();
        $record = array_merge([
            'assignment' => 0,
            'status' => job_status::COMPLETE,
            'generation' => 1,
            'requeues' => 0,
            'contenthash' => sha1('generator'),
            'totalrefs' => 0,
            'checkedrefs' => 0,
            'verifiedrefs' => 0,
            'partialrefs' => 0,
            'mismatchrefs' => 0,
            'notfoundrefs' => 0,
            'issuerefs' => 0,
            'retractedrefs' => 0,
            'predatoryrefs' => 0,
            'oldestyear' => null,
            'newestyear' => null,
            'avgyear' => null,
            'totalcitations' => 0,
            'avgcitations' => null,
            'truncated' => 0,
            'sectionheading' => 'References',
            'errorcode' => null,
            'errormessage' => null,
            'timequeued' => $now,
            'timestarted' => $now,
            'timecompleted' => $now,
            'timemodified' => $now,
        ], $record);

        // A job that has not finished should not claim a completion time.
        if (!job_status::is_terminal($record['status'])) {
            $record['timecompleted'] = 0;
        }

        $record['id'] = $DB->insert_record(job_manager::TABLE_JOB, (object) $record);
        job_manager::reset_caches();

        return (object) $record;
    }

    /**
     * Store a pasted reference list against a submission.
     *
     * @param stdClass $submission An assign_submission record.
     * @param string $text The reference list as a student would have pasted it.
     * @return stdClass The stored record.
     */
    public function create_text_submission(stdClass $submission, string $text): stdClass {
        text_submission::save(
            (int) $submission->assignment,
            (int) $submission->id,
            $text,
        );

        return text_submission::get((int) $submission->id);
    }

    /**
     * A short reference list in the shape a student would paste in, with no heading.
     *
     * @param int $count How many references to produce.
     * @return string
     */
    public function pasted_reference_list(int $count = 3): string {
        $references = [];
        for ($index = 1; $index <= $count; $index++) {
            $references[] = "Author, A. (20{$index}0). Reference number {$index}. "
                . "Journal of Things, {$index}(1), 1-10.";
        }

        return implode("\n", $references);
    }

    /**
     * Create a single reference belonging to a job.
     *
     * @param stdClass $job
     * @param array $record Any reference column may be overridden.
     * @return stdClass The created reference.
     */
    public function create_reference(stdClass $job, array $record = []): stdClass {
        global $DB;

        $rawref = $record['rawref'] ?? 'Author, A. (2020). A paper about things. Journal of Things, 1(1), 1-10.';

        $record = array_merge([
            'jobid' => $job->id,
            'submission' => $job->submission,
            'sortorder' => $DB->count_records(job_manager::TABLE_REFS, ['jobid' => $job->id]),
            'rawref' => $rawref,
            'sourcefile' => 'essay.pdf',
            'refhash' => sha1(core_text::strtolower(trim($rawref))),
            'status' => job_status::REF_CHECKED,
            'attempts' => 1,
            'matchstatus' => match_status::VERIFIED,
            'matchconfidence' => 95,
            'titlescore' => 98,
            'authorscore' => 100,
            'journalscore' => 90,
            'source' => 'crossref',
            'sourcesconsulted' => 'crossref',
            'sourcesunavailable' => null,
            'foundtitle' => 'A paper about things',
            'foundauthors' => json_encode(['Ann Author']),
            'foundyear' => 2020,
            'foundjournal' => 'Journal of Things',
            'doi' => '10.1234/things.2020.1',
            'url' => 'https://doi.org/10.1234/things.2020.1',
            'citations' => 42,
            'retracted' => 0,
            'predatory' => 0,
            'issues' => json_encode([]),
            'formatted' => json_encode([
                'apa' => 'Author, A. (2020). A paper about things. Journal of Things.',
                'mla' => 'Author, Ann. "A paper about things." Journal of Things, 2020.',
                'iso690' => 'AUTHOR, Ann. A paper about things. Journal of Things, 2020.',
                'bibtex' => '@article{Author2020A, title={A paper about things}}',
            ]),
            'errormessage' => null,
            'timechecked' => time(),
        ], $record);

        $record['id'] = $DB->insert_record(job_manager::TABLE_REFS, (object) $record);

        return (object) $record;
    }

    /**
     * Create a job with a spread of results, and keep the job's counters consistent with them.
     *
     * @param array $jobrecord Passed through to create_job(); submission is required.
     * @param array $statuses One match status per reference to create.
     * @return stdClass The job, with counters updated.
     */
    public function create_job_with_references(array $jobrecord, array $statuses): stdClass {
        global $DB;

        $job = $this->create_job($jobrecord);

        $counts = array_fill_keys(match_status::all(), 0);
        foreach ($statuses as $index => $status) {
            $this->create_reference($job, [
                'sortorder' => $index,
                'matchstatus' => $status,
                'matchconfidence' => match ($status) {
                    match_status::VERIFIED => 95,
                    match_status::PARTIAL => 65,
                    match_status::MISMATCH => 20,
                    default => 0,
                },
                'rawref' => "Author, A. (20{$index}0). Reference number {$index}. Journal of Things.",
                'foundtitle' => $status === match_status::NOTFOUND ? null : "Reference number {$index}",
                'source' => $status === match_status::NOTFOUND ? null : 'crossref',
                // Nothing was found, so every database was asked before giving up.
                'sourcesconsulted' => $status === match_status::NOTFOUND
                    ? 'crossref,openalex,arxiv,dblp'
                    : 'crossref',
                'foundyear' => $status === match_status::NOTFOUND ? null : 2000 + $index,
                'citations' => $status === match_status::NOTFOUND ? null : 10 * $index,
            ]);
            $counts[$status]++;
        }

        $job->totalrefs = count($statuses);
        $job->checkedrefs = count($statuses);
        $job->verifiedrefs = $counts[match_status::VERIFIED];
        $job->partialrefs = $counts[match_status::PARTIAL];
        $job->mismatchrefs = $counts[match_status::MISMATCH];
        $job->notfoundrefs = $counts[match_status::NOTFOUND];

        $DB->update_record(job_manager::TABLE_JOB, $job);
        job_manager::reset_caches();

        return $job;
    }
}

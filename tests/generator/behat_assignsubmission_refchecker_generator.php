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

/**
 * Behat data generator for assignsubmission_refchecker.
 *
 * Checking a reference list means calling external databases, which Behat cannot do, so scenarios
 * about the report seed a finished check directly rather than trying to produce one.
 *
 * Jobs and references hang off a submission, and a submission has no name a scenario can refer to,
 * so both entities are addressed by assignment and user and the submission is looked up here.
 *
 * @package   assignsubmission_refchecker
 * @category  test
 * @copyright 2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_assignsubmission_refchecker_generator extends behat_generator_base {
    /**
     * Get a list of the entities that Behat can create using the generator step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'jobs' => [
                'singular' => 'job',
                'datagenerator' => 'job',
                'required' => ['assign', 'user'],
            ],
            'references' => [
                'singular' => 'reference',
                'datagenerator' => 'reference',
                'required' => ['assign', 'user'],
            ],
        ];
    }

    /**
     * Turn the assignment and user a scenario named into the submission the job hangs off.
     *
     * @param array $data
     * @return array
     */
    protected function preprocess_job(array $data): array {
        $submission = $this->get_submission($data);

        unset($data['assign'], $data['user']);
        $data['submission'] = (int) $submission->id;
        $data['assignment'] = (int) $submission->assignment;

        return $data;
    }

    /**
     * References are addressed the same way, and resolved to the same submission.
     *
     * @param array $data
     * @return array
     */
    protected function preprocess_reference(array $data): array {
        return $this->preprocess_job($data);
    }

    /**
     * Create a job, standing in for a check that has already run.
     *
     * Named process_ rather than create_ because the base class dispatches to that first; the
     * component's own create_job() would otherwise be called instead, which is what we want here
     * but is not what we want for references.
     *
     * @param array $data
     */
    protected function process_job(array $data): void {
        $this->componentdatagenerator->create_job($data);
    }

    /**
     * Create a reference against a submission's job.
     *
     * @param array $data
     */
    protected function process_reference(array $data): void {
        $submissionid = (int) $data['submission'];
        unset($data['submission'], $data['assignment']);

        $job = job_manager::load($submissionid);
        if (!$job) {
            throw new coding_exception(
                'Create a refchecker job for this submission before adding references to it.',
            );
        }

        $this->componentdatagenerator->create_reference($job, $data);

        // The report reads its counts off the job rather than off the references, so a scenario
        // adding references one at a time would otherwise render a report claiming there are none.
        $this->recount($job);
    }

    /**
     * Bring a job's denormalised counters back into line with its references.
     *
     * @param stdClass $job
     */
    protected function recount(stdClass $job): void {
        global $DB;

        $references = job_manager::get_references((int) $job->id);

        $counts = ['verified' => 0, 'partial' => 0, 'mismatch' => 0, 'notfound' => 0];
        $issues = 0;
        $retracted = 0;
        $predatory = 0;

        foreach ($references as $reference) {
            $status = (string) $reference->matchstatus;
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            $issues += $reference->numissues > 0 ? 1 : 0;
            $retracted += !empty($reference->retracted) ? 1 : 0;
            $predatory += !empty($reference->predatory) ? 1 : 0;
        }

        $job->totalrefs = count($references);
        $job->checkedrefs = count($references);
        $job->verifiedrefs = $counts['verified'];
        $job->partialrefs = $counts['partial'];
        $job->mismatchrefs = $counts['mismatch'];
        $job->notfoundrefs = $counts['notfound'];
        $job->issuerefs = $issues;
        $job->retractedrefs = $retracted;
        $job->predatoryrefs = $predatory;

        $DB->update_record(job_manager::TABLE_JOB, $job);
        job_manager::reset_caches();
    }

    /**
     * The submission a scenario's assignment and user identify, created if it does not exist.
     *
     * @param array $data Needing assign (activity name or idnumber) and user (username).
     * @return stdClass The assign_submission record.
     */
    protected function get_submission(array $data): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $cm = $this->get_cm_by_activity_name('assign', $data['assign']);
        $context = context_module::instance($cm->id);
        $assign = new assign($context, $cm, $cm->get_course());

        return $assign->get_user_submission($this->get_user_id($data['user']), true);
    }
}

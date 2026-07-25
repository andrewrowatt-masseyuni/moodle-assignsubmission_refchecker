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

use assignsubmission_refchecker\local\extractor;
use assignsubmission_refchecker\local\job_manager;
use assignsubmission_refchecker\local\job_status;
use assignsubmission_refchecker\local\reference_parser;
use core\task\adhoc_task;
use core\task\manager;
use stdClass;
use stored_file;

/**
 * Reads the reference list out of a student's submitted files.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extract_references extends adhoc_task {
    use job_task;

    /**
     * The file area the File submissions plugin stores its uploads in.
     *
     * Mirrors ASSIGNSUBMISSION_FILE_FILEAREA. Repeated rather than referenced because that
     * constant only exists once the other plugin's locallib has been loaded, which is not
     * something a background task should have to depend on.
     *
     * @var string
     */
    protected const FILE_SUBMISSION_AREA = 'submission_files';

    /**
     * The task's name, as shown in the task administration screens.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_extract', 'assignsubmission_refchecker');
    }

    /**
     * Extract, parse and queue the references for checking.
     *
     * Exceptions are deliberately allowed to propagate: that is how Moodle's task API is told to
     * retry with a backoff. Catching them would tell it the work had succeeded.
     *
     * @return void
     */
    public function execute(): void {
        [$job, $submission] = $this->load_current_job();
        if (!$job) {
            return;
        }

        $assign = $this->load_assign($submission);
        if (!$assign) {
            job_manager::set_status($job, job_status::CANCELLED);
            return;
        }

        $files = $this->candidate_files($assign, $submission);
        if (!$files) {
            mtrace('  Nothing scannable was submitted.');
            job_manager::set_status($job, job_status::NOTAPPLICABLE);
            return;
        }

        // An unchanged resubmission does not need checking again.
        $contenthash = $this->content_hash($files);
        if ($job->contenthash === $contenthash && (int) $job->totalrefs > 0) {
            mtrace('  Files are unchanged since the last completed check.');
            job_manager::set_status($job, job_status::COMPLETE);
            return;
        }

        job_manager::set_status($job, job_status::EXTRACTING);

        [$references, $heading] = $this->extract_all($files);

        if (!$references) {
            mtrace('  No reference list could be found.');
            job_manager::set_status($job, job_status::NOREFS, ['contenthash' => $contenthash]);
            return;
        }

        $maxreferences = $this->max_references($assign);
        $truncated = count($references) > $maxreferences;
        if ($truncated) {
            $references = array_slice($references, 0, $maxreferences);
        }

        job_manager::store_references($job, $references, [
            'heading' => $heading,
            'truncated' => $truncated,
            'contenthash' => $contenthash,
        ]);

        mtrace('  Found ' . count($references) . ' reference(s)' . ($truncated ? ' (truncated)' : '') . '.');

        $remaining = $this->prefill_from_cache($job);
        $job = job_manager::recalculate_counters($job);

        if ($remaining === 0) {
            mtrace('  Every reference was already in the cache.');
            job_manager::set_status($job, job_status::COMPLETE);
            $this->trigger_completed($job, $assign);
            return;
        }

        job_manager::set_status($job, job_status::CHECKING);
        $this->queue_next(check_references::class, $job);
    }

    /**
     * The submitted files worth reading.
     *
     * @param \assign $assign
     * @param stdClass $submission
     * @return stored_file[]
     */
    protected function candidate_files(\assign $assign, stdClass $submission): array {
        $fs = get_file_storage();

        $files = $fs->get_area_files(
            $assign->get_context()->id,
            'assignsubmission_file',
            self::FILE_SUBMISSION_AREA,
            $submission->id,
            'sortorder, filename',
            false,
        );

        return array_values(array_filter($files, static fn(stored_file $file) => extractor::is_candidate($file)));
    }

    /**
     * A fingerprint of the files, so an unchanged resubmission can be recognised.
     *
     * @param stored_file[] $files
     * @return string
     */
    protected function content_hash(array $files): string {
        $hashes = array_map(static fn(stored_file $file) => $file->get_contenthash(), $files);
        sort($hashes);

        return sha1(implode(':', $hashes));
    }

    /**
     * Extract and parse every candidate file, merging the results.
     *
     * One unreadable file among several is recorded and stepped over rather than abandoning the
     * whole submission, because an appendix that will not open should not stop the essay being
     * checked.
     *
     * @param stored_file[] $files
     * @return array{0: array, 1: string} The references, and the heading they were found under.
     */
    protected function extract_all(array $files): array {
        $references = [];
        $seen = [];
        $heading = '';

        foreach ($files as $file) {
            $extracted = extractor::extract($file);

            if ($extracted['result'] !== extractor::RESULT_OK) {
                mtrace('  Skipped ' . $file->get_filename() . ': ' . $extracted['result']);
                continue;
            }

            $parsed = reference_parser::parse($extracted['text']);
            if (!$parsed['found']) {
                mtrace('  No reference list in ' . $file->get_filename() . '.');
                continue;
            }

            if ($heading === '') {
                $heading = $parsed['heading'];
            }

            foreach ($parsed['references'] as $reference) {
                $hash = job_manager::reference_hash($reference['raw']);
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;

                $reference['sourcefile'] = $file->get_filename();
                $references[] = $reference;
            }
        }

        return [$references, $heading];
    }

    /**
     * Satisfy whatever references have already been looked up for someone else.
     *
     * Students on a course cite much the same literature, so after the first few submissions this
     * removes a large share of the external requests, which is the main reason checking stays
     * inside the services' rate limits at cohort scale.
     *
     * @param stdClass $job
     * @return int How many references still need checking.
     */
    protected function prefill_from_cache(stdClass $job): int {
        $remaining = 0;
        $hits = 0;

        foreach (job_manager::get_references((int) $job->id) as $reference) {
            $cached = job_manager::cache_get($reference->refhash);
            if ($cached === null) {
                $remaining++;
                continue;
            }

            job_manager::record_result($reference, $cached);
            $hits++;
        }

        if ($hits) {
            mtrace('  ' . $hits . ' reference(s) served from the cache.');
        }

        return $remaining;
    }

    /**
     * The reference cap for this assignment.
     *
     * @param \assign $assign
     * @return int
     */
    protected function max_references(\assign $assign): int {
        $plugin = $assign->get_submission_plugin_by_type('refchecker');
        $configured = (int) $plugin->get_config('maxreferences');
        if ($configured > 0) {
            return $configured;
        }

        return (int) get_config('assignsubmission_refchecker', 'maxreferences') ?: 200;
    }
}

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

namespace assignsubmission_refchecker\local;

use stdClass;

/**
 * The reference list a student pasted into the submission form.
 *
 * Kept apart from {@see job_manager} deliberately. This is the student's own work and shares the
 * assignment's submission lifecycle: it survives a re-check, is copied forward when an attempt is
 * reopened, goes into course backups and comes out in a privacy export. A checking job is derived
 * data that can be thrown away and rebuilt at any time.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_submission {
    /** @var string The pasted reference list table. */
    public const TABLE = 'assignsubmission_refchecker_text';

    /**
     * Per-request cache of stored lists, keyed by submission id.
     *
     * The grading table asks about every row it renders — through is_empty() and again through
     * view_summary() — so without this a 300 student assignment would issue hundreds of queries to
     * draw one page. Mirrors the cache in {@see job_manager}.
     *
     * @var array<int, stdClass|null>
     */
    protected static array $cache = [];

    /**
     * Assignment ids whose lists have already been bulk loaded into the cache.
     *
     * @var array<int, true>
     */
    protected static array $primed = [];

    /**
     * The stored record for a submission, priming the whole assignment on first use.
     *
     * Display code should use this. Background tasks must use {@see get()} instead, because they
     * write and re-read and a stale value would defeat the generation guard.
     *
     * @param stdClass $submission An assign_submission record, needing at least id and assignment.
     * @return stdClass|null
     */
    public static function for_submission(stdClass $submission): ?stdClass {
        global $DB;

        $assignmentid = (int) ($submission->assignment ?? 0);
        $submissionid = (int) $submission->id;

        if ($assignmentid && !isset(self::$primed[$assignmentid])) {
            foreach ($DB->get_records(self::TABLE, ['assignment' => $assignmentid]) as $record) {
                self::$cache[(int) $record->submission] = $record;
            }
            self::$primed[$assignmentid] = true;
        }

        if (array_key_exists($submissionid, self::$cache)) {
            return self::$cache[$submissionid];
        }

        return self::$cache[$submissionid] = self::get($submissionid);
    }

    /**
     * The pasted reference list for a submission, from the per-request cache.
     *
     * @param stdClass $submission An assign_submission record.
     * @return string Empty when the student has not pasted anything.
     */
    public static function text_for_submission(stdClass $submission): string {
        $record = self::for_submission($submission);

        return $record ? (string) $record->referencetext : '';
    }

    /**
     * Forget everything cached for this request.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$cache = [];
        self::$primed = [];
    }

    /**
     * The stored record for a submission, straight from the database.
     *
     * @param int $submissionid
     * @return stdClass|null
     */
    public static function get(int $submissionid): ?stdClass {
        global $DB;

        if (!$submissionid) {
            return null;
        }

        return $DB->get_record(self::TABLE, ['submission' => $submissionid]) ?: null;
    }

    /**
     * The pasted reference list for a submission, straight from the database.
     *
     * @param int $submissionid
     * @return string Empty when the student has not pasted anything.
     */
    public static function text_for(int $submissionid): string {
        $record = self::get($submissionid);

        return $record ? (string) $record->referencetext : '';
    }

    /**
     * Store the pasted reference list, replacing anything already held.
     *
     * An empty string is stored rather than deleted, so that clearing the box is distinguishable
     * from never having filled it in when reading the form back.
     *
     * @param int $assignmentid
     * @param int $submissionid
     * @param string $text
     * @return void
     */
    public static function save(int $assignmentid, int $submissionid, string $text): void {
        global $DB;

        $now = time();
        $record = self::get($submissionid);

        if ($record) {
            $record->referencetext = $text;
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, (object) [
                'assignment' => $assignmentid,
                'submission' => $submissionid,
                'referencetext' => $text,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        unset(self::$cache[$submissionid]);
    }

    /**
     * Remove the pasted reference list for a single submission.
     *
     * @param int $submissionid
     * @return void
     */
    public static function delete_for_submission(int $submissionid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['submission' => $submissionid]);

        unset(self::$cache[$submissionid]);
    }

    /**
     * Remove every pasted reference list belonging to an assignment.
     *
     * @param int $assignmentid
     * @return void
     */
    public static function delete_for_assignment(int $assignmentid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['assignment' => $assignmentid]);

        self::reset_caches();
    }

    /**
     * Carry a pasted reference list onto another submission.
     *
     * Used when an attempt is reopened, so the student starts from what they wrote last time in
     * exactly the way the File submissions plugin copies their files forward.
     *
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     * @return void
     */
    public static function copy_to_submission(stdClass $sourcesubmission, stdClass $destsubmission): void {
        $source = self::get((int) $sourcesubmission->id);
        if (!$source) {
            return;
        }

        self::save(
            (int) $destsubmission->assignment,
            (int) $destsubmission->id,
            (string) $source->referencetext,
        );
    }
}

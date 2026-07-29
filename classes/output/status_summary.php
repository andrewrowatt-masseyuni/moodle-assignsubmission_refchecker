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

namespace assignsubmission_refchecker\output;

use assignsubmission_refchecker\local\display_level;
use assignsubmission_refchecker\local\job_status;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The compact status line shown in the submission status table and the grading table.
 *
 * This renders once per grading table row, so it stays deliberately small: a status line, and at
 * summary level or above a row of counts. Everything larger belongs in the report.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_summary implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass|null $job The job record, or null when the submission has never been checked.
     * @param int $level The viewer's effective display level.
     * @param bool $isteacher Whether the viewer may see operational detail such as error codes.
     * @param bool $textsource Whether the references came from a pasted list rather than a file,
     *                         which changes what advice is useful when none were recognised.
     */
    public function __construct(
        /** @var stdClass|null The job record. */
        protected ?stdClass $job,
        /** @var int The viewer's effective display level. */
        protected int $level,
        /** @var bool Whether the viewer may see operational detail. */
        protected bool $isteacher,
        /** @var bool Whether the references came from a pasted list rather than a file. */
        protected bool $textsource = false,
    ) {
    }

    /**
     * Whether there is anything worth rendering at all.
     *
     * A submission that has never been checked, or one with nothing scannable in it, shows nothing
     * to students. Teachers still get a quiet note so they can tell the difference between "not
     * configured" and "nothing to do".
     *
     * @return bool
     */
    public function has_content(): bool {
        if ($this->job === null) {
            return false;
        }
        if (display_level::shows_nothing($this->level)) {
            // Not even the status line. The level is the effective one, so it can only be NONE for
            // someone without the capability: a teacher is never silenced by it.
            return false;
        }
        if ($this->job->status === job_status::NOTAPPLICABLE || $this->job->status === job_status::CANCELLED) {
            return $this->isteacher;
        }
        if ($this->job->status === job_status::FAILED) {
            return true;
        }
        return true;
    }

    /**
     * Export for the status template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $job = $this->job;

        $status = $job->status;
        $counts = $this->counts();

        return [
            'submissionid' => (int) $job->submission,
            'status' => $status,
            'statuslabel' => job_status::label(
                $status,
                (int) $job->checkedrefs,
                (int) $job->totalrefs,
            ),
            'inprogress' => !job_status::is_terminal($status),
            'iscomplete' => $status === job_status::COMPLETE,
            'isfailed' => $status === job_status::FAILED,
            'isnorefs' => $status === job_status::NOREFS,
            'norefsguidance' => $status === job_status::NOREFS
                ? get_string(
                    $this->textsource ? 'norefs_guidance_text' : 'norefs_guidance',
                    'assignsubmission_refchecker',
                )
                : '',
            'showcounts' => $counts !== [],
            'counts' => $counts,
            // Operational detail is never exposed to students.
            'showdiagnostics' => $this->isteacher && !empty($job->errorcode),
            'errorcode' => $this->isteacher ? (string) $job->errorcode : '',
            'errormessage' => $this->isteacher ? (string) $job->errormessage : '',
        ];
    }

    /**
     * The badges shown under the status line, if any.
     *
     * At the status level, the number of references found and nothing else: that is a fact about the
     * student's own submission rather than a result of checking it. At summary level or above, once
     * checking has finished, one badge per match status — which says everything the bare total would
     * and more, so the two never both appear.
     *
     * @return array<int, array{label: string, count: int, labelclass: string}>
     */
    protected function counts(): array {
        $job = $this->job;

        if ((int) $job->totalrefs === 0) {
            return [];
        }

        if ($this->level === display_level::STATUS_ONLY) {
            return [
                [
                    'label' => get_string('dashboard_total', 'assignsubmission_refchecker'),
                    'count' => (int) $job->totalrefs,
                    'labelclass' => 'total',
                ],
            ];
        }

        if ($this->level < display_level::SUMMARY || $job->status !== job_status::COMPLETE) {
            return [];
        }

        return [
            [
                'label' => get_string('dashboard_verified', 'assignsubmission_refchecker'),
                'count' => (int) $job->verifiedrefs,
                'labelclass' => 'verified',
            ],
            [
                'label' => get_string('dashboard_partial', 'assignsubmission_refchecker'),
                'count' => (int) $job->partialrefs,
                'labelclass' => 'partial',
            ],
            [
                'label' => get_string('dashboard_mismatch', 'assignsubmission_refchecker'),
                'count' => (int) $job->mismatchrefs,
                'labelclass' => 'mismatch',
            ],
            [
                'label' => get_string('dashboard_notfound', 'assignsubmission_refchecker'),
                'count' => (int) $job->notfoundrefs,
                'labelclass' => 'notfound',
            ],
        ];
    }
}

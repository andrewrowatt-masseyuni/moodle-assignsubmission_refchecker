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

/**
 * The lifecycle states of a reference checking job, and the states of the individual references.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_status {
    /** @var string Nothing scannable was submitted, so there is nothing to do. */
    public const NOTAPPLICABLE = 'notapplicable';

    /** @var string Queued, waiting for the background task to pick it up. */
    public const PENDING = 'pending';

    /** @var string Reading text out of the submitted files. */
    public const EXTRACTING = 'extracting';

    /** @var string Looking references up in the external databases. */
    public const CHECKING = 'checking';

    /** @var string Files were read successfully but contained no recognisable reference list. */
    public const NOREFS = 'norefs';

    /** @var string Every reference has been checked. */
    public const COMPLETE = 'complete';

    /** @var string Checking could not be completed. */
    public const FAILED = 'failed';

    /** @var string Superseded by a newer submission before it finished. */
    public const CANCELLED = 'cancelled';

    /** @var string A reference waiting to be checked. Doubles as the chunking cursor. */
    public const REF_QUEUED = 'queued';

    /** @var string A reference that has been checked. */
    public const REF_CHECKED = 'checked';

    /** @var string A reference that could not be checked. */
    public const REF_ERROR = 'error';

    /**
     * Job states that mean work is still outstanding.
     *
     * Used by the reconcile task to find jobs that have stalled.
     *
     * @return string[]
     */
    public static function in_progress_states(): array {
        return [self::PENDING, self::EXTRACTING, self::CHECKING];
    }

    /**
     * Whether this state means the job will make no further progress.
     *
     * @param string $status
     * @return bool
     */
    public static function is_terminal(string $status): bool {
        return !in_array($status, self::in_progress_states(), true);
    }

    /**
     * The short status line shown to students and teachers.
     *
     * Only the checking state interpolates values, so the caller passes the job for that case.
     *
     * @param string $status
     * @param int $checkedrefs
     * @param int $totalrefs
     * @return string
     */
    public static function label(string $status, int $checkedrefs = 0, int $totalrefs = 0): string {
        if ($status === self::CHECKING) {
            $a = (object) [
                'done' => $checkedrefs,
                'total' => $totalrefs,
                'percent' => $totalrefs > 0 ? (int) round(100 * $checkedrefs / $totalrefs) : 0,
            ];
            return get_string('status_progress', 'assignsubmission_refchecker', $a);
        }

        return get_string('status_' . $status, 'assignsubmission_refchecker');
    }
}

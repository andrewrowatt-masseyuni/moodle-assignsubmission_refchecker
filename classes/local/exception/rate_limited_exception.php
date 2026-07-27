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

namespace assignsubmission_refchecker\local\exception;

/**
 * The external service asked us to slow down.
 *
 * Handled differently from other transient failures: the task reschedules itself for the moment
 * the service nominated and returns normally, rather than throwing. Throwing would work, but it
 * would also inflate Moodle's fail delay for what is a completely routine, expected event.
 *
 * Because it extends transient_exception, every handler that catches transient failures must catch
 * this first or it will be swallowed as an ordinary fault. Both callers do
 * ({@see \assignsubmission_refchecker\local\source\chain::check()} and
 * {@see \assignsubmission_refchecker\task\check_references::check_one()}), and the message is
 * translated because it has historically ended up stored against a reference and shown to students
 * when one of them did not.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limited_exception extends transient_exception {
    /** @var int Default wait when the service does not nominate one. */
    public const DEFAULT_RETRY_AFTER = 60;

    /** @var int Never wait longer than this, however large the Retry-After header. */
    public const MAX_RETRY_AFTER = 3600;

    /**
     * Constructor.
     *
     * @param int $retryafter Seconds to wait, as taken from the Retry-After header.
     * @param string|null $message Defaults to the translated wording. Callers describing an
     *      internal decision rather than a service's answer should pass their own.
     */
    public function __construct(
        /** @var int Seconds to wait before trying again. */
        protected int $retryafter = self::DEFAULT_RETRY_AFTER,
        ?string $message = null,
    ) {
        // Resolved here rather than as a default argument, which PHP will not allow to be a call.
        parent::__construct($message ?? get_string('error_ratelimited', 'assignsubmission_refchecker'));
    }

    /**
     * How long to wait before trying again, clamped to something sane.
     *
     * @return int Seconds.
     */
    public function get_retry_after(): int {
        return max(1, min($this->retryafter, self::MAX_RETRY_AFTER));
    }
}

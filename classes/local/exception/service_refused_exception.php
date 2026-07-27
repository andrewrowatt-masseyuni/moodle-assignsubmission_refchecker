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
 * A database refused a request outright, which these services signal with HTTP 429.
 *
 * Named for what happened rather than for the status code, because a 429 from them is not reliably
 * a statement about our request rate. Semantic Scholar returns one to a keyed caller pacing itself
 * at a third of its documented allowance, minutes after the previous request: it is how the service
 * reports being overloaded, much as DBLP reports the same condition with a 500. So this is an
 * ordinary transient failure of one source, left to the circuit breaker to stand down, and the
 * other databases still get asked.
 *
 * Contrast {@see rate_limited_exception}, which is this plugin's own pacer declining to send a
 * request yet. That one is genuinely about our rate, applies to the whole task, and is the only
 * thing that pauses it.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_refused_exception extends transient_exception {
    /** @var int Stand-down to assume when the service names no duration of its own. */
    public const DEFAULT_RETRY_AFTER = 60;

    /** @var int However large the Retry-After header, try the source again at least this often. */
    public const MAX_RETRY_AFTER = 3600;

    /**
     * Constructor.
     *
     * @param string $message
     * @param int $retryafter Seconds, as taken from the Retry-After header.
     */
    public function __construct(
        string $message,
        /** @var int Seconds the service asked us to wait for. */
        protected int $retryafter = self::DEFAULT_RETRY_AFTER,
    ) {
        parent::__construct($message);
    }

    /**
     * The shortest stand-down that still respects what the service asked for.
     *
     * Clamped at both ends: a source that answers with a year of Retry-After is not allowed to
     * remove itself from the chain indefinitely, and one that asks for nothing at all still gets
     * left alone until the next run.
     *
     * @return int Seconds.
     */
    public function get_retry_after(): int {
        return max(1, min($this->retryafter, self::MAX_RETRY_AFTER));
    }
}

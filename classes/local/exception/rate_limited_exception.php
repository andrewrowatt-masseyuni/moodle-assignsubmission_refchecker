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
     * @param string $message
     */
    public function __construct(
        /** @var int Seconds to wait before trying again. */
        protected int $retryafter = self::DEFAULT_RETRY_AFTER,
        string $message = 'Rate limited by the external service',
    ) {
        parent::__construct($message);
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

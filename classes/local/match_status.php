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
 * How well a single reference matched a record in one of the external databases.
 *
 * The thresholds are those used by the References-Validation project
 * (https://github.com/zabbonat/References-Validation, MIT, Diletta Abbonato), so that results
 * remain comparable with that tool.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class match_status {
    /** @var string Found, and the details line up. */
    public const VERIFIED = 'verified';

    /** @var string Found, but some of the details differ. */
    public const PARTIAL = 'partial';

    /** @var string Something was found, but it does not look like the work that was cited. */
    public const MISMATCH = 'mismatch';

    /** @var string Nothing matching was found in any of the databases searched. */
    public const NOTFOUND = 'notfound';

    /** @var int At or above this confidence a match is treated as verified. */
    public const THRESHOLD_VERIFIED = 80;

    /** @var int At or above this confidence a match is treated as partial. */
    public const THRESHOLD_PARTIAL = 50;

    /**
     * Classify a confidence score.
     *
     * @param bool $found Whether any record was matched at all.
     * @param int $confidence Overall confidence, 0-100.
     * @return string One of the class constants.
     */
    public static function from_confidence(bool $found, int $confidence): string {
        if (!$found) {
            return self::NOTFOUND;
        }
        if ($confidence >= self::THRESHOLD_VERIFIED) {
            return self::VERIFIED;
        }
        if ($confidence >= self::THRESHOLD_PARTIAL) {
            return self::PARTIAL;
        }
        return self::MISMATCH;
    }

    /**
     * All statuses, in the order they are counted and displayed.
     *
     * @return string[]
     */
    public static function all(): array {
        return [self::VERIFIED, self::PARTIAL, self::MISMATCH, self::NOTFOUND];
    }

    /**
     * The human readable label for a status.
     *
     * @param string $status
     * @return string
     */
    public static function label(string $status): string {
        return get_string('match_' . $status, 'assignsubmission_refchecker');
    }

    /**
     * The Bootstrap contextual suffix used for this status' badge.
     *
     * Deliberately never "danger" for notfound: a correctly cited book that simply is not indexed
     * would otherwise be presented to the student as an error.
     *
     * @param string $status
     * @return string
     */
    public static function badge_class(string $status): string {
        return match ($status) {
            self::VERIFIED => 'success',
            self::PARTIAL => 'warning',
            self::MISMATCH => 'danger',
            default => 'secondary',
        };
    }
}

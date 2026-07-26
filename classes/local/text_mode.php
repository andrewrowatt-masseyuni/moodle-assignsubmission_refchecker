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
 * Where an assignment's reference list comes from.
 *
 * Reading references out of a PDF or Word file is the least reliable part of the pipeline: the text
 * has to be extracted, and then the bibliography has to be located by heading match. Asking the
 * student to paste their list in as plain text removes both steps, at the cost of asking them to do
 * something extra.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_mode {
    /** @var string No text box. References are read out of the submitted file. */
    public const NONE = 'no';

    /** @var string A text box is offered. If it is left empty, the submitted file is read instead. */
    public const OPTIONAL = 'optional';

    /** @var string A text box is offered and must be filled in. The submitted file is never read. */
    public const REQUIRED = 'required';

    /**
     * Clamp an arbitrary stored value to a mode we know about.
     *
     * @param string $mode
     * @return string
     */
    public static function sanitise(string $mode): string {
        return in_array($mode, [self::OPTIONAL, self::REQUIRED], true) ? $mode : self::NONE;
    }

    /**
     * Whether this mode puts a reference list box on the submission form.
     *
     * This is also what decides whether the plugin accepts a submission of its own, so it governs
     * whether File submissions is needed at all.
     *
     * @param string $mode
     * @return bool
     */
    public static function shows_field(string $mode): bool {
        return self::sanitise($mode) !== self::NONE;
    }

    /**
     * Whether the student must fill the box in before the form will save.
     *
     * @param string $mode
     * @return bool
     */
    public static function is_required(string $mode): bool {
        return self::sanitise($mode) === self::REQUIRED;
    }

    /**
     * Options for the settings select menus.
     *
     * @return array<string, string>
     */
    public static function menu(): array {
        return [
            self::NONE => get_string('requiretext_no', 'assignsubmission_refchecker'),
            self::OPTIONAL => get_string('requiretext_optional', 'assignsubmission_refchecker'),
            self::REQUIRED => get_string('requiretext_required', 'assignsubmission_refchecker'),
        ];
    }
}

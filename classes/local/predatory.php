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
 * Flags journals and publishers that appear on a list of possibly predatory outlets.
 *
 * The bundled list comes from the References-Validation project
 * (https://github.com/zabbonat/References-Validation), MIT licensed, copyright Diletta Abbonato.
 *
 * Be realistic about what this achieves: the list holds around twenty of the best known names,
 * so it will fire very rarely and its silence means nothing at all. It is worth keeping because a
 * hit is genuinely worth showing, but it must never be presented as a clean bill of health, which
 * is why the report wording says "appears on a list" rather than making a judgement.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class predatory {
    /** @var string[]|null Normalised names, loaded once per request. */
    protected static ?array $names = null;

    /**
     * Whether a journal or publisher name matches the list.
     *
     * Substring matching, so "OMICS Publishing Group Ltd" matches "OMICS Publishing Group".
     *
     * @param string|null $name
     * @return bool
     */
    public static function is_predatory(?string $name): bool {
        $name = trim((string) $name);
        if ($name === '') {
            return false;
        }

        $needle = matcher::normalize($name);
        if ($needle === '') {
            return false;
        }

        foreach (self::names() as $candidate) {
            if ($candidate !== '' && str_contains($needle, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The normalised publisher and journal names.
     *
     * @return string[]
     */
    protected static function names(): array {
        if (self::$names !== null) {
            return self::$names;
        }

        self::$names = [];

        $path = __DIR__ . '/../../data/predatorylist.json';
        if (!is_readable($path)) {
            return self::$names;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return self::$names;
        }

        foreach (['publishers', 'journals'] as $group) {
            foreach ((array) ($data[$group] ?? []) as $name) {
                $normalised = matcher::normalize((string) $name);
                if ($normalised !== '') {
                    self::$names[] = $normalised;
                }
            }
        }

        return self::$names;
    }

    /**
     * Discard the loaded list. Used by tests.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$names = null;
    }
}

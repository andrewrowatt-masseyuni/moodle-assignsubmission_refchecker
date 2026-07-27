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

/**
 * PSR-4 autoloader for the bundled elephant-php library.
 *
 * The library ships as a Composer package, but Moodle has no Composer autoloader available to
 * plugins and its own class loader only resolves Moodle's frozen namespace conventions. This is
 * the same approach local/faultreporting takes for its bundled libraries.
 *
 * Nothing here checks the PHP version. The vendored sources are patched back to PHP 8.1 (see
 * PATCHES.md), but the hazard that shaped this is worth keeping in mind: upstream's `readonly
 * class` is a parse error before 8.2, and a parse error in an autoloaded file is fatal rather than
 * catchable. \assignsubmission_refchecker\local\docx_converter::is_available() is the guard, and it
 * must be consulted before this file is required — not wrapped around it.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

spl_autoload_register(function (string $classname): void {
    $prefix = 'EndlessCreativity\\ElephantPhp\\';

    if (strpos($classname, $prefix) !== 0) {
        return;
    }

    $relative = substr($classname, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    // A missing file means another autoloader should get its turn, not a fatal error.
    if (is_readable($path)) {
        require_once($path);
    }
});

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

use stored_file;

/**
 * Reads a .docx without leaving PHP, using the bundled elephant-php library.
 *
 * The alternative, Moodle's document converter, needs a LibreOffice the site may not run and
 * cannot be relied on for the format students submit most often after PDF. This route has no
 * subprocess and no external service, so it either works everywhere the plugin is installed or
 * nowhere, which is a far easier thing for an administrator to reason about.
 *
 * Raw text is asked for rather than HTML or Markdown deliberately: the library emits one paragraph
 * per block separated by a blank line, which is exactly the shape reference_parser wants. Markdown
 * would prefix the reference-list heading with "#" and stop find_section() recognising it.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docx_converter {
    /**
     * The oldest PHP the bundled library runs on.
     *
     * Upstream declares "php": "^8.2", needing it for `readonly class` alone, so the vendored copy
     * is patched back to 8.1 — see thirdparty/elephant-php/PATCHES.md. That matches Moodle 4.5's
     * own floor, which is the point: the built-in converter is available on every server the
     * plugin can be installed on rather than only the newest ones.
     *
     * Checking it at all is belt and braces, but cheap, and the *ordering* it enforces is not
     * optional: an unpatched `readonly class` is a parse error, which is fatal and uncatchable, so
     * this must be consulted before the autoloader is required rather than wrapped in a try/catch
     * after the fact. Keep that shape if the library is ever re-vendored.
     *
     * @var int
     */
    public const MIN_PHP_VERSION = 80100;

    /**
     * Whether this server can run the bundled library at all.
     *
     * Separate from the site's preference on purpose: an administrator who has turned the setting
     * on deserves to be told that their server cannot oblige rather than left wondering why
     * nothing changed, and the status check reports the two conditions differently.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return PHP_VERSION_ID >= self::MIN_PHP_VERSION
            && class_exists('ZipArchive')
            && is_readable(self::autoloader_path());
    }

    /**
     * Whether this server can run the library and the site has asked us to.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return self::is_available()
            && (bool) get_config('assignsubmission_refchecker', 'usebuiltinconverter');
    }

    /**
     * Extract the text of a .docx.
     *
     * Never throws. A file this cannot read must fall through to the site's document converter,
     * which may well manage it, rather than failing the submission outright.
     *
     * @param stored_file $file
     * @return string|null Null when the library could not be used or could not read the file.
     */
    public static function extract(stored_file $file): ?string {
        if (!self::is_available()) {
            return null;
        }

        require_once(self::autoloader_path());

        try {
            // The library reads from a path rather than a stream, so the file has to be spilled to
            // disk first. make_request_directory() is cleaned up at the end of the request.
            $path = make_request_directory() . '/input.docx';
            $file->copy_content_to($path);

            $result = (new \EndlessCreativity\ElephantPhp\Converter())->extractRawText($path);
        } catch (\Throwable $e) {
            debugging(
                'assignsubmission_refchecker: built-in docx converter could not read '
                    . $file->get_filename() . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER,
            );
            return null;
        }

        $text = (string) $result->value;

        // An empty result is a miss, not an empty document: a .docx whose text lives somewhere the
        // library does not read is better handed to LibreOffice than reported as having no text.
        return trim($text) === '' ? null : $text;
    }

    /**
     * Path to the bundled library's autoloader.
     *
     * @return string
     */
    protected static function autoloader_path(): string {
        global $CFG;

        return $CFG->dirroot . '/mod/assign/submission/refchecker/thirdparty/elephant-php/autoload.php';
    }
}

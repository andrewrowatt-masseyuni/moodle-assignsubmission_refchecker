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

use core_files\conversion;
use core_files\converter;
use core_text;
use stored_file;

/**
 * Turns a submitted file into plain text.
 *
 * Three routes, chosen by file type:
 *
 * - PDF goes through pdftotext, because it is a genuine text extractor. LibreOffice imports PDF
 *   through Draw and reconstructs the page as positioned text boxes, which mangles the line order
 *   of a reference list, so it is deliberately not used here.
 * - DOCX goes through the bundled converter first, which needs nothing installed, and falls back
 *   to Moodle's document converter when that is turned off or cannot read the file.
 * - DOC, OpenDocument and RTF go through Moodle's document converter, which reuses infrastructure
 *   the site already runs and covers more formats than any library we could bundle.
 *
 * Every route but the bundled one is optional. A site with none of them still checks plain text
 * submissions.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extractor {
    /**
     * Below this many characters, a file almost certainly had no text layer to read.
     *
     * A scanned essay yields a handful of stray characters rather than nothing at all, so an
     * emptiness check alone would report it as an unremarkable empty result.
     *
     * @var int
     */
    public const MIN_USEFUL_CHARS = 100;

    /** @var string Extraction produced usable text. */
    public const RESULT_OK = 'ok';

    /** @var string The file type is not one we read. */
    public const RESULT_UNSUPPORTED = 'unsupported';

    /** @var string Extraction ran but produced almost nothing, most likely a scanned image. */
    public const RESULT_NOTEXT = 'noextractabletext';

    /** @var string Extraction could not be attempted or failed outright. */
    public const RESULT_FAILED = 'extractfailed';

    /**
     * Extract the text of a submitted file.
     *
     * Never throws: one unreadable file among several should not abandon the whole submission.
     *
     * @param stored_file $file
     * @return array{result: string, text: string, chars: int}
     */
    public static function extract(stored_file $file): array {
        $extension = core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));

        if (!in_array($extension, self::supported_types(), true)) {
            return self::result(self::RESULT_UNSUPPORTED, '');
        }

        try {
            $text = match ($extension) {
                'pdf' => self::extract_pdf($file),
                'docx' => self::extract_docx($file),
                'txt' => $file->get_content(),
                default => self::extract_via_converter($file),
            };
        } catch (\Throwable $e) {
            debugging(
                'assignsubmission_refchecker could not extract ' . $file->get_filename() . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER,
            );
            return self::result(self::RESULT_FAILED, '');
        }

        if ($text === null) {
            return self::result(self::RESULT_FAILED, '');
        }

        $text = self::normalise_text($text);
        if (core_text::strlen($text) < self::MIN_USEFUL_CHARS) {
            return self::result(self::RESULT_NOTEXT, $text);
        }

        return self::result(self::RESULT_OK, $text);
    }

    /**
     * Whether this file is one we would even attempt, and small enough to be worth attempting.
     *
     * @param stored_file $file
     * @return bool
     */
    public static function is_candidate(stored_file $file): bool {
        $extension = core_text::strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if (!in_array($extension, self::supported_types(), true)) {
            return false;
        }

        $maxbytes = (int) get_config('assignsubmission_refchecker', 'maxfilesizemb') * 1024 * 1024;

        return $maxbytes <= 0 || $file->get_filesize() <= $maxbytes;
    }

    /**
     * The file extensions the site is configured to read.
     *
     * @return string[]
     */
    public static function supported_types(): array {
        $configured = (string) get_config('assignsubmission_refchecker', 'supportedtypes');
        if (trim($configured) === '') {
            return ['pdf', 'docx', 'odt', 'rtf', 'txt'];
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    /**
     * Extract text from a PDF with pdftotext.
     *
     * The -layout flag matters more than it looks: it preserves the hanging indent structure of a
     * reference list, which is exactly what the unnumbered splitter keys off. Without it, every
     * reference runs together into one paragraph.
     *
     * @param stored_file $file
     * @return string|null Null when extraction was not possible.
     */
    protected static function extract_pdf(stored_file $file): ?string {
        $binary = (string) get_config('assignsubmission_refchecker', 'pathtopdftotext');
        if ($binary === '' || !is_executable($binary)) {
            return null;
        }

        $sourcedir = make_request_directory();
        $source = $sourcedir . '/' . 'input.pdf';
        $file->copy_content_to($source);
        $target = $sourcedir . '/output.txt';

        $command = escapeshellarg($binary)
            . ' -layout -enc UTF-8 -q '
            . escapeshellarg($source) . ' ' . escapeshellarg($target);

        $commandoutput = [];
        $status = 0;
        exec($command . ' 2>&1', $commandoutput, $status);

        // Damaged files make pdftotext report a non-zero status while it still writes usable
        // output, so trust the output file rather than the exit code alone. The status is worth
        // capturing for the case where nothing was written at all.
        if (!is_readable($target)) {
            debugging(
                sprintf(
                    'assignsubmission_refchecker: pdftotext exited %d for %s: %s',
                    $status,
                    $file->get_filename(),
                    implode(' ', $commandoutput),
                ),
                DEBUG_DEVELOPER,
            );
            return null;
        }

        return (string) file_get_contents($target);
    }

    /**
     * Extract text from a .docx, preferring the bundled converter.
     *
     * The two routes are complementary rather than redundant: the bundled one needs nothing
     * installed but reads only OOXML, while LibreOffice reads almost anything but may not be
     * there. Trying both in this order means a site gets docx support from whichever it has.
     *
     * @param stored_file $file
     * @return string|null Null when neither route could handle it.
     */
    protected static function extract_docx(stored_file $file): ?string {
        if (docx_converter::is_enabled()) {
            $text = docx_converter::extract($file);
            if ($text !== null) {
                return $text;
            }
        }

        return self::extract_via_converter($file);
    }

    /**
     * Extract text from a word processing file using Moodle's document converter.
     *
     * @param stored_file $file
     * @return string|null Null when no converter could handle it.
     */
    protected static function extract_via_converter(stored_file $file): ?string {
        if (!get_config('assignsubmission_refchecker', 'uselibreoffice')) {
            return null;
        }

        $converter = new converter();
        if (!$converter->can_convert_storedfile_to($file, 'txt')) {
            return null;
        }

        $conversion = $converter->start_conversion($file, 'txt');

        // The LibreOffice converter in this tree completes inline, but the interface allows an
        // asynchronous one. Treat anything short of a finished conversion as a miss rather than
        // silently returning empty text.
        if ((int) $conversion->get('status') !== conversion::STATUS_COMPLETE) {
            return null;
        }

        $result = $conversion->get_destfile();

        return $result ? $result->get_content() : null;
    }

    /**
     * Normalise line endings and whitespace without destroying the line structure.
     *
     * The reference splitter depends on line breaks, so this deliberately does not collapse them.
     *
     * @param string $text
     * @return string
     */
    protected static function normalise_text(string $text): string {
        // Extraction output is not guaranteed to be valid UTF-8; a bad byte would break every
        // subsequent preg_* call with the /u flag.
        $text = core_text::convert($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Non-breaking spaces and soft hyphens are common in extracted text and break matching.
        $text = str_replace(["\xc2\xa0", "\xc2\xad"], [' ', ''], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n/u', "\n", $text);

        return trim($text);
    }

    /**
     * Shape an extraction result.
     *
     * @param string $result One of the RESULT_* constants.
     * @param string $text
     * @return array{result: string, text: string, chars: int}
     */
    protected static function result(string $result, string $text): array {
        return ['result' => $result, 'text' => $text, 'chars' => core_text::strlen($text)];
    }
}

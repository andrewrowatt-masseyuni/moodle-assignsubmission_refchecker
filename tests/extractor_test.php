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

namespace assignsubmission_refchecker;

use assignsubmission_refchecker\local\docx_converter;
use assignsubmission_refchecker\local\extractor;
use assignsubmission_refchecker\local\reference_parser;
use stored_file;

/**
 * Tests for text extraction, and for the built-in .docx converter in particular.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\docx_converter
 * @covers     \assignsubmission_refchecker\local\extractor
 */
final class extractor_test extends \advanced_testcase {
    /**
     * Skip rather than fail where the bundled library cannot run.
     *
     * Since the library is patched down to PHP 8.1 this should never actually skip on a server
     * Moodle 4.5 itself supports, but the plugin's contract is that .docx falls back to the site
     * converter rather than failing, and the tests should hold to the same contract.
     */
    protected function require_builtin_converter(): void {
        if (!docx_converter::is_available()) {
            $this->markTestSkipped(
                'The built-in docx converter needs PHP ' . docx_converter::MIN_PHP_VERSION
                    . ' or later and ext-zip; this server has PHP ' . PHP_VERSION . '.',
            );
        }
    }

    /**
     * The vendored library still carries the PHP 8.1 patch.
     *
     * Upstream needs PHP 8.2 for `readonly class` alone, so the vendored copy is rewritten to 8.1
     * longhand by thirdparty/elephant-php/apply-php81-patch.php. Re-vendoring a new release without
     * re-running that script would otherwise go unnoticed until a parse error took out a live 8.1
     * site — and a parse error in an autoloaded file cannot be caught and recovered from, so
     * nothing at runtime would soften it. Failing here instead is the whole point.
     */
    public function test_vendored_library_carries_the_php81_patch(): void {
        $src = __DIR__ . '/../thirdparty/elephant-php/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        $unpatched = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (strpos(file_get_contents($file->getPathname()), 'readonly class') !== false) {
                $unpatched[] = $file->getFilename();
            }
        }

        $this->assertSame(
            [],
            $unpatched,
            'Vendored elephant-php files still use PHP 8.2 readonly classes. Run '
                . 'thirdparty/elephant-php/apply-php81-patch.php; see that directory\'s PATCHES.md.',
        );
    }

    /**
     * Store a file against a throwaway context so extraction has a stored_file to read.
     *
     * @param string $filename
     * @param string $content
     * @return stored_file
     */
    protected function make_file(string $filename, string $content): stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * The fixture: a small Word document with body text, a References heading and four references.
     *
     * @return stored_file
     */
    protected function make_fixture_file(): stored_file {
        return $this->make_file('essay.docx', file_get_contents(__DIR__ . '/fixtures/references.docx'));
    }

    /**
     * The built-in converter reads a .docx without any site converter configured.
     */
    public function test_builtin_converter_reads_a_docx(): void {
        $this->resetAfterTest();
        $this->require_builtin_converter();

        $text = docx_converter::extract($this->make_fixture_file());

        $this->assertNotNull($text);
        $this->assertStringContainsString('Enhancing teaching through constructive alignment', $text);
    }

    /**
     * A file that is not a .docx at all is a miss, not an exception.
     *
     * The caller falls back to the site's document converter on null, so throwing here would
     * abandon a file LibreOffice might well have managed.
     */
    public function test_builtin_converter_returns_null_for_a_corrupt_file(): void {
        $this->resetAfterTest();
        $this->require_builtin_converter();

        $file = $this->make_file('broken.docx', 'This is not a zip archive, let alone OOXML.');

        $this->assertNull(docx_converter::extract($file));
        $this->assertDebuggingCalled();
    }

    /**
     * A valid .docx with no text in it is a miss too, and a quiet one.
     *
     * The library returns an empty string rather than failing here, which is not the same as
     * having read the document successfully: a .docx whose text lives somewhere the library does
     * not read is better handed to LibreOffice than reported as an empty submission. Nothing has
     * gone wrong, so unlike the corrupt case this must not log.
     */
    public function test_builtin_converter_returns_null_for_a_document_with_no_text(): void {
        $this->resetAfterTest();
        $this->require_builtin_converter();

        $path = make_request_directory() . '/empty.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',
        );
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document '
                . 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body><w:sectPr/></w:body></w:document>',
        );
        $zip->close();

        $this->assertNull(docx_converter::extract($this->make_file('empty.docx', file_get_contents($path))));
    }

    /**
     * The setting gates the converter without disturbing whether it could run.
     */
    public function test_setting_gates_the_builtin_converter(): void {
        $this->resetAfterTest();
        $this->require_builtin_converter();

        set_config('usebuiltinconverter', 0, 'assignsubmission_refchecker');
        $this->assertFalse(docx_converter::is_enabled());
        $this->assertTrue(docx_converter::is_available());

        set_config('usebuiltinconverter', 1, 'assignsubmission_refchecker');
        $this->assertTrue(docx_converter::is_enabled());
    }

    /**
     * extract() routes .docx through the built-in converter and reports success.
     */
    public function test_extract_reads_a_docx_with_no_site_converter(): void {
        $this->resetAfterTest();
        $this->require_builtin_converter();

        set_config('usebuiltinconverter', 1, 'assignsubmission_refchecker');
        // Prove the built-in route stands alone: the site converter is explicitly off.
        set_config('uselibreoffice', 0, 'assignsubmission_refchecker');

        $result = extractor::extract($this->make_fixture_file());

        $this->assertSame(extractor::RESULT_OK, $result['result']);
        $this->assertGreaterThan(extractor::MIN_USEFUL_CHARS, $result['chars']);
    }

    /**
     * The parser finds the reference list in the extracted text.
     *
     * This is the assertion that matters. The library separates paragraphs with a blank line and
     * the parser depends on exactly that: find_section() needs the heading alone on its own line,
     * and the unnumbered splitter starts a new reference at a blank line followed by a capital.
     * Anything that flattened those breaks — a change to normalise_text(), or switching the
     * library to Markdown output — would still extract plenty of characters and silently find
     * nothing, so counting references is the only check that would notice.
     */
    public function test_extracted_docx_text_parses_into_references(): void {
        $this->resetAfterTest();
        $this->require_builtin_converter();

        set_config('usebuiltinconverter', 1, 'assignsubmission_refchecker');
        set_config('uselibreoffice', 0, 'assignsubmission_refchecker');

        $result = extractor::extract($this->make_fixture_file());
        $section = reference_parser::find_section($result['text']);

        $this->assertTrue($section['found']);
        $this->assertSame('References', $section['heading']);

        $references = reference_parser::split(reference_parser::clean($section['text']));

        $this->assertCount(4, $references);
        $this->assertStringStartsWith('Biggs, J. (1996).', $references[0]);
        $this->assertStringStartsWith('Sadler, D. R. (1989).', $references[3]);
    }

    /**
     * A file type the site does not scan is reported as unsupported before anything is read.
     */
    public function test_unsupported_type_is_reported(): void {
        $this->resetAfterTest();

        set_config('supportedtypes', 'pdf,txt', 'assignsubmission_refchecker');
        $result = extractor::extract($this->make_file('essay.docx', 'irrelevant'));

        $this->assertSame(extractor::RESULT_UNSUPPORTED, $result['result']);
        $this->assertSame('', $result['text']);
    }

    /**
     * Too little text is reported as such rather than as an ordinary empty result.
     */
    public function test_short_text_is_reported_as_no_extractable_text(): void {
        $this->resetAfterTest();

        $result = extractor::extract($this->make_file('essay.txt', 'Three words only.'));

        $this->assertSame(extractor::RESULT_NOTEXT, $result['result']);
    }
}

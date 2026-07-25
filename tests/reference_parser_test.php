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

use assignsubmission_refchecker\local\reference_parser;

/**
 * Tests for finding and splitting a reference list.
 *
 * This is the most fragile part of the plugin, and the part most likely to under-report. The
 * fixtures here are deliberately shaped like real student work rather than tidy published PDFs.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\reference_parser
 */
final class reference_parser_test extends \advanced_testcase {
    /**
     * A plain "References" heading is found and its name reported back.
     */
    public function test_finds_a_reference_section(): void {
        $text = "Some essay text about things.\n\nReferences\n\nSmith, J. (2019). A paper. Journal.\n";

        $section = reference_parser::find_section($text);

        $this->assertTrue($section['found']);
        $this->assertSame('References', $section['heading']);
        $this->assertStringContainsString('Smith, J.', $section['text']);
    }

    /**
     * The last heading wins, because essays mention "references" in their body too.
     */
    public function test_uses_the_last_heading(): void {
        $text = "We discuss references in this section.\n\n"
            . "References\n"
            . "Early, A. (2001). Wrong list. Journal.\n\n"
            . "Bibliography\n"
            . "Correct, B. (2020). Right list. Journal.\n";

        $section = reference_parser::find_section($text);

        $this->assertSame('Bibliography', $section['heading']);
        $this->assertStringContainsString('Correct, B.', $section['text']);
        $this->assertStringNotContainsString('Early, A.', $section['text']);
    }

    /**
     * Numbered headings and trailing punctuation are tolerated.
     *
     * @dataProvider heading_variant_provider
     * @param string $heading The heading line as it appears in the document.
     */
    public function test_heading_variants(string $heading): void {
        $text = "Body text.\n\n{$heading}\n\nSmith, J. (2019). A paper. Journal of Things.\n";

        $this->assertTrue(reference_parser::find_section($text)['found'], "failed on: {$heading}");
    }

    /**
     * Data provider of heading spellings that must be recognised.
     *
     * @return array[]
     */
    public static function heading_variant_provider(): array {
        return [
            ['References'],
            ['REFERENCES'],
            ['references'],
            ['8. References'],
            ['VIII. References'],
            ['References:'],
            ['Reference List'],
            ['Works Cited'],
            ['Bibliography'],
            ['Sources'],
        ];
    }

    /**
     * A document with no reference list reports that, rather than guessing.
     *
     * Treating the whole document as a reference list produces confident nonsense, which is worse
     * than admitting nothing was found.
     */
    public function test_missing_section_is_reported_not_guessed(): void {
        $text = "An essay with no reference list at all.\nJust prose, for several lines.\n";

        $section = reference_parser::find_section($text);

        $this->assertFalse($section['found']);
        $this->assertSame('', $section['text']);
        $this->assertSame([], reference_parser::parse($text)['references']);
    }

    /**
     * The list stops at the next major heading.
     */
    public function test_section_stops_at_the_next_heading(): void {
        $text = "References\n\n"
            . "Smith, J. (2019). A paper. Journal of Things.\n\n"
            . "Appendix\n\n"
            . "Table 1 shows the raw data collected during the study period.\n";

        $section = reference_parser::find_section($text);

        $this->assertStringContainsString('Smith, J.', $section['text']);
        $this->assertStringNotContainsString('Table 1', $section['text']);
    }

    /**
     * An APA list with hanging indents wrapping over lines splits on the citations, not the lines.
     */
    public function test_splits_wrapped_apa_references(): void {
        $section = "Braun, V., & Clarke, V. (2006). Using thematic analysis in psychology.\n"
            . "    Qualitative Research in Psychology, 3(2), 77-101.\n"
            . "\n"
            . "Creswell, J. W. (2014). Research design: Qualitative, quantitative, and mixed\n"
            . "    methods approaches. SAGE Publications.\n";

        $references = reference_parser::split($section);

        $this->assertCount(2, $references);
        $this->assertStringContainsString('77-101', $references[0]);
        $this->assertStringContainsString('SAGE Publications', $references[1]);
    }

    /**
     * A numbered list splits on its markers even without blank lines between entries.
     */
    public function test_splits_numbered_references(): void {
        $section = "[1] Vaswani A, Shazeer N. Attention is all you need. NeurIPS. 2017.\n"
            . "[2] Devlin J, Chang M. BERT: pre-training of deep bidirectional transformers.\n"
            . "NAACL. 2019.\n"
            . "[3] Brown T, Mann B. Language models are few-shot learners. NeurIPS. 2020.\n";

        $references = reference_parser::split($section);

        $this->assertCount(3, $references);
        // The wrapped second entry must be rejoined, not left as a fragment.
        $this->assertStringContainsString('NAACL', $references[1]);
    }

    /**
     * Vancouver numbering with trailing dots also splits correctly.
     */
    public function test_splits_vancouver_numbering(): void {
        $section = "1. Smith J, Jones A. A study of things. J Things. 2019;12(3):45-67.\n"
            . "2. Brown B. Another study of things. J Things. 2020;13(1):1-12.\n";

        $this->assertCount(2, reference_parser::split($section));
    }

    /**
     * Page numbers and footer boilerplate are stripped before splitting.
     */
    public function test_cleaning_removes_extraction_artefacts(): void {
        $section = "Smith, J. (2019). A paper about things. Journal of Things, 1(1), 1-10.\n"
            . "\n"
            . "42\n"
            . "\n"
            . "Downloaded from example.com on 1 January 2020\n"
            . "\n"
            . "Jones, A. (2020). Another paper about things. Journal of Things, 2(1), 1-10.\n";

        $references = reference_parser::split($section);

        $this->assertCount(2, $references);
        foreach ($references as $reference) {
            $this->assertStringNotContainsString('Downloaded from', $reference);
        }
    }

    /**
     * Fragments and runaway paragraphs are discarded.
     */
    public function test_discards_noise(): void {
        $section = "ibid.\n\n" . str_repeat('This is running prose that is not a citation. ', 60);

        $this->assertSame([], reference_parser::split($section));
    }

    /**
     * A bracketed year is preferred over any other four digit number in the reference.
     */
    public function test_extracts_the_citation_year(): void {
        $this->assertSame('2019', reference_parser::extract_year(
            'Smith, J. (2019). A paper. Journal of Things, 2020, 1-10.',
        ));
        $this->assertSame('1998', reference_parser::extract_year('Smith 1998 A paper'));
        $this->assertNull(reference_parser::extract_year('Smith, J. A paper with no year.'));
    }

    /**
     * DOIs are recognised in each of the forms references use.
     *
     * @dataProvider doi_provider
     * @param string $reference The reference text.
     * @param string $expected The DOI that must be extracted.
     */
    public function test_extracts_doi(string $reference, string $expected): void {
        $this->assertSame($expected, reference_parser::extract_doi($reference));
    }

    /**
     * Data provider of DOI spellings.
     *
     * @return array[]
     */
    public static function doi_provider(): array {
        return [
            'bare' => ['A paper. 10.1234/abc.def', '10.1234/abc.def'],
            'prefixed' => ['A paper. DOI: 10.1234/abc', '10.1234/abc'],
            'resolver' => ['A paper. https://doi.org/10.1234/abc', '10.1234/abc'],
            'trailing stop' => ['A paper. https://doi.org/10.1234/abc.', '10.1234/abc'],
        ];
    }

    /**
     * An APA reference yields its author, year, title and journal.
     */
    public function test_parses_apa_metadata(): void {
        $metadata = reference_parser::parse_metadata(
            'Braun, V., & Clarke, V. (2006). Using thematic analysis in psychology. '
            . 'Qualitative Research in Psychology, 3(2), 77-101.',
        );

        $this->assertSame('2006', $metadata['year']);
        $this->assertSame('Using thematic analysis in psychology', $metadata['title']);
        $this->assertStringContainsString('Braun', $metadata['authors']);
        $this->assertStringContainsString('Qualitative Research', $metadata['journal']);
    }

    /**
     * Parsing a whole document end to end reports the heading alongside the references.
     */
    public function test_parse_end_to_end(): void {
        $text = "An essay.\n\nReferences\n\n"
            . "Braun, V., & Clarke, V. (2006). Using thematic analysis in psychology. "
            . "Qualitative Research in Psychology, 3(2), 77-101.\n\n"
            . "Creswell, J. W. (2014). Research design: Qualitative and quantitative approaches. "
            . "SAGE Publications.\n";

        $parsed = reference_parser::parse($text);

        $this->assertTrue($parsed['found']);
        $this->assertSame('References', $parsed['heading']);
        $this->assertCount(2, $parsed['references']);
        $this->assertArrayHasKey('raw', $parsed['references'][0]);
        $this->assertArrayHasKey('title', $parsed['references'][0]);
    }
}

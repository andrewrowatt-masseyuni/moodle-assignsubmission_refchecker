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

use core_text;

/**
 * Finds the reference list in a document's text and splits it into individual references.
 *
 * Ported from the References-Validation project
 * (https://github.com/zabbonat/References-Validation), MIT licensed, copyright Diletta Abbonato.
 *
 * This is the least reliable part of the whole pipeline and the one most worth testing against
 * real submissions: it was originally tuned on published academic PDFs, and student work is far
 * more varied. Under-counting is the dangerous failure mode, which is why the matched heading is
 * reported back to the reader rather than silently assumed correct.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reference_parser {
    /** @var int References shorter than this are noise rather than citations. */
    private const MIN_REFERENCE_LENGTH = 16;

    /** @var int References longer than this are running prose that was mistaken for a citation. */
    private const MAX_REFERENCE_LENGTH = 2000;

    /** @var string Marks a segment as reading like a list of names rather than a title. */
    private const AUTHOR_PATTERN = '/\b(and)\b.*(?:,|\band\b)/i';

    /** @var string Marks a segment as reading like a journal or publisher rather than a title. */
    private const VENUE_PATTERN = '/\b(journal|proceedings|conference|transactions|advances|'
        . 'letters|review|annals|bulletin|workshop|symposium|arxiv|ieee|acm|springer|nature|'
        . 'science|press)\b/i';

    /**
     * Headings that introduce a reference list.
     *
     * The English set is extended slightly beyond upstream with wordings students actually use.
     * Over-matching is cheap because only the last occurrence in the document is used.
     *
     * @return string[]
     */
    protected static function reference_headings(): array {
        return [
            // English.
            'references', 'reference list', 'list of references', 'bibliography', 'literature',
            'works cited', 'cited literature', 'literature cited', 'citations',
            'works referenced', 'works consulted', 'sources', 'source list',
            // Italian.
            'riferimenti', 'riferimenti bibliografici', 'bibliografia',
            // Spanish.
            'bibliografía', 'referencias', 'referencias bibliográficas',
            // Portuguese.
            'referências', 'referências bibliográficas',
            // French.
            'bibliographie', 'références', 'références bibliographiques',
            // German.
            'literaturverzeichnis', 'quellenverzeichnis', 'quellen',
        ];
    }

    /**
     * Headings that mean the reference list has ended.
     *
     * @return string[]
     */
    protected static function end_headings(): array {
        return [
            'appendix', 'appendices', 'supplementary', 'supplementary material',
            'supplementary materials', 'supporting information',
            'acknowledgment', 'acknowledgments', 'acknowledgement', 'acknowledgements',
            'about the author', 'about the authors', 'author contributions',
            'author biography', 'biographies', 'vita', 'curriculum vitae',
            'conflict of interest', 'conflicts of interest', 'declaration',
            'funding', 'data availability', 'ethics statement', 'glossary',
            'annexe', 'annexes', 'anhang', 'ringraziamenti', 'agradecimientos',
        ];
    }

    /**
     * A regex matching any of the given headings on a line of its own.
     *
     * Tolerates numbering ("8. References", "VIII. References"), trailing colons, and the page
     * numbers that a table of contents or a running footer leaves behind.
     *
     * @param string[] $headings
     * @return string
     */
    protected static function heading_regex(array $headings): string {
        $alternatives = implode('|', array_map(static fn($h) => preg_quote($h, '/'), $headings));

        return '/^\s*(?:[0-9IVXLC]+[.\s)]+)?\s*(' . $alternatives . ')\s*[:\s\d.\-]*$/iu';
    }

    /**
     * Locate the reference list within a document's full text.
     *
     * Takes the last matching heading, because papers and essays routinely mention "references"
     * in their body text before reaching the actual list.
     *
     * @param string $text
     * @return array{found: bool, heading: string, text: string}
     */
    public static function find_section(string $text): array {
        $lines = preg_split('/\R/u', $text) ?: [];
        $headingregex = self::heading_regex(self::reference_headings());

        $headingindex = -1;
        $heading = '';
        foreach ($lines as $index => $line) {
            if (preg_match($headingregex, trim($line))) {
                $headingindex = $index;
                $heading = trim($line);
            }
        }

        if ($headingindex === -1) {
            // No heading at all. Report it rather than guessing: treating the whole document as a
            // reference list produces convincing nonsense.
            return ['found' => false, 'heading' => '', 'text' => ''];
        }

        $after = array_slice($lines, $headingindex + 1);
        $endregex = self::heading_regex(self::end_headings());

        $end = count($after);
        foreach ($after as $index => $line) {
            if (preg_match($endregex, trim($line))) {
                $end = $index;
                break;
            }
        }

        return [
            'found' => true,
            'heading' => $heading,
            'text' => implode("\n", array_slice($after, 0, $end)),
        ];
    }

    /**
     * Strip the artefacts that text extraction leaves behind.
     *
     * @param string $text
     * @return string
     */
    public static function clean(string $text): string {
        // Standalone page numbers.
        $text = preg_replace('/^\s*\d{1,4}\s*$/mu', '', $text);
        // Running headers and footers.
        $text = preg_replace(
            '/^\s*(Downloaded from|Copyright ©|All rights reserved|Published by)\b.*$/imu',
            '',
            $text,
        );
        // URLs on a line of their own, which are usually footer boilerplate.
        $text = preg_replace('/^\s*https?:\/\/\S+\s*$/mu', '', $text);
        // Collapse the resulting runs of blank lines.
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /**
     * Split a reference list into individual references.
     *
     * Numbered lists are unambiguous. Unnumbered ones are not, so two heuristics are used: a new
     * reference after a blank line, and an author-then-year opening after a line that ended like
     * a complete citation.
     *
     * @param string $sectiontext
     * @return string[]
     */
    public static function split(string $sectiontext): array {
        $cleaned = self::clean($sectiontext);
        if ($cleaned === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $cleaned) ?: [];
        $numbered = '/^\s*[\[(]?\d{1,4}[\].)]\s*/u';

        $numberedlines = 0;
        foreach ($lines as $line) {
            if (preg_match($numbered, trim($line))) {
                $numberedlines++;
            }
        }

        $references = $numberedlines >= 2
            ? self::split_numbered($lines, $numbered)
            : self::split_unnumbered($lines);

        return array_values(array_filter($references, static function ($reference) {
            $length = core_text::strlen($reference);
            return $length > self::MIN_REFERENCE_LENGTH && $length < self::MAX_REFERENCE_LENGTH;
        }));
    }

    /**
     * Split a numbered reference list on its number markers.
     *
     * @param string[] $lines
     * @param string $numbered The regex identifying a number marker.
     * @return string[]
     */
    protected static function split_numbered(array $lines, string $numbered): array {
        $references = [];
        $current = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match($numbered, $trimmed)) {
                if (trim($current) !== '') {
                    $references[] = trim($current);
                }
                $current = $trimmed;
            } else {
                $current .= ' ' . $trimmed;
            }
        }

        if (trim($current) !== '') {
            $references[] = trim($current);
        }

        return $references;
    }

    /**
     * Split an unnumbered (typically APA) reference list.
     *
     * @param string[] $lines
     * @return string[]
     */
    protected static function split_unnumbered(array $lines): array {
        $references = [];
        $current = '';
        $previousblank = true;

        // A capitalised opening followed, before long, by a bracketed year.
        $apastart = '/^[A-Z\x{00C0}-\x{024F}].{0,250}?\(\d{4}[a-z]?\)/u';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if (trim($current) !== '') {
                    $references[] = trim($current);
                    $current = '';
                }
                $previousblank = true;
                continue;
            }

            $afterblank = $previousblank && preg_match('/^[A-Z\x{00C0}-\x{024F}]/u', $trimmed);
            $completed = preg_match('/[.\d)\]>]$/u', trim($current));
            $newcitation = $completed && preg_match($apastart, $trimmed);

            if (($afterblank || $newcitation) && trim($current) !== '') {
                $references[] = trim($current);
                $current = $trimmed;
            } else {
                $current .= ($current === '' ? '' : ' ') . $trimmed;
            }

            $previousblank = false;
        }

        if (trim($current) !== '') {
            $references[] = trim($current);
        }

        return $references;
    }

    /**
     * Pull whatever metadata can be recognised out of a single reference.
     *
     * Everything here is best effort. A missing field is left null so the matcher can drop it
     * from the weighting rather than scoring it as a mismatch.
     *
     * @param string $reference
     * @return array{title: ?string, authors: ?string, journal: ?string, year: ?string, doi: ?string}
     */
    public static function parse_metadata(string $reference): array {
        $metadata = [
            'title' => null,
            'authors' => null,
            'journal' => null,
            'year' => self::extract_year($reference),
            'doi' => self::extract_doi($reference),
            'arxivid' => self::extract_arxiv_id($reference),
        ];

        $apa = self::parse_apa($reference);
        if ($apa !== null) {
            return array_merge($metadata, $apa);
        }

        $vancouver = self::parse_vancouver($reference);
        if ($vancouver !== null) {
            return array_merge($metadata, $vancouver);
        }

        return array_merge($metadata, self::parse_generic($reference));
    }

    /**
     * Extract a DOI.
     *
     * @param string $reference
     * @return string|null
     */
    public static function extract_doi(string $reference): ?string {
        $patterns = [
            '/(?:DOI\s*[：:]\s*)(10\.\d{4,9}\/[^\s,;]+)/i',
            '#(?:https?://(?:dx\.)?doi\.org/)(10\.\d{4,9}/[^\s,;]+)#i',
            '/\b(10\.\d{4,9}\/[^\s,;]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $reference, $matches)) {
                return rtrim($matches[1], '. ');
            }
        }

        return null;
    }

    /**
     * Extract an arXiv identifier.
     *
     * Covers the modern form (2301.12345, optionally versioned), the pre-2007 form
     * (cs.CL/0112017), and both spelled out in an arxiv.org URL. Worth doing: a reference that
     * names its preprint identifies the work outright, which turns a search into a lookup.
     *
     * @param string $reference
     * @return string|null
     */
    public static function extract_arxiv_id(string $reference): ?string {
        $patterns = [
            // The modern scheme: arXiv:2301.12345v2, or the same inside an arxiv.org URL.
            '/ar[Xx]iv[:\s\/]\s*(\d{4}\.\d{4,5}(?:v\d+)?)/i',
            '#arxiv\.org/(?:abs|pdf)/(\d{4}\.\d{4,5}(?:v\d+)?)#i',
            // The pre-2007 scheme, for example arXiv:cs.CL/0112017.
            '/ar[Xx]iv[:\s\/]\s*([a-z-]+(?:\.[A-Z]{2})?\/\d{7}(?:v\d+)?)/i',
            '#arxiv\.org/(?:abs|pdf)/([a-z-]+(?:\.[A-Z]{2})?/\d{7}(?:v\d+)?)#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $reference, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Extract the publication year.
     *
     * @param string $reference
     * @return string|null
     */
    public static function extract_year(string $reference): ?string {
        // A bracketed year is the citation year; a bare one could be a volume or a page range.
        if (preg_match('/\((19|20)(\d{2})[a-z]?\)/', $reference, $matches)) {
            return $matches[1] . $matches[2];
        }
        if (preg_match('/\b(19|20)\d{2}\b/', $reference, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Strip a leading reference number such as "[12]" or "3.".
     *
     * @param string $reference
     * @return string
     */
    protected static function strip_number(string $reference): string {
        return trim(preg_replace('/^\s*[\[(\s]*\d{1,4}[\])\s]*[.\s]?/u', '', $reference));
    }

    /**
     * Parse an APA style reference: Authors (Year). Title. Journal, ...
     *
     * @param string $reference
     * @return array|null Null when the shape does not match.
     */
    protected static function parse_apa(string $reference): ?array {
        $cleaned = self::strip_number($reference);

        $pattern = '/^(.+?)\s*\((\d{4})[a-z]?\)\.\s*(.+?)\.\s*(.+?)'
            . '(?:\.\s*(?:https?:\/\/|doi:).*)?$/isu';
        if (!preg_match($pattern, $cleaned, $matches)) {
            return null;
        }

        $journal = null;
        if (preg_match('/^([^,\d]+)/u', $matches[4], $journalmatch)) {
            $journal = trim(rtrim(trim($journalmatch[1]), '.'));
        }

        return [
            'authors' => trim(rtrim(trim($matches[1]), '.')),
            'year' => $matches[2],
            'title' => trim(rtrim(trim($matches[3]), '.')),
            'journal' => ($journal === '' ? null : $journal),
        ];
    }

    /**
     * Parse a Vancouver style reference: N. Authors. Title. Journal. Year;Vol(Issue):Pages.
     *
     * @param string $reference
     * @return array|null Null when the shape does not match.
     */
    protected static function parse_vancouver(string $reference): ?array {
        // Vancouver references are numbered. Without a number this would match almost anything.
        if (!preg_match('/^\s*[\[(\s]*\d{1,4}[\])\s]*[.\s]/u', $reference)) {
            return null;
        }

        $cleaned = self::strip_number($reference);
        $segments = array_values(array_filter(
            preg_split('/\.\s+/u', $cleaned) ?: [],
            static fn($segment) => core_text::strlen(trim($segment)) > 3,
        ));

        if (count($segments) < 3) {
            return null;
        }

        $journal = null;
        if (preg_match('/^([^;,\d]+)/u', $segments[2], $journalmatch)) {
            $journal = trim($journalmatch[1]);
        }

        return [
            'authors' => trim($segments[0]),
            'title' => trim($segments[1]),
            'journal' => ($journal === '' ? null : $journal),
        ];
    }

    /**
     * Last resort: guess which sentence-like segment is the title.
     *
     * Titles read like prose, so they carry a high proportion of lower case words. Author lists
     * and journal names do not, and are penalised heavily.
     *
     * @param string $reference
     * @return array
     */
    protected static function parse_generic(string $reference): array {
        $cleaned = self::strip_number($reference);
        $cleaned = preg_replace('/(?:DOI\s*[：:]\s*)10\.\d{4,9}\/[^\s,;]+/i', '', $cleaned);
        $cleaned = preg_replace('#https?://\S+#i', '', $cleaned);
        $cleaned = preg_replace('/\(?\b(19|20)\d{2}[a-z]?\b\)?/', '', $cleaned);

        $segments = array_values(array_filter(
            array_map(
                static fn($segment) => trim(rtrim(trim($segment), '.')),
                preg_split('/\.\s+/u', (string) $cleaned) ?: [],
            ),
            static fn($segment) => core_text::strlen($segment) > 5,
        ));

        if (!$segments) {
            return ['title' => null, 'authors' => null, 'journal' => null];
        }
        if (count($segments) === 1) {
            return ['title' => $segments[0], 'authors' => null, 'journal' => null];
        }

        $bestindex = self::most_title_like($segments);

        return array_merge(
            ['title' => $segments[$bestindex]],
            self::identify_supporting_segments($segments, $bestindex),
        );
    }

    /**
     * Which segment reads most like a title.
     *
     * Titles are prose, so they carry a high proportion of lower case words. Author lists and
     * journal names do not, and are penalised heavily.
     *
     * @param string[] $segments
     * @return int Index into $segments.
     */
    protected static function most_title_like(array $segments): int {
        $bestindex = 0;
        $bestscore = -1.0;

        foreach ($segments as $index => $segment) {
            $score = self::title_likeness($segment);
            if ($score > $bestscore) {
                $bestscore = $score;
                $bestindex = $index;
            }
        }

        return $bestindex;
    }

    /**
     * How much one segment reads like a title.
     *
     * @param string $segment
     * @return float
     */
    protected static function title_likeness(string $segment): float {
        $words = preg_split('/\s+/u', $segment) ?: [];

        $lowercase = 0;
        foreach ($words as $word) {
            if (core_text::strlen($word) > 2 && core_text::strtolower($word) === $word) {
                $lowercase++;
            }
        }

        $ratio = $words ? $lowercase / count($words) : 0;
        $score = core_text::strlen($segment) * (0.5 + $ratio);

        if (preg_match(self::AUTHOR_PATTERN, $segment) || preg_match(self::VENUE_PATTERN, $segment)) {
            $score *= 0.05;
        }

        return $score;
    }

    /**
     * Pick out the author list and the venue from the segments that are not the title.
     *
     * @param string[] $segments
     * @param int $bestindex The index already claimed by the title.
     * @return array{authors: ?string, journal: ?string}
     */
    protected static function identify_supporting_segments(array $segments, int $bestindex): array {
        $authors = null;
        $journal = null;

        foreach ($segments as $index => $segment) {
            if ($index === $bestindex) {
                continue;
            }
            // The opening segment is the author list in every style we handle.
            if ($authors === null && ($index === 0 || preg_match(self::AUTHOR_PATTERN, $segment))) {
                $authors = $segment;
            }
            if ($journal === null && preg_match(self::VENUE_PATTERN, $segment)) {
                $journal = $segment;
            }
        }

        return ['authors' => $authors, 'journal' => $journal];
    }

    /**
     * Find and parse every reference in a document's text.
     *
     * @param string $text The full extracted text of one submitted file.
     * @return array{found: bool, heading: string, references: array<int, array>}
     */
    public static function parse(string $text): array {
        $section = self::find_section($text);
        if (!$section['found']) {
            return ['found' => false, 'heading' => '', 'references' => []];
        }

        $references = [];
        foreach (self::split($section['text']) as $raw) {
            $references[] = array_merge(['raw' => $raw], self::parse_metadata($raw));
        }

        return [
            'found' => true,
            'heading' => $section['heading'],
            'references' => $references,
        ];
    }
}

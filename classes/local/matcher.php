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
 * Decides how well a record returned by a database matches the reference a student cited.
 *
 * The similarity measures and the 70% minimum title threshold are ported from the
 * References-Validation project (https://github.com/zabbonat/References-Validation), which is
 * MIT licensed and copyright Diletta Abbonato. Keeping the same measures means results stay
 * comparable with that tool.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class matcher {
    /**
     * Below this title similarity a candidate is rejected outright.
     *
     * Reporting "not found" is much less damaging than confidently showing a student a different
     * paper and telling them it is theirs.
     *
     * @var int
     */
    public const MIN_TITLE_SIMILARITY = 70;

    /** @var int PHP's levenshtein() refuses arguments longer than this. */
    private const LEVENSHTEIN_MAX = 255;

    /**
     * Reduce a string to a comparable form: unaccented, lower case, punctuation stripped.
     *
     * @param string $value
     * @return string
     */
    public static function normalize(string $value): string {
        $value = core_text::specialtoascii($value);
        $value = core_text::strtolower($value);
        $value = preg_replace('/[^\w\s]/u', '', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Character level similarity, 0-100.
     *
     * PHP's levenshtein() throws above 255 bytes per argument, which real titles exceed. Rather
     * than truncating and comparing prefixes, this returns 0 and lets the word overlap measure
     * carry the comparison, exactly as it does for reordered words.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public static function levenshtein_similarity(string $a, string $b): int {
        if ($a === '' || $b === '') {
            return 0;
        }
        if (strlen($a) > self::LEVENSHTEIN_MAX || strlen($b) > self::LEVENSHTEIN_MAX) {
            return 0;
        }

        $distance = levenshtein($a, $b);
        $maxlength = max(strlen($a), strlen($b));

        return $maxlength === 0 ? 0 : (int) max(0, round((1 - $distance / $maxlength) * 100));
    }

    /**
     * Word overlap (Jaccard) similarity, 0-100.
     *
     * Robust to reordered words, which character distance punishes heavily.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public static function word_overlap_similarity(string $a, string $b): int {
        $wordsa = array_filter(preg_split('/\s+/u', self::normalize($a)));
        $wordsb = array_filter(preg_split('/\s+/u', self::normalize($b)));

        if (!$wordsa || !$wordsb) {
            return 0;
        }

        $seta = array_unique($wordsa);
        $setb = array_unique($wordsb);

        $intersection = count(array_intersect($seta, $setb));
        $union = count(array_unique(array_merge($seta, $setb)));

        return $union === 0 ? 0 : (int) round(($intersection / $union) * 100);
    }

    /**
     * The better of the character and word level measures.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public static function similarity(string $a, string $b): int {
        if ($a === '' || $b === '') {
            return 0;
        }

        $cleana = self::normalize($a);
        $cleanb = self::normalize($b);

        if ($cleana === $cleanb) {
            return 100;
        }

        return max(
            self::levenshtein_similarity($cleana, $cleanb),
            self::word_overlap_similarity($a, $b),
        );
    }

    /**
     * Title similarity, 0-100.
     *
     * @param string $expected The title as parsed out of the student's reference.
     * @param string $found The title of the candidate record.
     * @return int
     */
    public static function title_similarity(string $expected, string $found): int {
        if ($expected === '' || $found === '') {
            return 0;
        }

        $similarity = self::similarity($expected, $found);

        // When the parser could not isolate the title, the "expected" string is often the whole
        // reference. A candidate title appearing inside it verbatim is a strong signal.
        $cleanexpected = self::normalize($expected);
        $cleanfound = self::normalize($found);
        if ($similarity < 90 && core_text::strlen($cleanfound) > 20 && str_contains($cleanexpected, $cleanfound)) {
            $similarity = 95;
        }

        return $similarity;
    }

    /**
     * Author similarity, 0-100.
     *
     * Compares family names only. Reference lists abbreviate given names inconsistently, so
     * anything stricter produces false mismatches on correctly cited works.
     *
     * The question asked is "are the people named in this reference on the work we found", not
     * "did the reference name everybody". Those differ whenever a reference truncates a long
     * author list, which is the norm: scoring against the full list would mark a correctly cited
     * eight author paper at 25% simply because the student named two of them.
     *
     * @param string $expected The author text from the student's reference.
     * @param string[] $found The candidate record's author names.
     * @return int
     */
    public static function author_similarity(string $expected, array $found): int {
        // With nothing to compare, do not invent a penalty.
        if (trim($expected) === '' || !$found) {
            return 100;
        }

        $haystack = self::normalize($expected);

        $surnames = [];
        foreach ($found as $author) {
            $surname = self::surname((string) $author);
            if ($surname !== null) {
                $surnames[] = $surname;
            }
        }

        if (!$surnames) {
            return 100;
        }

        // Compare only as many of the record's authors as the reference actually named. Author
        // order is preserved by every database we use, and references list authors in order.
        $named = self::count_named_authors($expected);
        $limit = $named > 0 ? min(count($surnames), $named) : count($surnames);

        $matched = 0;
        $comparable = 0;
        foreach (array_slice($surnames, 0, $limit) as $family) {
            $comparable++;
            if (str_contains($haystack, $family)) {
                $matched++;
            }
        }

        if ($comparable === 0) {
            return 100;
        }

        // Citing "et al." means the rest were deliberately omitted, so one hit is a full match.
        if ($matched > 0 && preg_match('/\bet\s*al\b|\band\s+others\b/i', $expected)) {
            return 100;
        }

        return (int) round(($matched / $comparable) * 100);
    }

    /**
     * How many people a reference's author text appears to name.
     *
     * Counts words that could be surnames, which means discarding initials and the connecting
     * words references use. Approximate by nature, and only ever used to bound a comparison.
     *
     * @param string $expected
     * @return int
     */
    protected static function count_named_authors(string $expected): int {
        $skip = ['and', 'et', 'al', 'others', 'with', 'by', 'the'];

        $count = 0;
        foreach (preg_split('/\s+/u', self::normalize($expected)) ?: [] as $word) {
            if (core_text::strlen($word) > 1 && !in_array($word, $skip, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The comparable family name from a full author name.
     *
     * DBLP appends disambiguation digits such as "0001" to names, which are dropped. Single
     * letters are initials rather than names, so they yield nothing to compare.
     *
     * @param string $author
     * @return string|null Null when the name offers nothing comparable.
     */
    protected static function surname(string $author): ?string {
        $parts = array_values(array_filter(
            preg_split('/\s+/u', self::normalize($author)) ?: [],
            static fn($part) => $part !== '' && !preg_match('/^\d+$/', $part),
        ));

        if (!$parts) {
            return null;
        }

        $family = end($parts);

        // Two letter family names are common; single letters are initials.
        return core_text::strlen($family) > 1 ? $family : null;
    }

    /**
     * Score a candidate record against a parsed reference.
     *
     * Title dominates because it is the only field a reference almost always states unambiguously.
     * Fields the student did not supply are dropped from the weighting rather than scored as zero,
     * so a reference that omits the journal is not punished for it.
     *
     * @param array $expected Parsed reference: title, authors, journal, year, doi.
     * @param array $found Candidate record: title, authors (array), journal, year, doi.
     * @return array{confidence: int, titlescore: int, authorscore: int, journalscore: int, issues: string[]}
     */
    public static function score(array $expected, array $found): array {
        $titlescore = self::title_similarity(
            (string) ($expected['title'] ?? ''),
            (string) ($found['title'] ?? ''),
        );
        $authorscore = self::author_similarity(
            (string) ($expected['authors'] ?? ''),
            (array) ($found['authors'] ?? []),
        );
        $journalscore = self::similarity(
            (string) ($expected['journal'] ?? ''),
            (string) ($found['journal'] ?? ''),
        );

        $weights = ['title' => 0.6, 'authors' => 0.3, 'journal' => 0.1];
        $scores = ['title' => $titlescore, 'authors' => $authorscore, 'journal' => $journalscore];

        // Only weight fields we can actually compare.
        $available = ['title' => true, 'authors' => true, 'journal' => true];
        if (trim((string) ($expected['journal'] ?? '')) === '' || trim((string) ($found['journal'] ?? '')) === '') {
            $available['journal'] = false;
        }
        if (trim((string) ($expected['authors'] ?? '')) === '' || empty($found['authors'])) {
            $available['authors'] = false;
        }

        $total = 0.0;
        $divisor = 0.0;
        foreach ($available as $field => $usable) {
            if ($usable) {
                $total += $weights[$field] * $scores[$field];
                $divisor += $weights[$field];
            }
        }
        $confidence = $divisor > 0 ? (int) round($total / $divisor) : 0;

        return [
            'confidence' => $confidence,
            'titlescore' => $titlescore,
            'authorscore' => $authorscore,
            'journalscore' => $available['journal'] ? $journalscore : null,
            'issues' => self::detect_issues($expected, $found, $authorscore, $available['authors']),
        ];
    }

    /**
     * Describe the specific ways a candidate disagrees with the reference.
     *
     * These are the actionable part of the report: "we found it, but you have the year wrong" is
     * far more useful to a student than a bare confidence percentage.
     *
     * @param array $expected
     * @param array $found
     * @param int $authorscore
     * @param bool $authorscomparable
     * @return string[]
     */
    protected static function detect_issues(
        array $expected,
        array $found,
        int $authorscore,
        bool $authorscomparable,
    ): array {
        $issues = [];

        if ($authorscomparable && $authorscore < 50) {
            $issues[] = get_string('issue_authors', 'assignsubmission_refchecker');
        }

        $expectedyear = (int) ($expected['year'] ?? 0);
        $foundyear = (int) ($found['year'] ?? 0);
        if ($expectedyear && $foundyear && $expectedyear !== $foundyear) {
            // A year out by one is usually online-first versus issue publication, not an error.
            if (abs($expectedyear - $foundyear) > 1) {
                $issues[] = get_string('issue_year', 'assignsubmission_refchecker', (object) [
                    'cited' => $expectedyear,
                    'found' => $foundyear,
                ]);
            }
        }

        $expecteddoi = self::normalize_doi((string) ($expected['doi'] ?? ''));
        $founddoi = self::normalize_doi((string) ($found['doi'] ?? ''));
        if ($expecteddoi !== '' && $founddoi !== '' && $expecteddoi !== $founddoi) {
            $issues[] = get_string('issue_doi', 'assignsubmission_refchecker');
        }

        return $issues;
    }

    /**
     * Reduce a DOI to a comparable form, stripping resolver prefixes and case.
     *
     * @param string $doi
     * @return string
     */
    public static function normalize_doi(string $doi): string {
        $doi = trim($doi);
        $doi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi);
        $doi = preg_replace('/^doi:\s*/i', '', $doi);

        return core_text::strtolower(rtrim($doi, '. '));
    }
}

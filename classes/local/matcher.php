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
 * The similarity measures, the 70% minimum title threshold, the extra author check and the preprint
 * venue list are ported from the References-Validation project
 * (https://github.com/zabbonat/References-Validation), which is MIT licensed and copyright Diletta
 * Abbonato. Keeping the same measures means results stay comparable with that tool.
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

    /**
     * Title similarity below which an extra author is not reported.
     *
     * Higher than the floor above, deliberately. A weak title match means the candidate may simply
     * be the wrong paper, which is what the confidence score is for; reading the author list of a
     * doubtful match as evidence of fabrication would be accusing a student on thin grounds.
     *
     * @var int
     */
    public const MIN_TITLE_FOR_EXTRA_AUTHORS = 85;

    /**
     * More unexplained names than this and none of them are reported.
     *
     * Past a handful the likeliest explanation is that the parser handed over a segment that was
     * never an author list at all, and a wrong accusation is far worse than saying nothing. The
     * general author issue still covers the case.
     *
     * @var int
     */
    public const MAX_EXTRA_AUTHORS = 3;

    /** @var int Title similarity above which "none of these authors" is worth saying outright. */
    private const MIN_TITLE_FOR_AUTHORS_NONE = 80;

    /** @var int Author agreement below which the authors are reported as not matching. */
    private const MIN_AUTHOR_AGREEMENT = 50;

    /** @var int Journal agreement below which a preprint difference is worth explaining. */
    private const MIN_JOURNAL_AGREEMENT = 50;

    /** @var int Journal agreement below which the venues are reported as disagreeing. */
    private const MIN_JOURNAL_MATCH = 40;

    /** @var int Years of drift a preprint and its published form can explain. */
    private const YEAR_VERSION_DRIFT = 2;

    /** @var int Deducted from the author score for each cited name that is not on the work found. */
    private const EXTRA_AUTHOR_PENALTY = 20;

    /** @var int PHP's levenshtein() refuses arguments longer than this. */
    private const LEVENSHTEIN_MAX = 255;

    /**
     * Words that appear in an author list without naming anybody.
     *
     * Shared by the surname splitter and the count that bounds the author comparison, so the two
     * cannot disagree about how many people a reference names.
     *
     * @var string[]
     */
    private const AUTHOR_NOISE = [
        'and', 'et', 'al', 'others', 'with', 'by', 'the',
        'jr', 'sr', 'ii', 'iii', 'iv', 'ed', 'eds', 'editor', 'editors', 'trans',
    ];

    /**
     * Venue names that mean the record is a preprint rather than a published version.
     *
     * arXiv is one of the databases this plugin asks, so a reference to the journal version of a
     * paper we answer from arXiv is the normal case, not an error. Matched on word boundaries: as
     * bare substrings the shorter entries are hopeless, with "hal" inside both Challenges and
     * Chalmers.
     *
     * @var string[]
     */
    private const PREPRINT_VENUES = [
        'arxiv', 'biorxiv', 'medrxiv', 'chemrxiv', 'ssrn', 'preprint', 'preprints',
        'research square', 'osf preprints', 'techrxiv', 'eartharxiv', 'engrxiv',
        'socarxiv', 'psyarxiv', 'edarxiv', 'hal', 'repec', 'working paper',
    ];

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
        $count = 0;
        foreach (preg_split('/\s+/u', self::normalize($expected)) ?: [] as $word) {
            if (core_text::strlen($word) > 1 && !in_array($word, self::AUTHOR_NOISE, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The name-bearing words of a reference's author text, grouped as the reference punctuated them.
     *
     * Every style separates one person from the next with a comma, a semicolon, an ampersand or the
     * word "and". Which of those, and whether the surname or the initials come first, varies by style
     * and by how carefully the reference was typed, so a group is not assumed to be exactly one
     * person. What it does hold is words that belong together — "da Silva Meireles" is never split
     * across two groups — and that is what lets a name written out more fully than the database has
     * it be recognised rather than reported as an invention.
     *
     * Initials, the words that join an author list together and the suffixes attached to a name are
     * all dropped, so a group can come back empty and is then discarded.
     *
     * @param string $authors The author text from a reference.
     * @return array<int, array<string, string>> One group per punctuated part, each mapping a
     *      normalised word to the form the student wrote it in.
     */
    public static function cited_author_parts(string $authors): array {
        $parts = [];

        foreach (preg_split('/[,;&]|\sand\s/iu', $authors) ?: [] as $part) {
            $words = [];

            foreach (preg_split('/\s+/u', trim($part)) ?: [] as $word) {
                $normalised = self::normalize($word);

                // A single letter is an initial: "Rowatt, A." names one person, not two.
                if (core_text::strlen($normalised) < 2) {
                    continue;
                }
                if (in_array($normalised, self::AUTHOR_NOISE, true)) {
                    continue;
                }
                if (self::is_initials($word)) {
                    continue;
                }

                $words[$normalised] ??= trim($word, " \t.,;:()[]");
            }

            if ($words) {
                $parts[] = $words;
            }
        }

        return $parts;
    }

    /**
     * Whether a word is a run of initials rather than a name.
     *
     * Vancouver style writes them without punctuation and without a space, so "Smith JA" names one
     * person and "JA" must not be read as a second. A short name written normally ("Ho", "Wu") is
     * kept, because only an all-capitals run is treated as initials; a short surname in a reference
     * list typed entirely in capitals is therefore missed, which costs a finding rather than
     * producing a wrong one.
     *
     * @param string $word As written in the reference.
     * @return bool
     */
    protected static function is_initials(string $word): bool {
        $letters = preg_replace('/[^\p{L}]/u', '', $word);

        return $letters !== '' && $letters !== null
            && core_text::strlen($letters) <= 3
            && $letters === core_text::strtoupper($letters);
    }

    /**
     * The names a reference gives that are on no part of the work that was found.
     *
     * This is the mirror image of author_similarity(), and the reason both exist. That one asks
     * whether the people named in the reference are on the work, walking the record's authors; it
     * therefore cannot see anything the reference has *added*, and a citation padded with a name
     * that was never on the paper scores a clean 100. This one walks the other way.
     *
     * Ported from the References-Validation project's extra author check, without its given-name
     * exoneration: that rule exists there only to keep initials from being flagged, and it lets any
     * invented name through whose first letter happens to match a real author's. The splitter above
     * removes initials properly instead.
     *
     * @param array $expected Parsed reference: authors, title, journal, year, doi.
     * @param array $found Candidate record: title, authors (array), journal, year, doi.
     * @return string[] The names as the student wrote them, in the order they were cited. Empty when
     *      there is nothing to compare, or when there are too many to be believable.
     */
    public static function extra_authors(array $expected, array $found): array {
        $cited = trim((string) ($expected['authors'] ?? ''));

        // Two-letter names are real, but a one-letter "author" from a database is junk and would
        // otherwise explain away every name in the reference as containing it.
        $recordauthors = array_filter(
            array_map([self::class, 'normalize'], (array) ($found['authors'] ?? [])),
            static fn($author) => core_text::strlen($author) > 1,
        );

        // With nothing to compare against, say nothing. A database that returned no author list is
        // not evidence that the people cited do not exist.
        if ($cited === '' || !$recordauthors) {
            return [];
        }

        // The title and journal are searched as well as the author list because the parser sometimes
        // hands over a segment that runs past the authors, and a word from the title of the very
        // work we just found is the last thing to accuse anybody over.
        $context = self::normalize(
            (string) ($found['title'] ?? '') . ' ' . (string) ($found['journal'] ?? '')
        );

        $extras = [];
        foreach (self::cited_author_parts($cited) as $words) {
            foreach (array_keys($words) as $normalised) {
                if (str_contains($context, $normalised)) {
                    continue 2;
                }

                foreach ($recordauthors as $author) {
                    // Overlap in either direction, because none of the databases we ask hand back the
                    // family name separately: a reference may shorten "da Silva Meireles" to "Silva"
                    // or spell out what the database has as "Silva", and all that can be established
                    // is that the two names share something.
                    if (str_contains($author, $normalised) || str_contains($normalised, $author)) {
                        continue 3;
                    }
                }
            }

            // One word of this group was enough to identify somebody on the work, and the group is
            // words the reference wrote together, so the rest of it is that person's name at a
            // different length rather than a second person. Nothing here matched anybody, so the
            // group is reported whole: "Andrew Rowatt" reads as one invented name, which is what it
            // is, where word by word it would read as two.
            $extras[] = implode(' ', $words);
        }

        return count($extras) > self::MAX_EXTRA_AUTHORS ? [] : $extras;
    }

    /**
     * Whether a venue name means the record is a preprint rather than a published version.
     *
     * @param string|null $venue
     * @return bool
     */
    public static function is_preprint_venue(?string $venue): bool {
        $venue = self::normalize((string) $venue);
        if ($venue === '') {
            return false;
        }

        foreach (self::PREPRINT_VENUES as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/u', $venue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The issues that can be found in a reference without having anything to compare it against.
     *
     * Everything else here needs a candidate record. These do not, which is what makes them worth
     * having: a reference nothing was found for is exactly the case where there is otherwise nothing
     * to tell the reader beyond "not found".
     *
     * @param array $expected Parsed reference.
     * @return array[] Issue records.
     */
    public static function reference_issues(array $expected): array {
        $issues = [];

        // Next year is allowed: a paper published online in December routinely carries the following
        // year's issue date. Anything beyond that has not happened.
        $cited = (int) ($expected['year'] ?? 0);
        if ($cited > (int) date('Y') + 1) {
            $issues[] = issue::make(issue::YEAR_FUTURE, ['cited' => $cited]);
        }

        return $issues;
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
     * @return array{confidence: int, titlescore: int, authorscore: int, journalscore: int,
     *     extraauthors: string[], issues: array[]}
     */
    public static function score(array $expected, array $found): array {
        $titlescore = self::title_similarity(
            (string) ($expected['title'] ?? ''),
            (string) ($found['title'] ?? ''),
        );
        $rawauthorscore = self::author_similarity(
            (string) ($expected['authors'] ?? ''),
            (array) ($found['authors'] ?? []),
        );
        $journalscore = self::journal_similarity(
            (string) ($expected['journal'] ?? ''),
            (string) ($found['journal'] ?? ''),
        );

        $extras = $titlescore >= self::MIN_TITLE_FOR_EXTRA_AUTHORS
            ? self::extra_authors($expected, $found)
            : [];

        // The author score answers "are the people cited on this work", and a name that is on no
        // part of it answers exactly that — an answer the score cannot otherwise reach, because
        // author_similarity() only ever walks the record's authors. Deducting here supplies a
        // missing signal rather than counting an existing one twice, which is why the journal and
        // year issues below carry no penalty and this one does. Applied after author_similarity()
        // has finished so that citing "et al." cannot license adding a name.
        $authorscore = max(0, $rawauthorscore - self::EXTRA_AUTHOR_PENALTY * count($extras));

        $comparable = self::comparable_fields($expected, $found);

        return [
            'confidence' => self::weighted_confidence(
                ['title' => $titlescore, 'authors' => $authorscore, 'journal' => $journalscore],
                $comparable,
            ),
            'titlescore' => $titlescore,
            'authorscore' => $authorscore,
            'journalscore' => $comparable['journal'] ? $journalscore : null,
            'extraauthors' => $extras,
            'issues' => self::detect_issues($expected, $found, [
                'titlescore' => $titlescore,
                // The score before the extra author deduction, so that the general author issue
                // keeps meaning "the people you cited are not the ones on this work" instead of
                // becoming an echo of a penalty this class applied itself.
                'authorscore' => $rawauthorscore,
                'journalscore' => $journalscore,
                'extraauthors' => $extras,
                'authorscomparable' => $comparable['authors'],
                'journalcomparable' => $comparable['journal'],
            ]),
        ];
    }

    /**
     * Which fields both sides actually state.
     *
     * A field the student did not supply is dropped from the weighting rather than scored as zero, so
     * a reference that omits the journal is not punished for omitting it.
     *
     * @param array $expected Parsed reference.
     * @param array $found Candidate record.
     * @return array<string, bool>
     */
    protected static function comparable_fields(array $expected, array $found): array {
        return [
            // Always weighed: without a title there is no match to consider in the first place.
            'title' => true,
            'authors' => trim((string) ($expected['authors'] ?? '')) !== '' && !empty($found['authors']),
            'journal' => trim((string) ($expected['journal'] ?? '')) !== ''
                && trim((string) ($found['journal'] ?? '')) !== '',
        ];
    }

    /**
     * The weighted mean of the sub-scores, over the comparable fields only.
     *
     * Title dominates because it is the only field a reference almost always states unambiguously.
     *
     * @param array $scores Sub-score per field: title, authors, journal.
     * @param array $comparable Whether each of those fields is worth weighing, from comparable_fields().
     * @return int 0-100.
     */
    protected static function weighted_confidence(array $scores, array $comparable): int {
        $weights = ['title' => 0.6, 'authors' => 0.3, 'journal' => 0.1];

        $total = 0.0;
        $divisor = 0.0;
        foreach ($comparable as $field => $usable) {
            if ($usable) {
                $total += $weights[$field] * $scores[$field];
                $divisor += $weights[$field];
            }
        }

        return $divisor > 0 ? (int) round($total / $divisor) : 0;
    }

    /**
     * Journal similarity, 0-100.
     *
     * @param string $expected The venue as parsed out of the student's reference.
     * @param string $found The venue of the candidate record.
     * @return int
     */
    public static function journal_similarity(string $expected, string $found): int {
        if ($expected === '' || $found === '') {
            return 0;
        }

        $similarity = self::similarity($expected, $found);

        // Venues get subtitles, series names and the owning society attached to them inconsistently,
        // so one name sitting whole inside the other is agreement rather than a difference: "Open
        // Innovation" against "Open innovation: researching a new paradigm" is the same book. Both
        // sides have to be long enough for that to mean something, or a short name is contained in
        // everything.
        $cleanexpected = self::normalize($expected);
        $cleanfound = self::normalize($found);
        if (
            $similarity < 90
            && core_text::strlen($cleanexpected) > 3
            && core_text::strlen($cleanfound) > 3
            && (str_contains($cleanexpected, $cleanfound) || str_contains($cleanfound, $cleanexpected))
        ) {
            $similarity = 95;
        }

        return $similarity;
    }

    /**
     * Describe the specific ways a candidate disagrees with the reference.
     *
     * These are the actionable part of the report: "we found it, but you have the year wrong" is
     * far more useful to a student than a bare confidence percentage.
     *
     * @param array $expected Parsed reference.
     * @param array $found Candidate record.
     * @param array $context The scores and comparability flags from score().
     * @return array[] Issue records.
     */
    protected static function detect_issues(array $expected, array $found, array $context): array {
        // One venue being a preprint server while the other is not means we are looking at two forms
        // of the same work, which explains both a venue difference and a year or two of drift.
        $context['versiondifference'] = self::is_preprint_venue((string) ($expected['journal'] ?? ''))
            !== self::is_preprint_venue((string) ($found['journal'] ?? ''));

        $issues = array_merge(
            self::author_issues($context),
            self::year_issues($expected, $found, $context),
            self::journal_issues($expected, $found, $context),
        );

        $expecteddoi = self::normalize_doi((string) ($expected['doi'] ?? ''));
        $founddoi = self::normalize_doi((string) ($found['doi'] ?? ''));
        if ($expecteddoi !== '' && $founddoi !== '' && $expecteddoi !== $founddoi) {
            $issues[] = issue::make(issue::DOI);
        }

        return $issues;
    }

    /**
     * What to say about the people a reference names.
     *
     * @param array $context From detect_issues().
     * @return array[] Issue records.
     */
    protected static function author_issues(array $context): array {
        $issues = [];

        $extras = (array) $context['extraauthors'];
        if ($extras) {
            $issues[] = issue::make(issue::EXTRAAUTHORS, ['names' => implode(', ', $extras)]);
        }

        if (!$context['authorscomparable']) {
            return $issues;
        }

        $authorscore = (int) $context['authorscore'];
        if ($authorscore === 0 && (int) $context['titlescore'] >= self::MIN_TITLE_FOR_AUTHORS_NONE) {
            // The right work and none of the right people. Worth saying outright rather than leaving
            // the reader to infer it from a percentage, because it is the shape a fabricated
            // reference most often takes: a real paper with an invented author list.
            $issues[] = issue::make(issue::AUTHORS_NONE);
        } else if ($authorscore < self::MIN_AUTHOR_AGREEMENT) {
            $issues[] = issue::make(issue::AUTHORS);
        }

        return $issues;
    }

    /**
     * What to say about the year a reference gives.
     *
     * @param array $expected Parsed reference.
     * @param array $found Candidate record.
     * @param array $context From detect_issues().
     * @return array[] Issue records.
     */
    protected static function year_issues(array $expected, array $found, array $context): array {
        $cited = (int) ($expected['year'] ?? 0);
        $foundyear = (int) ($found['year'] ?? 0);

        if (!$cited || !$foundyear || $cited === $foundyear) {
            return [];
        }

        $drift = abs($cited - $foundyear);
        $a = ['cited' => $cited, 'found' => $foundyear];

        if ($context['versiondifference']) {
            // A preprint and the journal version of the same work are routinely a year or two apart.
            // Named as a version difference rather than reported as an error, and not passed over in
            // silence either: which form was found is worth knowing.
            return [issue::make($drift <= self::YEAR_VERSION_DRIFT ? issue::YEAR_VERSION : issue::YEAR, $a)];
        }

        // A single year is online-first against issue publication, which is not an error at all.
        return $drift > 1 ? [issue::make(issue::YEAR, $a)] : [];
    }

    /**
     * What to say about the venue a reference gives.
     *
     * @param array $expected Parsed reference.
     * @param array $found Candidate record.
     * @param array $context From detect_issues().
     * @return array[] Issue records.
     */
    protected static function journal_issues(array $expected, array $found, array $context): array {
        if (!$context['journalcomparable']) {
            return [];
        }

        $journalscore = (int) $context['journalscore'];
        $a = [
            'cited' => (string) ($expected['journal'] ?? ''),
            'found' => (string) ($found['journal'] ?? ''),
        ];

        if (
            $context['versiondifference']
            && $journalscore < self::MIN_JOURNAL_AGREEMENT
            && (int) $context['titlescore'] >= self::MIN_TITLE_SIMILARITY
        ) {
            return [issue::make(issue::JOURNAL_VERSION, $a)];
        }

        // Only a wide gap is reported. Venues are cited loosely enough that a middling score is
        // usually the same journal typed differently, and the weighted mean has already taken it
        // into account.
        return $journalscore < self::MIN_JOURNAL_MATCH ? [issue::make(issue::JOURNAL, $a)] : [];
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

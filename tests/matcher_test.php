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

use assignsubmission_refchecker\local\issue;
use assignsubmission_refchecker\local\matcher;

/**
 * Tests for the reference matching measures.
 *
 * These are pure functions, so the tests need no database and run fast. They are the safety net
 * for the logic ported from References-Validation.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\matcher
 */
final class matcher_test extends \advanced_testcase {
    /**
     * Normalising strips accents, case and punctuation.
     */
    public function test_normalize(): void {
        $this->assertSame('produccao e uso', matcher::normalize('Produçção, e USO!'));
        $this->assertSame('attention is all you need', matcher::normalize('Attention Is All You Need.'));
        $this->assertSame('', matcher::normalize('   '));
    }

    /**
     * Identical titles score 100 whatever their punctuation and case.
     */
    public function test_identical_titles_score_full(): void {
        $this->assertSame(100, matcher::title_similarity(
            'Attention is all you need',
            'Attention Is All You Need.',
        ));
    }

    /**
     * Reordered words stay high, which character distance alone would not manage.
     */
    public function test_reordered_words_stay_similar(): void {
        $score = matcher::title_similarity(
            'Producao e uso setorial de tecnologia no Brasil',
            'Producao e uso de tecnologia setorial no Brasil',
        );

        $this->assertGreaterThanOrEqual(90, $score);
    }

    /**
     * Unrelated titles fall below the acceptance threshold.
     */
    public function test_unrelated_titles_fall_below_threshold(): void {
        $score = matcher::title_similarity(
            'Attention is all you need',
            'A study of soil erosion in coastal wetlands',
        );

        $this->assertLessThan(matcher::MIN_TITLE_SIMILARITY, $score);
    }

    /**
     * Titles longer than PHP's levenshtein limit must not fatal, and must still score.
     *
     * This is the trap in the port: levenshtein() throws above 255 bytes, and real titles do
     * exceed that.
     */
    public function test_long_titles_do_not_fatal(): void {
        $long = str_repeat('a very long title about something in particular ', 12);

        $this->assertSame(100, matcher::title_similarity($long, $long));
        $this->assertIsInt(matcher::title_similarity($long, $long . ' extended'));
    }

    /**
     * A candidate title quoted verbatim inside the whole reference is a strong signal.
     */
    public function test_title_contained_in_full_reference(): void {
        $score = matcher::title_similarity(
            'Smith, J. (2019). The effects of tidal variation on estuarine ecology. Marine Biology.',
            'The effects of tidal variation on estuarine ecology',
        );

        $this->assertGreaterThanOrEqual(95, $score);
    }

    /**
     * Author comparison uses family names, so initials style does not matter.
     */
    public function test_author_similarity_matches_family_names(): void {
        $this->assertSame(100, matcher::author_similarity(
            'Vaswani, A., Shazeer, N.',
            ['Ashish Vaswani', 'Noam Shazeer'],
        ));
    }

    /**
     * "et al." means the omission was deliberate, so a partial hit is a full match.
     */
    public function test_author_similarity_honours_et_al(): void {
        $this->assertSame(100, matcher::author_similarity(
            'Vaswani, A. et al.',
            ['Ashish Vaswani', 'Noam Shazeer', 'Niki Parmar'],
        ));
    }

    /**
     * A reference that names only the first few of many authors is not penalised for it.
     *
     * Regression test. Scoring against the record's full author list marked a correctly cited
     * eight author paper at 25%, which dragged an otherwise perfect match down to "partial".
     * Truncated author lists are the norm, not the exception.
     */
    public function test_truncated_author_list_is_not_penalised(): void {
        $eightauthors = [
            'Ashish Vaswani', 'Noam Shazeer', 'Niki Parmar', 'Jakob Uszkoreit',
            'Llion Jones', 'Aidan Gomez', 'Lukasz Kaiser', 'Illia Polosukhin',
        ];

        $this->assertSame(100, matcher::author_similarity('Vaswani, A., Shazeer, N.', $eightauthors));
        $this->assertSame(100, matcher::author_similarity('Vaswani, A.', $eightauthors));
    }

    /**
     * Naming authors who are not on the work is still caught.
     *
     * The truncation allowance must not become a blanket pass.
     */
    public function test_wrong_authors_are_still_caught(): void {
        $eightauthors = [
            'Ashish Vaswani', 'Noam Shazeer', 'Niki Parmar', 'Jakob Uszkoreit',
            'Llion Jones', 'Aidan Gomez', 'Lukasz Kaiser', 'Illia Polosukhin',
        ];

        $this->assertSame(0, matcher::author_similarity('Smith, J., Brown, A.', $eightauthors));
    }

    /**
     * With nothing to compare, no penalty is invented.
     */
    public function test_author_similarity_without_data_is_neutral(): void {
        $this->assertSame(100, matcher::author_similarity('', ['Ashish Vaswani']));
        $this->assertSame(100, matcher::author_similarity('Vaswani, A.', []));
    }

    /**
     * Completely different authors score zero.
     */
    public function test_author_similarity_detects_mismatch(): void {
        $this->assertSame(0, matcher::author_similarity(
            'Smith, J.',
            ['Ashish Vaswani', 'Noam Shazeer'],
        ));
    }

    /**
     * A good match on every comparable field scores high overall.
     */
    public function test_score_of_a_good_match(): void {
        $result = matcher::score(
            [
                'title' => 'Attention is all you need',
                'authors' => 'Vaswani, A. et al.',
                'journal' => 'Advances in Neural Information Processing Systems',
                'year' => '2017',
            ],
            [
                'title' => 'Attention Is All You Need',
                'authors' => ['Ashish Vaswani', 'Noam Shazeer'],
                'journal' => 'Advances in Neural Information Processing Systems',
                'year' => 2017,
            ],
        );

        $this->assertGreaterThanOrEqual(90, $result['confidence']);
        $this->assertSame([], $result['issues']);
    }

    /**
     * A reference that omits the journal is not punished for the omission.
     */
    public function test_missing_journal_is_dropped_from_the_weighting(): void {
        $result = matcher::score(
            ['title' => 'Attention is all you need', 'authors' => 'Vaswani, A. et al.'],
            [
                'title' => 'Attention Is All You Need',
                'authors' => ['Ashish Vaswani'],
                'journal' => 'Advances in Neural Information Processing Systems',
            ],
        );

        $this->assertNull($result['journalscore']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
    }

    /**
     * A wrong year is reported, but a one year drift is not.
     */
    public function test_year_issues(): void {
        $base = ['title' => 'A paper', 'authors' => 'Smith, J.'];
        $found = ['title' => 'A paper', 'authors' => ['Jane Smith'], 'year' => 2020];

        $onedrift = matcher::score($base + ['year' => '2019'], $found);
        $this->assertSame([], $onedrift['issues']);

        $wrong = matcher::score($base + ['year' => '2011'], $found);
        $this->assertSame(
            [['code' => issue::YEAR, 'a' => ['cited' => 2011, 'found' => 2020]]],
            $wrong['issues'],
        );
    }

    /**
     * A cited year that has not happened yet is reported without needing anything to compare it to.
     */
    public function test_a_future_year_is_reported_with_no_record_at_all(): void {
        $next = (int) date('Y') + 1;

        // Next year is allowed: work published online in December carries the following year's date.
        $this->assertSame([], matcher::reference_issues(['year' => (string) $next]));

        $this->assertSame(
            [['code' => issue::YEAR_FUTURE, 'a' => ['cited' => $next + 5]]],
            matcher::reference_issues(['year' => (string) ($next + 5)]),
        );
        $this->assertSame([], matcher::reference_issues([]));
    }

    /**
     * Preprint venues are recognised, and the short names in the list do not match ordinary words.
     */
    public function test_preprint_venues_are_recognised_on_word_boundaries(): void {
        $this->assertTrue(matcher::is_preprint_venue('arXiv'));
        $this->assertTrue(matcher::is_preprint_venue('arXiv [cs.CL]'));
        $this->assertTrue(matcher::is_preprint_venue('bioRxiv'));
        $this->assertTrue(matcher::is_preprint_venue('NBER Working Paper Series'));

        // References-Validation tests these as bare substrings, which makes "hal" match both of
        // these. Anchoring on word boundaries is the whole reason the check is written out here.
        $this->assertFalse(matcher::is_preprint_venue('Challenges'));
        $this->assertFalse(matcher::is_preprint_venue('Chalmers University Press'));
        $this->assertFalse(matcher::is_preprint_venue('Nature'));
        $this->assertFalse(matcher::is_preprint_venue(''));
        $this->assertFalse(matcher::is_preprint_venue(null));
    }

    /**
     * A journal version answered from a preprint server is explained, not reported as wrong.
     */
    public function test_a_preprint_is_a_version_difference_rather_than_an_error(): void {
        $result = matcher::score(
            [
                'title' => 'Attention is all you need',
                'authors' => 'Vaswani, A. et al.',
                'journal' => 'Advances in Neural Information Processing Systems',
                'year' => '2019',
            ],
            [
                'title' => 'Attention Is All You Need',
                'authors' => ['Ashish Vaswani'],
                'journal' => 'arXiv',
                'year' => 2017,
            ],
        );

        $codes = array_column($result['issues'], 'code');
        $this->assertContains(issue::YEAR_VERSION, $codes);
        $this->assertContains(issue::JOURNAL_VERSION, $codes);
        $this->assertNotContains(issue::YEAR, $codes);
        $this->assertNotContains(issue::JOURNAL, $codes);
    }

    /**
     * Two years of drift is only forgiven when a preprint explains it.
     */
    public function test_two_years_of_drift_without_a_preprint_is_still_wrong(): void {
        $result = matcher::score(
            ['title' => 'A paper', 'authors' => 'Smith, J.', 'journal' => 'Nature', 'year' => '2018'],
            ['title' => 'A paper', 'authors' => ['Jane Smith'], 'journal' => 'Nature', 'year' => 2020],
        );

        $this->assertSame([issue::YEAR], array_column($result['issues'], 'code'));
    }

    /**
     * A wholly different journal is reported; a subtitle or a series name is not.
     */
    public function test_journal_issues(): void {
        $wrong = matcher::score(
            ['title' => 'A paper', 'journal' => 'Journal of Things'],
            ['title' => 'A paper', 'journal' => 'Quarterly Review of Biology'],
        );
        $this->assertSame(
            [['code' => issue::JOURNAL, 'a' => [
                'cited' => 'Journal of Things',
                'found' => 'Quarterly Review of Biology',
            ]]],
            $wrong['issues'],
        );

        // One name sitting whole inside the other is the same venue typed two ways.
        $subtitled = matcher::score(
            ['title' => 'A paper', 'journal' => 'Open Innovation'],
            ['title' => 'A paper', 'journal' => 'Open innovation: researching a new paradigm'],
        );
        $this->assertSame([], $subtitled['issues']);
        $this->assertSame(95, $subtitled['journalscore']);
    }

    /**
     * The right work with entirely the wrong people is said outright rather than as a percentage.
     */
    public function test_no_matching_authors_at_all_is_its_own_issue(): void {
        $result = matcher::score(
            ['title' => 'Attention is all you need', 'authors' => 'Smith, J., Brown, A.'],
            ['title' => 'Attention is all you need', 'authors' => ['Ashish Vaswani', 'Noam Shazeer']],
        );

        $codes = array_column($result['issues'], 'code');
        $this->assertContains(issue::AUTHORS_NONE, $codes);
        $this->assertNotContains(issue::AUTHORS, $codes);
    }

    /**
     * DOIs are compared after stripping resolver prefixes and case.
     */
    public function test_doi_normalisation(): void {
        $this->assertSame('10.1234/abc', matcher::normalize_doi('https://doi.org/10.1234/ABC'));
        $this->assertSame('10.1234/abc', matcher::normalize_doi('doi: 10.1234/abc.'));
        $this->assertSame('10.1234/abc', matcher::normalize_doi('10.1234/abc'));
    }

    /**
     * A DOI pointing somewhere else is reported as an issue.
     */
    public function test_mismatched_doi_is_an_issue(): void {
        $result = matcher::score(
            ['title' => 'A paper', 'doi' => '10.1234/abc'],
            ['title' => 'A paper', 'doi' => 'https://doi.org/10.5678/xyz'],
        );

        $this->assertSame([['code' => issue::DOI]], $result['issues']);
    }

    /**
     * A citation padded with a name that is on no part of the work names that name.
     *
     * The case the check exists for, and the one author_similarity() cannot see: every real author is
     * present, so it scores a clean 100 and the invented name passes unremarked.
     */
    public function test_a_name_that_is_not_on_the_work_is_named(): void {
        $real = ['B. Provera', 'A. Montefusco', 'A. Canato'];

        $this->assertSame(
            100,
            matcher::author_similarity('Rowatt, A., Provera, B., Montefusco, A., & Canato, A.', $real),
        );

        $this->assertSame(
            ['Rowatt'],
            matcher::extra_authors(
                ['authors' => 'Rowatt, A., Provera, B., Montefusco, A., & Canato, A.'],
                ['authors' => $real, 'title' => 'Silent innovation', 'journal' => 'Industry and Innovation'],
            ),
        );
    }

    /**
     * Initials are not people, in either of the styles that write them.
     */
    public function test_initials_are_never_read_as_names(): void {
        $this->assertSame(
            [['rowatt' => 'Rowatt'], ['provera' => 'Provera']],
            matcher::cited_author_parts('Rowatt, A., & Provera, B.'),
        );

        // Vancouver runs initials together and drops the punctuation: "Smith JA" is one person.
        $this->assertSame(
            [['smith' => 'Smith'], ['jones' => 'Jones']],
            matcher::cited_author_parts('Smith JA, Jones B'),
        );

        // Nor are the words that join an author list together, or the suffixes attached to a name.
        $this->assertSame(
            [['smith' => 'Smith'], ['jones' => 'Jones']],
            matcher::cited_author_parts('Smith, J. Jr., and Jones, B., et al.'),
        );

        // Words the reference wrote together stay together, so a compound name is one group.
        $this->assertSame(
            [['da' => 'da', 'silva' => 'Silva', 'meireles' => 'Meireles']],
            matcher::cited_author_parts('da Silva Meireles, M.'),
        );
    }

    /**
     * A name is explained by any overlap with a record author, in either direction.
     */
    public function test_names_are_matched_across_author_name_orders_and_compounds(): void {
        // Given name first, which is how every database this plugin asks returns them.
        $this->assertSame([], matcher::extra_authors(
            ['authors' => 'Vaswani, A., & Shazeer, N.'],
            ['authors' => ['Ashish Vaswani', 'Noam Shazeer']],
        ));

        // A reference may shorten a compound family name to its last part, or spell it out in full.
        $this->assertSame([], matcher::extra_authors(
            ['authors' => 'Silva, M.'],
            ['authors' => ['Marco da Silva Meireles']],
        ));
        $this->assertSame([], matcher::extra_authors(
            ['authors' => 'da Silva Meireles, M.'],
            ['authors' => ['Marco Silva']],
        ));
    }

    /**
     * A word the parser took from the title or the venue is not treated as an invented author.
     */
    public function test_a_misparsed_title_word_is_not_an_extra_author(): void {
        $this->assertSame([], matcher::extra_authors(
            ['authors' => 'Vaswani, A. Attention'],
            [
                'authors' => ['Ashish Vaswani'],
                'title' => 'Attention Is All You Need',
                'journal' => 'Neural Information Processing Systems',
            ],
        ));
    }

    /**
     * With nothing to compare against, no accusation is made.
     */
    public function test_extra_authors_needs_both_sides(): void {
        $this->assertSame([], matcher::extra_authors(
            ['authors' => ''],
            ['authors' => ['Ashish Vaswani']],
        ));
        $this->assertSame([], matcher::extra_authors(
            ['authors' => 'Rowatt, A.'],
            ['authors' => []],
        ));
        // A record whose only author is a single letter offers nothing to compare either.
        $this->assertSame([], matcher::extra_authors(
            ['authors' => 'Rowatt, A.'],
            ['authors' => ['R']],
        ));

        // But it must not be allowed to explain away a name, since every name contains a letter.
        $this->assertSame(['Rowatt'], matcher::extra_authors(
            ['authors' => 'Rowatt, A.'],
            ['authors' => ['R', 'Zed Zulu']],
        ));
    }

    /**
     * Past a handful of unexplained names nothing is reported, because the parse is the likelier
     * explanation than a fabricated author list.
     */
    public function test_too_many_unexplained_names_reports_none_of_them(): void {
        $reference = ['authors' => 'One, A., Two, B., Three, C., Four, D.'];
        $record = ['authors' => ['Zed Zulu']];

        $this->assertSame([], matcher::extra_authors($reference, $record));
        $this->assertSame(
            matcher::MAX_EXTRA_AUTHORS,
            count(matcher::extra_authors(
                ['authors' => 'One, A., Two, B., Three, C.'],
                $record,
            )),
        );
    }

    /**
     * An extra author costs the author score, and citing "et al." buys no immunity from it.
     */
    public function test_an_extra_author_is_deducted_even_from_an_et_al_citation(): void {
        $found = ['title' => 'Attention is all you need', 'authors' => ['Ashish Vaswani', 'Noam Shazeer']];

        $clean = matcher::score(['title' => 'Attention is all you need', 'authors' => 'Vaswani, A. et al.'], $found);
        $this->assertSame(100, $clean['authorscore']);
        $this->assertSame([], $clean['extraauthors']);

        $padded = matcher::score(
            ['title' => 'Attention is all you need', 'authors' => 'Rowatt, A., Vaswani, A. et al.'],
            $found,
        );
        $this->assertSame(['Rowatt'], $padded['extraauthors']);
        $this->assertSame(80, $padded['authorscore']);
        $this->assertSame(
            [['code' => issue::EXTRAAUTHORS, 'a' => ['names' => 'Rowatt']]],
            $padded['issues'],
        );
    }

    /**
     * A doubtful title is a doubtful match, not evidence of a fabricated author.
     */
    public function test_a_weak_title_suppresses_the_extra_author_check(): void {
        $result = matcher::score(
            [
                'title' => 'Attention is mostly what you need for things',
                'authors' => 'Rowatt, A., Vaswani, A.',
            ],
            ['title' => 'Attention Is All You Need', 'authors' => ['Ashish Vaswani', 'Noam Shazeer']],
        );

        $this->assertLessThan(matcher::MIN_TITLE_FOR_EXTRA_AUTHORS, $result['titlescore']);
        $this->assertSame([], $result['extraauthors']);
        $this->assertNotContains(issue::EXTRAAUTHORS, array_column($result['issues'], 'code'));
    }
}

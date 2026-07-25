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
        $this->assertNotEmpty($wrong['issues']);
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

        $this->assertNotEmpty($result['issues']);
    }
}

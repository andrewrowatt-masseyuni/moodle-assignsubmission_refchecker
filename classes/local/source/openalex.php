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

namespace assignsubmission_refchecker\local\source;

use assignsubmission_refchecker\local\matcher;
use core_text;

/**
 * Looks references up in OpenAlex.
 *
 * Tried after CrossRef, and earns its place on what CrossRef indexes only patchily: preprints and
 * much conference material. That coverage matters most in computing, engineering and physics,
 * where a reference list can be largely arXiv and proceedings.
 *
 * Unlike CrossRef there is no citation-string matching endpoint, so this searches on the parsed
 * title. When the parser could not isolate a title the search is skipped rather than sending a
 * whole citation to a title filter, which returns confident nonsense.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openalex extends http_source {
    /** @var string The works endpoint. */
    protected const ENDPOINT = 'https://api.openalex.org/works';

    /** @var int A title shorter than this is too generic to search on. */
    protected const MIN_TITLE_LENGTH = 10;

    /**
     * The identifier stored against a result.
     *
     * @return string
     */
    public function get_name(): string {
        return 'openalex';
    }

    /**
     * The name shown in the report.
     *
     * @return string
     */
    public function get_display_name(): string {
        return 'OpenAlex';
    }

    /**
     * Look a reference up.
     *
     * @param array $reference
     * @return array|null
     */
    public function check(array $reference): ?array {
        $doi = matcher::normalize_doi((string) ($reference['doi'] ?? ''));
        if ($doi !== '') {
            $record = $this->fetch(self::ENDPOINT . '/doi:' . rawurlencode($doi)
                . '?' . http_build_query($this->with_contact([]), '', '&', PHP_QUERY_RFC3986));
            if (is_array($record) && isset($record['id'])) {
                return $this->map_work($record);
            }
        }

        $title = trim((string) ($reference['title'] ?? ''));
        if (core_text::strlen($title) < self::MIN_TITLE_LENGTH) {
            return null;
        }

        $response = $this->fetch($this->url(self::ENDPOINT, [
            'filter' => 'title.search:' . $this->sanitise_title($title),
            'per_page' => self::CANDIDATES,
            'select' => 'id,title,display_name,authorships,publication_year,'
                . 'primary_location,doi,cited_by_count,is_retracted',
        ]));

        $results = $response['results'] ?? [];
        if (!is_array($results) || !$results) {
            return null;
        }

        return $this->best_candidate($results, $reference);
    }

    /**
     * Strip characters that OpenAlex's filter syntax treats as operators.
     *
     * Commas separate filters and pipes separate alternatives, so a title containing either would
     * otherwise be read as a malformed query rather than as text.
     *
     * @param string $title
     * @return string
     */
    protected function sanitise_title(string $title): string {
        $title = str_replace([',', '|', ':'], ' ', $title);
        $title = preg_replace('/\s+/u', ' ', $title);

        return core_text::substr(trim($title), 0, 350);
    }

    /**
     * Pick the candidate that best matches the reference.
     *
     * @param array $results
     * @param array $reference
     * @return array|null
     */
    protected function best_candidate(array $results, array $reference): ?array {
        $expected = (string) ($reference['title'] ?? $reference['raw'] ?? '');

        $best = null;
        $bestscore = -1;

        foreach ($results as $work) {
            $mapped = $this->map_work($work);
            if ($mapped === null) {
                continue;
            }

            $score = matcher::title_similarity($expected, $mapped['title']);

            $expectedyear = (int) ($reference['year'] ?? 0);
            if ($expectedyear && $mapped['year']) {
                $difference = abs((int) $mapped['year'] - $expectedyear);
                if ($difference === 0) {
                    $score += 20;
                } else if ($difference === 1) {
                    // Preprint then publication a year later is the norm, not a discrepancy.
                    $score += 5;
                }
            }

            if ($score > $bestscore) {
                $bestscore = $score;
                $best = $mapped;
            }
        }

        return $best;
    }

    /**
     * Convert an OpenAlex work into the shape the matcher expects.
     *
     * @param mixed $work
     * @return array|null
     */
    protected function map_work($work): ?array {
        if (!is_array($work)) {
            return null;
        }

        $title = trim((string) ($work['title'] ?? $work['display_name'] ?? ''));
        if ($title === '') {
            return null;
        }

        $authors = [];
        foreach ((array) ($work['authorships'] ?? []) as $authorship) {
            $name = trim((string) ($authorship['author']['display_name'] ?? ''));
            if ($name !== '') {
                $authors[] = $name;
            }
        }

        $doi = matcher::normalize_doi((string) ($work['doi'] ?? ''));

        return [
            'title' => $title,
            'authors' => $authors,
            'journal' => trim((string) ($work['primary_location']['source']['display_name'] ?? '')),
            'year' => isset($work['publication_year']) ? (int) $work['publication_year'] : null,
            'doi' => $doi,
            'url' => $doi !== '' ? 'https://doi.org/' . $doi : (string) ($work['id'] ?? ''),
            'citations' => isset($work['cited_by_count']) ? (int) $work['cited_by_count'] : null,
            'retracted' => !empty($work['is_retracted']),
        ];
    }

    /**
     * This service operates a polite pool keyed on a contact address.
     *
     * @return bool
     */
    protected function uses_polite_pool(): bool {
        return true;
    }

    /**
     * A cheap request that proves the service is answering.
     *
     * @return string
     */
    protected function availability_url(): string {
        return $this->url(self::ENDPOINT, ['per_page' => 1, 'select' => 'id']);
    }

    /**
     * @inheritDoc
     */
    public function get_brand_color(): string {
        return '#1A1A1A';
    }
}

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
 * Looks references up in Semantic Scholar.
 *
 * Disabled by default, and deliberately so. Without an API key the service puts every anonymous
 * caller in one shared pool: two requests a few seconds apart are enough to be refused, so at
 * cohort scale it would spend most of its time rate limited and risks having the institution's
 * address throttled. Configure a key, then enable it.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class semanticscholar extends http_source {
    /** @var string The paper search endpoint. */
    protected const ENDPOINT = 'https://api.semanticscholar.org/graph/v1/paper/search';

    /** @var string The single paper endpoint, used for DOI lookups. */
    protected const PAPER_ENDPOINT = 'https://api.semanticscholar.org/graph/v1/paper';

    /**
     * The fields to request.
     *
     * Verified against the live API. Note the absence of isRetracted: the upstream project asks
     * for it, but the field no longer exists and requesting it fails the whole call with
     * "Unrecognized or unsupported fields".
     *
     * @var string
     */
    protected const FIELDS = 'paperId,title,authors,year,venue,externalIds,url,citationCount,'
        . 'publicationTypes,journal';

    /** @var int A title shorter than this is too generic to search on. */
    protected const MIN_TITLE_LENGTH = 10;

    /**
     * The identifier stored against a result.
     *
     * @return string
     */
    public function get_name(): string {
        return 'semanticscholar';
    }

    /**
     * The name shown in the report.
     *
     * @return string
     */
    public function get_display_name(): string {
        return 'Semantic Scholar';
    }

    /**
     * The configured API key, if any.
     *
     * @return string
     */
    public function get_api_key(): string {
        return trim((string) get_config('assignsubmission_refchecker', 'semanticscholarkey'));
    }

    /**
     * Send the API key when one is configured.
     *
     * @return array<string, string>
     */
    protected function extra_headers(): array {
        $key = $this->get_api_key();

        return $key === '' ? [] : ['x-api-key' => $key];
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
            $record = $this->fetch(
                self::PAPER_ENDPOINT . '/DOI:' . rawurlencode($doi)
                    . '?' . http_build_query(['fields' => self::FIELDS], '', '&', PHP_QUERY_RFC3986)
            );
            if (is_array($record) && isset($record['paperId'])) {
                return $this->map_paper($record);
            }
        }

        $title = trim((string) ($reference['title'] ?? ''));
        if (core_text::strlen($title) < self::MIN_TITLE_LENGTH) {
            return null;
        }

        $response = $this->fetch($this->url(self::ENDPOINT, [
            'query' => core_text::substr($title, 0, 300),
            'limit' => self::CANDIDATES,
            'fields' => self::FIELDS,
        ]));

        $papers = $response['data'] ?? [];
        if (!is_array($papers) || !$papers) {
            return null;
        }

        return $this->best_candidate($papers, $reference);
    }

    /**
     * Pick the candidate that best matches the reference.
     *
     * @param array $papers
     * @param array $reference
     * @return array|null
     */
    protected function best_candidate(array $papers, array $reference): ?array {
        $expected = (string) ($reference['title'] ?? $reference['raw'] ?? '');

        $best = null;
        $bestscore = -1;

        foreach ($papers as $paper) {
            $mapped = $this->map_paper($paper);
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
     * Convert a Semantic Scholar paper into the shape the matcher expects.
     *
     * @param mixed $paper
     * @return array|null
     */
    protected function map_paper($paper): ?array {
        if (!is_array($paper)) {
            return null;
        }

        $title = trim((string) ($paper['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $authors = [];
        foreach ((array) ($paper['authors'] ?? []) as $author) {
            $name = trim((string) ($author['name'] ?? ''));
            if ($name !== '') {
                $authors[] = $name;
            }
        }

        $doi = matcher::normalize_doi((string) ($paper['externalIds']['DOI'] ?? ''));
        $venue = trim((string) ($paper['venue'] ?? ''));
        if ($venue === '') {
            $venue = trim((string) ($paper['journal']['name'] ?? ''));
        }

        return [
            'title' => $title,
            'authors' => $authors,
            'journal' => $venue,
            'year' => isset($paper['year']) ? (int) $paper['year'] : null,
            'doi' => $doi,
            'url' => $doi !== '' ? 'https://doi.org/' . $doi : trim((string) ($paper['url'] ?? '')),
            'citations' => isset($paper['citationCount']) ? (int) $paper['citationCount'] : null,
            'retracted' => $this->is_retracted($paper, $title),
        ];
    }

    /**
     * Whether this paper looks retracted.
     *
     * There is no dedicated flag, so this rests on the publication types and the title convention.
     * A negative result means nothing was noticed, not that the work is sound.
     *
     * @param array $paper
     * @param string $title
     * @return bool
     */
    protected function is_retracted(array $paper, string $title): bool {
        foreach ((array) ($paper['publicationTypes'] ?? []) as $type) {
            if (str_contains(core_text::strtolower((string) $type), 'retract')) {
                return true;
            }
        }

        return $this->looks_retracted($title);
    }

    /**
     * Whether this source is usable.
     *
     * Reported unavailable without a key rather than left to fail request by request, so the
     * reason shows up in the site status report instead of as a stream of rate limit errors.
     *
     * @return bool
     */
    public function is_available(): bool {
        if ($this->get_api_key() === '') {
            return false;
        }

        return parent::is_available();
    }

    /**
     * A cheap request that proves the service is answering.
     *
     * @return string
     */
    protected function availability_url(): string {
        return $this->url(self::ENDPOINT, ['query' => 'test', 'limit' => 1, 'fields' => 'paperId']);
    }

    /**
     * The brand color for the source.
     *
     * @return string
     */
    public function get_brand_color(): string {
        return '#1857B6';
    }
}

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
 * Looks references up in DBLP.
 *
 * DBLP indexes computer science conference proceedings thoroughly, which is exactly the material
 * CrossRef and OpenAlex cover least well. In that discipline a reference list can be mostly
 * proceedings papers, so without it a great many correctly cited works come back "not found".
 *
 * Two quirks are worth knowing. DBLP signals overload with HTTP 500 rather than 429, so those are
 * treated as transient and retried. And it appends disambiguation digits to author names, as in
 * "Xiaopeng Zhang 0009"; no special handling is needed because the matcher already discards
 * numeric-only name parts.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dblp extends http_source {
    /** @var string The publication search endpoint. */
    protected const ENDPOINT = 'https://dblp.org/search/publ/api';

    /** @var int A title shorter than this is too generic to search on. */
    protected const MIN_TITLE_LENGTH = 10;

    /**
     * The identifier stored against a result.
     *
     * @return string
     */
    public function get_name(): string {
        return 'dblp';
    }

    /**
     * The name shown in the report.
     *
     * @return string
     */
    public function get_display_name(): string {
        return 'DBLP';
    }

    /**
     * Look a reference up.
     *
     * @param array $reference
     * @return array|null
     */
    public function check(array $reference): ?array {
        $title = trim((string) ($reference['title'] ?? ''));
        if (core_text::strlen($title) < self::MIN_TITLE_LENGTH) {
            return null;
        }

        $response = $this->fetch($this->url(self::ENDPOINT, [
            'q' => $this->sanitise_title($title),
            'format' => 'json',
            'h' => self::CANDIDATES,
        ]));

        $hits = $response['result']['hits']['hit'] ?? [];
        if (!is_array($hits) || !$hits) {
            return null;
        }

        return $this->best_candidate($hits, $reference);
    }

    /**
     * Trim the query to something DBLP's search will handle.
     *
     * @param string $title
     * @return string
     */
    protected function sanitise_title(string $title): string {
        $title = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $title);
        $title = preg_replace('/\s+/u', ' ', $title);

        return core_text::substr(trim($title), 0, 250);
    }

    /**
     * Pick the candidate that best matches the reference.
     *
     * @param array $hits
     * @param array $reference
     * @return array|null
     */
    protected function best_candidate(array $hits, array $reference): ?array {
        $expected = (string) ($reference['title'] ?? $reference['raw'] ?? '');

        $best = null;
        $bestscore = -1;

        foreach ($hits as $hit) {
            $mapped = $this->map_hit($hit['info'] ?? null);
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
                    // DBLP lists the preprint and the proceedings version separately.
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
     * Convert a DBLP record into the shape the matcher expects.
     *
     * @param mixed $info
     * @return array|null
     */
    protected function map_hit($info): ?array {
        if (!is_array($info)) {
            return null;
        }

        $title = trim(rtrim(trim((string) ($info['title'] ?? '')), '.'));
        if ($title === '') {
            return null;
        }

        $doi = matcher::normalize_doi((string) ($info['doi'] ?? ''));
        if ($doi === '') {
            $doi = $this->doi_from_ee($info['ee'] ?? null);
        }

        return [
            'title' => $title,
            'authors' => $this->authors($info['authors']['author'] ?? null),
            'journal' => trim((string) ($info['venue'] ?? '')),
            'year' => isset($info['year']) ? (int) $info['year'] : null,
            'doi' => $doi,
            'url' => $doi !== '' ? 'https://doi.org/' . $doi : trim((string) ($info['url'] ?? '')),
            // DBLP publishes no citation counts.
            'citations' => null,
            'retracted' => $this->looks_retracted($title),
        ];
    }

    /**
     * Normalise DBLP's author list.
     *
     * An author may be a bare string or an object carrying the name under "text", and a single
     * author is not wrapped in an array at all. All three shapes occur in practice.
     *
     * @param mixed $authors
     * @return string[]
     */
    protected function authors($authors): array {
        if ($authors === null) {
            return [];
        }

        // One author comes back unwrapped.
        if (is_string($authors) || (is_array($authors) && isset($authors['text']))) {
            $authors = [$authors];
        }

        if (!is_array($authors)) {
            return [];
        }

        $names = [];
        foreach ($authors as $author) {
            $name = is_array($author) ? (string) ($author['text'] ?? '') : (string) $author;
            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Pull a DOI out of DBLP's electronic edition links.
     *
     * @param mixed $ee A single URL, or a list of them.
     * @return string
     */
    protected function doi_from_ee($ee): string {
        foreach ((array) $ee as $url) {
            if (!is_string($url)) {
                continue;
            }
            if (preg_match('#doi\.org/(10\.\d{4,9}/[^\s,;]+)#i', $url, $matches)) {
                return matcher::normalize_doi($matches[1]);
            }
        }

        return '';
    }

    /**
     * A cheap request that proves the service is answering.
     *
     * @return string
     */
    protected function availability_url(): string {
        return $this->url(self::ENDPOINT, ['q' => 'test', 'format' => 'json', 'h' => 1]);
    }
}

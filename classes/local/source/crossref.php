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
 * Looks references up in CrossRef.
 *
 * CrossRef is the first source tried because its coverage matches what students actually cite:
 * as well as journal articles it indexes books, chapters, conference proceedings, dissertations
 * and standards, which are exactly the material that otherwise comes back "not found" and erodes
 * trust in the whole report. It also carries Retraction Watch data, so retractions are detected
 * without a separate lookup.
 *
 * No API key is needed. Supplying a contact address moves requests into the "polite pool", which
 * is the difference between comfortable and marginal throughput at cohort scale.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class crossref extends http_source {
    /** @var string The works endpoint. */
    protected const ENDPOINT = 'https://api.crossref.org/works';

    /**
     * The identifier stored against a result.
     *
     * @return string
     */
    public function get_name(): string {
        return 'crossref';
    }

    /**
     * The name shown in the report.
     *
     * @return string
     */
    public function get_display_name(): string {
        return 'CrossRef';
    }

    /**
     * Look a reference up.
     *
     * @param array $reference
     * @return array|null
     */
    public function check(array $reference): ?array {
        // A DOI identifies the work outright, so resolve it rather than searching for it.
        $doi = matcher::normalize_doi((string) ($reference['doi'] ?? ''));
        if ($doi !== '') {
            $record = $this->fetch(self::ENDPOINT . '/' . rawurlencode($doi)
                . '?' . http_build_query($this->with_contact([]), '', '&', PHP_QUERY_RFC3986));
            if (is_array($record) && isset($record['message'])) {
                return $this->map_item($record['message']);
            }
            // A DOI that does not resolve is itself a finding, but the work may still exist under
            // a different identifier, so fall through to the search.
        }

        $query = $this->search_terms($reference);
        if ($query === '') {
            return null;
        }

        $response = $this->fetch($this->url(self::ENDPOINT, [
            'query.bibliographic' => $query,
            'rows' => self::CANDIDATES,
            'select' => 'DOI,title,author,container-title,issued,URL,is-referenced-by-count,type,update-to',
        ]));

        $items = $response['message']['items'] ?? [];
        if (!is_array($items) || !$items) {
            return null;
        }

        return $this->best_candidate($items, $reference);
    }

    /**
     * The text to search on.
     *
     * query.bibliographic is CrossRef's reference matching entry point and is designed to take a
     * whole citation string, so the raw reference is preferred over the parsed title.
     *
     * @param array $reference
     * @return string
     */
    protected function search_terms(array $reference): string {
        $raw = trim((string) ($reference['raw'] ?? ''));
        $title = trim((string) ($reference['title'] ?? ''));

        $query = $raw !== '' ? $raw : $title;

        // Very long strings dilute the query rather than sharpening it.
        return core_text::substr(trim(preg_replace('/\s+/u', ' ', $query)), 0, 500);
    }

    /**
     * Pick the candidate that best matches the reference.
     *
     * CrossRef orders by its own relevance score, which is not the same question as "is this the
     * work the student cited", so the candidates are re-ranked on title similarity here.
     *
     * @param array $items
     * @param array $reference
     * @return array|null
     */
    protected function best_candidate(array $items, array $reference): ?array {
        $expected = (string) ($reference['title'] ?? $reference['raw'] ?? '');

        $best = null;
        $bestscore = -1;

        foreach ($items as $item) {
            $mapped = $this->map_item($item);
            if ($mapped === null) {
                continue;
            }

            $score = matcher::title_similarity($expected, $mapped['title']);

            // A matching year breaks ties between reissues and versions of the same work.
            $expectedyear = (int) ($reference['year'] ?? 0);
            if ($expectedyear && (int) $mapped['year'] === $expectedyear) {
                $score += 5;
            }

            if ($score > $bestscore) {
                $bestscore = $score;
                $best = $mapped;
            }
        }

        return $best;
    }

    /**
     * Convert a CrossRef work into the shape the matcher expects.
     *
     * @param mixed $item
     * @return array|null
     */
    protected function map_item($item): ?array {
        if (!is_array($item)) {
            return null;
        }

        $title = $this->first_string($item['title'] ?? null);
        if ($title === '') {
            return null;
        }

        $authors = [];
        foreach ((array) ($item['author'] ?? []) as $author) {
            $name = trim(($author['given'] ?? '') . ' ' . ($author['family'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($author['name'] ?? ''));
            }
            if ($name !== '') {
                $authors[] = $name;
            }
        }

        $doi = (string) ($item['DOI'] ?? '');

        return [
            'title' => $title,
            'authors' => $authors,
            'journal' => $this->first_string($item['container-title'] ?? null),
            'year' => $this->year($item),
            'doi' => $doi,
            'url' => (string) ($item['URL'] ?? ($doi !== '' ? 'https://doi.org/' . $doi : '')),
            'citations' => isset($item['is-referenced-by-count'])
                ? (int) $item['is-referenced-by-count']
                : null,
            'retracted' => $this->is_retracted($item),
        ];
    }

    /**
     * Whether CrossRef reports this work as retracted or withdrawn.
     *
     * CrossRef publishes the Retraction Watch data through this endpoint, so the update-to
     * relationships are authoritative. The title check is a cheap backstop for publishers who
     * signal a retraction only by renaming the article.
     *
     * @param array $item
     * @return bool
     */
    protected function is_retracted(array $item): bool {
        foreach ((array) ($item['update-to'] ?? []) as $update) {
            $type = core_text::strtolower((string) ($update['type'] ?? ''));
            if (str_contains($type, 'retract') || str_contains($type, 'withdraw')) {
                return true;
            }
        }

        $type = core_text::strtolower((string) ($item['type'] ?? ''));
        if (str_contains($type, 'retract')) {
            return true;
        }

        $title = core_text::strtolower($this->first_string($item['title'] ?? null));

        return str_starts_with($title, 'retracted') || str_starts_with($title, 'withdrawn');
    }

    /**
     * The publication year.
     *
     * @param array $item
     * @return int|null
     */
    protected function year(array $item): ?int {
        foreach (['issued', 'published-print', 'published-online', 'created'] as $field) {
            $parts = $item[$field]['date-parts'][0] ?? null;
            if (is_array($parts) && isset($parts[0]) && (int) $parts[0] > 0) {
                return (int) $parts[0];
            }
        }

        return null;
    }

    /**
     * CrossRef returns most single valued fields as arrays.
     *
     * @param mixed $value
     * @return string
     */
    protected function first_string($value): string {
        if (is_array($value)) {
            $value = reset($value);
        }

        return trim((string) $value);
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
        return $this->url(self::ENDPOINT, ['rows' => 1, 'select' => 'DOI']);
    }
}

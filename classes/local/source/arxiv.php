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
use SimpleXMLElement;

/**
 * Looks references up in arXiv.
 *
 * Earns its place on preprints, which CrossRef and OpenAlex index inconsistently and which make up
 * a large share of the reference lists in computing, physics and increasingly the quantitative
 * social sciences.
 *
 * It also gets versions right where the others do not. A search for "Attention Is All You Need"
 * returns 2025 re-deposits from both CrossRef and OpenAlex, while arXiv returns the genuine 2017
 * paper the student actually cited.
 *
 * The upstream project routes arXiv through a public CORS proxy. That is a browser restriction and
 * does not apply here: called from the server the API answers directly.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class arxiv extends http_source {
    /** @var string The query endpoint. */
    protected const ENDPOINT = 'https://export.arxiv.org/api/query';

    /** @var string The Atom namespace. */
    protected const NS_ATOM = 'http://www.w3.org/2005/Atom';

    /** @var string arXiv's own extension namespace, carrying the DOI and journal reference. */
    protected const NS_ARXIV = 'http://arxiv.org/schemas/atom';

    /** @var int A title shorter than this is too generic to search on. */
    protected const MIN_TITLE_LENGTH = 10;

    /**
     * The identifier stored against a result.
     *
     * @return string
     */
    public function get_name(): string {
        return 'arxiv';
    }

    /**
     * The name shown in the report.
     *
     * @return string
     */
    public function get_display_name(): string {
        return 'arXiv';
    }

    /**
     * Look a reference up.
     *
     * @param array $reference
     * @return array|null
     */
    public function check(array $reference): ?array {
        // An identifier in the reference names the work outright, so look it up rather than
        // searching for it.
        $arxivid = (string) ($reference['arxivid'] ?? '');
        if ($arxivid !== '') {
            $feed = $this->fetch_xml($this->url(self::ENDPOINT, ['id_list' => $arxivid]));
            $entries = $this->entries($feed);
            if ($entries) {
                return $this->map_entry($entries[0]);
            }
        }

        $title = trim((string) ($reference['title'] ?? ''));
        if (core_text::strlen($title) < self::MIN_TITLE_LENGTH) {
            return null;
        }

        $feed = $this->fetch_xml($this->url(self::ENDPOINT, [
            'search_query' => 'ti:"' . $this->sanitise_title($title) . '"',
            'max_results' => self::CANDIDATES,
            'sortBy' => 'relevance',
            'sortOrder' => 'descending',
        ]));

        $entries = $this->entries($feed);
        if (!$entries) {
            return null;
        }

        return $this->best_candidate($entries, $reference);
    }

    /**
     * Strip characters that would break out of the quoted title term.
     *
     * @param string $title
     * @return string
     */
    protected function sanitise_title(string $title): string {
        $title = str_replace(['"', ':', '(', ')'], ' ', $title);
        $title = preg_replace('/\s+/u', ' ', $title);

        return core_text::substr(trim($title), 0, 300);
    }

    /**
     * The entry elements of an Atom feed.
     *
     * @param SimpleXMLElement|null $feed
     * @return SimpleXMLElement[]
     */
    protected function entries(?SimpleXMLElement $feed): array {
        if ($feed === null) {
            return [];
        }

        $atom = $feed->children(self::NS_ATOM);

        return $atom->entry === null ? [] : iterator_to_array($atom->entry, false);
    }

    /**
     * Pick the candidate that best matches the reference.
     *
     * @param SimpleXMLElement[] $entries
     * @param array $reference
     * @return array|null
     */
    protected function best_candidate(array $entries, array $reference): ?array {
        $expected = (string) ($reference['title'] ?? $reference['raw'] ?? '');

        $best = null;
        $bestscore = -1;

        foreach ($entries as $entry) {
            $mapped = $this->map_entry($entry);
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
                    // A preprint posted the year before formal publication is the norm here.
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
     * Convert an Atom entry into the shape the matcher expects.
     *
     * @param SimpleXMLElement $entry
     * @return array|null
     */
    protected function map_entry(SimpleXMLElement $entry): ?array {
        $atom = $entry->children(self::NS_ATOM);
        $extra = $entry->children(self::NS_ARXIV);

        $title = $this->tidy((string) $atom->title);
        if ($title === '') {
            return null;
        }

        $authors = [];
        foreach ($atom->author as $author) {
            $name = trim((string) $author->children(self::NS_ATOM)->name);
            if ($name !== '') {
                $authors[] = $name;
            }
        }

        $id = trim((string) $atom->id);
        $doi = matcher::normalize_doi((string) $extra->doi);

        // Where a preprint has been formally published, arXiv records the journal reference. That
        // is a better answer for the student than "arXiv" alone.
        $journal = $this->tidy((string) $extra->journal_ref);

        return [
            'title' => $title,
            'authors' => $authors,
            'journal' => $journal !== '' ? $journal : $this->get_display_name(),
            'year' => $this->year((string) $atom->published),
            'doi' => $doi,
            'url' => $doi !== '' ? 'https://doi.org/' . $doi : $id,
            // No citation counts are published by this service.
            'citations' => null,
            'retracted' => $this->looks_retracted($title)
                || $this->looks_retracted($this->tidy((string) $atom->summary)),
        ];
    }

    /**
     * Collapse the whitespace arXiv wraps its text fields in.
     *
     * @param string $value
     * @return string
     */
    protected function tidy(string $value): string {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * The year from an Atom timestamp.
     *
     * @param string $published
     * @return int|null
     */
    protected function year(string $published): ?int {
        if (preg_match('/^(\d{4})-/', trim($published), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * A cheap request that proves the service is answering.
     *
     * @return string
     */
    protected function availability_url(): string {
        return $this->url(self::ENDPOINT, ['id_list' => '1706.03762', 'max_results' => 1]);
    }

    /**
     * Whether the service is reachable.
     *
     * The base implementation treats a null result as unreachable, but a well formed empty feed is
     * a perfectly good answer, so availability is judged on getting any parseable feed back.
     *
     * @return bool
     */
    public function is_available(): bool {
        try {
            return $this->fetch_xml($this->availability_url()) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function get_brand_color(): string {
        return '#AA142D';
    }
}

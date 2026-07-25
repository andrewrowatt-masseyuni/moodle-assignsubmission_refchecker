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

use assignsubmission_refchecker\local\exception\permanent_exception;
use assignsubmission_refchecker\local\exception\rate_limited_exception;
use assignsubmission_refchecker\local\circuit_breaker;
use assignsubmission_refchecker\local\exception\transient_exception;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\matcher;
use assignsubmission_refchecker\local\predatory;

/**
 * Asks each configured database in turn and returns the most convincing answer.
 *
 * The chain stops early only on a match that is both high confidence and consistent with the year
 * the reference claimed. Anything less is remembered and the remaining sources are still asked, so
 * a specialist database late in the order can correct a plausible but wrong answer from a general
 * one. That costs an extra request only when the first hit is doubtful.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chain {
    /**
     * Confidence at or above which a year-consistent match ends the search.
     *
     * @var int
     */
    public const STOP_EARLY_CONFIDENCE = 90;

    /**
     * Every source this plugin knows how to search, in the order they are tried.
     *
     * The order is the registry's, not the settings multi-select's, so an administrator cannot
     * accidentally produce a nonsensical sequence. CrossRef first because its coverage best matches
     * what students cite; the two specialist databases after the two general ones.
     *
     * @var array<string, class-string<reference_source>>
     */
    protected const AVAILABLE = [
        'crossref' => crossref::class,
        'openalex' => openalex::class,
        'arxiv' => arxiv::class,
        'dblp' => dblp::class,
        'semanticscholar' => semanticscholar::class,
    ];

    /**
     * The sources enabled when the site has never configured them.
     *
     * Semantic Scholar is absent deliberately: without an API key it rate limits within seconds.
     *
     * @var string[]
     */
    public const DEFAULT_SOURCES = ['crossref', 'openalex', 'arxiv', 'dblp'];

    /**
     * The names of every source this plugin can search.
     *
     * @return string[]
     */
    public static function available_sources(): array {
        return array_keys(self::AVAILABLE);
    }

    /**
     * Every source's stored name mapped to the name people read.
     *
     * Derived from the source classes rather than repeated here, so each one stays the single
     * authority on what it is called. Settings menus and the report all read from this.
     *
     * @return array<string, string>
     */
    public static function display_names(): array {
        static $names = null;

        if ($names === null) {
            $names = [];
            foreach (self::AVAILABLE as $name => $class) {
                $names[$name] = (new $class())->get_display_name();
            }
        }

        return $names;
    }

    /**
     * The readable name for a stored source name.
     *
     * @param string|null $source As stored against a reference, e.g. "semanticscholar".
     * @return string The readable form, e.g. "Semantic Scholar". Empty when nothing was stored.
     */
    public static function display_name(?string $source): string {
        $source = trim((string) $source);
        if ($source === '') {
            return '';
        }

        // A source that has since been removed still has results stored against it, so fall back
        // to whatever was recorded rather than showing a blank.
        return self::display_names()[$source] ?? $source;
    }

    /**
     * Every source's stored brand color.
     *
     * Derived from the source classes rather than repeated here, so each one stays the single
     * authority on what it is called. Settings menus and the report all read from this.
     *
     * @return array<string, string>
     */
    public static function brand_colors(): array {
        static $colors = null;

        if ($colors === null) {
            $colors = [];
            foreach (self::AVAILABLE as $name => $class) {
                $colors[$name] = (new $class())->get_brand_color();
            }
        }

        return $colors;
    }

    /**
     * The brand color for a stored source name.
     *
     * @param string|null $source As stored against a reference, e.g. "semanticscholar".
     * @return string The readable form, e.g. "Semantic Scholar". Empty when nothing was stored.
     */
    public static function brand_color(?string $source): string {
        $source = trim((string) $source);
        if ($source === '') {
            return '';
        }

        // A source that has since been removed still has results stored against it, so fall back
        // to whatever was recorded rather than showing a blank.
        return self::brand_colors()[$source] ?? $source;
    }

    /**
     * Constructor.
     *
     * @param reference_source[] $sources In the order they should be tried.
     */
    public function __construct(
        /** @var reference_source[] The sources to try, in order. */
        protected array $sources,
    ) {
    }

    /**
     * Build the chain from the site configuration.
     *
     * @return self
     */
    public static function from_config(): self {
        $configured = (string) get_config('assignsubmission_refchecker', 'sources');
        $enabled = array_filter(array_map('trim', explode(',', $configured)));
        if (!$enabled) {
            $enabled = self::DEFAULT_SOURCES;
        }

        $sources = [];
        foreach (self::AVAILABLE as $name => $class) {
            if (in_array($name, $enabled, true)) {
                $sources[] = new $class();
            }
        }

        return new self($sources);
    }

    /**
     * The sources this chain will try.
     *
     * @return reference_source[]
     */
    public function get_sources(): array {
        return $this->sources;
    }

    /**
     * Look a reference up and score the result.
     *
     * @param array $reference Parsed reference: raw, title, authors, journal, year, doi.
     * @return array The outcome, always populated. Keys: matchstatus, confidence, titlescore,
     *     authorscore, journalscore, issues, source, record (or null).
     * @throws transient_exception When every source failed in a way worth retrying.
     * @throws rate_limited_exception When a source asked us to slow down.
     */
    public function check(array $reference): array {
        $bestresult = null;
        $transient = null;
        $answered = 0;
        $unavailable = [];

        foreach ($this->sources as $source) {
            // A source that has been failing repeatedly is stood down for a few minutes rather
            // than asked once per reference while it is unwell.
            if (circuit_breaker::is_open($source->get_name())) {
                $unavailable[] = $source->get_name();
                continue;
            }

            try {
                $record = $source->check($reference);
                $answered++;
                circuit_breaker::record_success($source->get_name());
            } catch (rate_limited_exception $e) {
                // Backpressure is the caller's decision to act on, not something to route around.
                throw $e;
            } catch (transient_exception $e) {
                // Remember it, but give the remaining sources a chance first.
                $transient = $e;
                $unavailable[] = $source->get_name();
                circuit_breaker::record_failure($source->get_name());
                continue;
            } catch (permanent_exception $e) {
                debugging(
                    'assignsubmission_refchecker: ' . $source->get_name() . ' failed: ' . $e->getMessage(),
                    DEBUG_DEVELOPER,
                );
                continue;
            }

            if ($record === null) {
                continue;
            }

            $result = $this->evaluate($reference, $record, $source);

            if ($this->is_strong_match($reference, $result)) {
                return $this->finalise($result, $unavailable);
            }

            if ($this->is_better($result, $bestresult)) {
                $bestresult = $result;
            }
        }

        // Give up only when nothing answered at all. One flaky database must not be able to fail
        // the whole submission: three services saying "not in my index" is a real answer, and a
        // fourth being briefly unreachable does not make it untrustworthy enough to abandon.
        //
        // Nothing answering is different. Reporting "not found" then would mean saying a work does
        // not exist on the strength of a search that never happened, so the caller is asked to try
        // again later instead.
        if ($answered === 0 && $unavailable !== []) {
            throw $transient ?? new transient_exception(
                'No bibliographic database was available: ' . implode(', ', $unavailable)
            );
        }

        return $this->finalise($bestresult ?? $this->not_found(), $unavailable);
    }

    /**
     * Record whether the search that produced a result was complete.
     *
     * Only a negative is ever treated as degraded. Finding the work is a true positive however many
     * other databases were unreachable at the time, so it is safe to keep and to cache. Failing to
     * find it while a database was down is a different claim altogether, and one that must not be
     * written to a cache the whole site reads: a single outage would otherwise teach every later
     * submission that a real work does not exist.
     *
     * @param array $result
     * @param string[] $unavailable Sources that could not be consulted.
     * @return array
     */
    protected function finalise(array $result, array $unavailable): array {
        $result['unavailable'] = $unavailable;
        $result['degraded'] = $unavailable !== []
            && $result['matchstatus'] === match_status::NOTFOUND;

        return $result;
    }

    /**
     * Whether a result is good enough to stop looking.
     *
     * Confidence alone is not enough. Widely cited papers get re-deposited under fresh identifiers,
     * so a search can return a convincing match to a copy published years after the one the student
     * read: CrossRef and OpenAlex both answer "Attention Is All You Need" with 2025 re-deposits,
     * while arXiv still has the 2017 original. Requiring the year to agree before stopping lets a
     * later source correct that, at the cost of one extra request only when the first hit is
     * doubtful.
     *
     * @param array $reference
     * @param array $result
     * @return bool
     */
    protected function is_strong_match(array $reference, array $result): bool {
        return $result['matchstatus'] === match_status::VERIFIED
            && $result['confidence'] >= self::STOP_EARLY_CONFIDENCE
            && $this->year_agrees($reference, $result);
    }

    /**
     * Whether a result's year is consistent with what the reference claimed.
     *
     * True when the reference states no year, since there is then nothing to disagree with. A
     * single year of drift is treated as agreement: online-first and preprint-then-proceedings
     * publication routinely straddle a year boundary.
     *
     * @param array $reference
     * @param array $result
     * @return bool
     */
    protected function year_agrees(array $reference, array $result): bool {
        $cited = (int) ($reference['year'] ?? 0);
        $found = (int) ($result['record']['year'] ?? 0);

        if ($cited === 0 || $found === 0) {
            return true;
        }

        return abs($cited - $found) <= 1;
    }

    /**
     * Whether a new result should displace the best one seen so far.
     *
     * Ranked on how well the work was identified first, then on year agreement, and only then on
     * confidence. Year agreement outranks confidence deliberately: it is what distinguishes the
     * edition the student actually cited from a later copy of the same work.
     *
     * @param array $candidate
     * @param array|null $incumbent
     * @return bool
     */
    protected function is_better(array $candidate, ?array $incumbent): bool {
        if ($incumbent === null) {
            return true;
        }

        foreach (
            [
            [$this->rank($candidate), $this->rank($incumbent)],
            [(int) $candidate['yearagrees'], (int) $incumbent['yearagrees']],
            [(int) $candidate['confidence'], (int) $incumbent['confidence']],
            [(int) $candidate['titlescore'], (int) $incumbent['titlescore']],
            ] as [$new, $old]
        ) {
            if ($new !== $old) {
                return $new > $old;
            }
        }

        return false;
    }

    /**
     * How well a match status identifies the work, highest first.
     *
     * @param array $result
     * @return int
     */
    protected function rank(array $result): int {
        return match ($result['matchstatus']) {
            match_status::VERIFIED => 3,
            match_status::PARTIAL => 2,
            match_status::MISMATCH => 1,
            default => 0,
        };
    }

    /**
     * Score a candidate record and decide what to call it.
     *
     * @param array $reference
     * @param array $record
     * @param reference_source $source
     * @return array
     */
    protected function evaluate(array $reference, array $record, reference_source $source): array {
        $scores = matcher::score($reference, $record);

        // A convincing title is the precondition for reporting anything at all. Without it we may
        // have found a real work, but not the one that was cited.
        if ($scores['titlescore'] < matcher::MIN_TITLE_SIMILARITY) {
            return array_merge($this->not_found(), [
                'titlescore' => $scores['titlescore'],
            ]);
        }

        $record['predatory'] = predatory::is_predatory($record['journal'] ?? '');

        $result = [
            'matchstatus' => match_status::from_confidence(true, $scores['confidence']),
            'confidence' => $scores['confidence'],
            'titlescore' => $scores['titlescore'],
            'authorscore' => $scores['authorscore'],
            'journalscore' => $scores['journalscore'],
            'issues' => $scores['issues'],
            'source' => $source->get_name(),
            'record' => $record,
            'yearagrees' => true,
        ];

        $result['yearagrees'] = $this->year_agrees($reference, $result);

        return $result;
    }

    /**
     * The outcome when nothing convincing was found.
     *
     * @return array
     */
    protected function not_found(): array {
        return [
            'matchstatus' => match_status::NOTFOUND,
            'confidence' => 0,
            'titlescore' => 0,
            'authorscore' => null,
            'journalscore' => null,
            'issues' => [],
            'source' => null,
            'record' => null,
            'yearagrees' => false,
            'degraded' => false,
            'unavailable' => [],
        ];
    }
}

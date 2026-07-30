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
use assignsubmission_refchecker\local\exception\service_refused_exception;
use assignsubmission_refchecker\local\circuit_breaker;
use assignsubmission_refchecker\local\debug_log;
use assignsubmission_refchecker\local\exception\transient_exception;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\matcher;
use assignsubmission_refchecker\local\predatory;
use assignsubmission_refchecker\local\reference_parser;

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
     * The readable names for a stored list of source names.
     *
     * The two source list columns on a reference hold machine names separated by commas, which is
     * only ever read back to be worded for somebody.
     *
     * @param string|null $stored As stored, e.g. "crossref,openalex".
     * @return string[] The readable forms, in the order they were recorded.
     */
    public static function display_name_list(?string $stored): array {
        $names = [];

        foreach (array_filter(array_map('trim', explode(',', (string) $stored))) as $name) {
            $names[] = self::display_name($name);
        }

        return $names;
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
     *     authorscore, journalscore, issues, source, record (or null), consulted, unavailable.
     * @throws service_refused_exception When nothing answered and the sources that did not were
     *      refusing or standing down, rather than unreachable.
     * @throws transient_exception When every source failed in a way worth retrying.
     * @throws rate_limited_exception When our own pacer declined to send a request at all.
     */
    public function check(array $reference): array {
        $bestresult = null;
        $transient = null;
        $consulted = [];
        $unavailable = [];

        foreach ($this->sources as $source) {
            // A source that has been failing repeatedly is stood down for a few minutes rather
            // than asked once per reference while it is unwell.
            if (circuit_breaker::is_open($source->get_name())) {
                $remaining = circuit_breaker::remaining($source->get_name());
                debug_log::log('source.skipped', [
                    'source' => $source->get_name(),
                    'reason' => 'standdown',
                    'remaining' => $remaining,
                ]);
                $unavailable[] = $source->get_name();
                // A source we chose not to ask is not evidence that the databases are unreachable:
                // it declined to serve us earlier and is sitting out the stand-down we gave it. Say
                // so, so that a chain left with nothing but stood down sources settles the
                // reference against its attempt budget instead of backing the whole task off and
                // retrying into the same stand-down. Never in place of a live failure from this
                // run, which is the better account of why nothing answered.
                $transient ??= new service_refused_exception(
                    $source->get_name() . ' is standing down for a further ' . $remaining . 's',
                    $remaining,
                );
                continue;
            }

            try {
                $record = $source->check($reference);
                // Named, not just counted. "Nothing was found in CrossRef and OpenAlex" is a
                // different statement from "nothing was found", and the reader cannot tell them
                // apart afterwards unless the search says which databases actually answered.
                $consulted[] = $source->get_name();
                circuit_breaker::record_success($source->get_name());
            } catch (rate_limited_exception $e) {
                // Our own pacer, not the service. Nothing was sent and nothing here can change
                // that, so it is the caller's decision to act on rather than something to route
                // around. Must precede the transient catch, which is its parent.
                throw $e;
            } catch (service_refused_exception $e) {
                // The service turned us away. Stand it down for at least as long as it asked for,
                // then carry on down the chain: one database refusing is not a reason to stop
                // asking the others, and the answer they give is still a real answer. Must precede
                // the transient catch, which is its parent.
                debug_log::log('source.failed', [
                    'source' => $source->get_name(),
                    'kind' => 'refused',
                    'error' => $e->getMessage(),
                ]);
                $transient = $e;
                $unavailable[] = $source->get_name();
                circuit_breaker::record_failure($source->get_name(), $e->get_retry_after());
                continue;
            } catch (transient_exception $e) {
                // Remember it, but give the remaining sources a chance first.
                debug_log::log('source.failed', [
                    'source' => $source->get_name(),
                    'kind' => 'transient',
                    'error' => $e->getMessage(),
                ]);
                $transient = $e;
                $unavailable[] = $source->get_name();
                circuit_breaker::record_failure($source->get_name());
                continue;
            } catch (permanent_exception $e) {
                debug_log::log('source.failed', [
                    'source' => $source->get_name(),
                    'kind' => 'permanent',
                    'error' => $e->getMessage(),
                ]);
                debugging(
                    'assignsubmission_refchecker: ' . $source->get_name() . ' failed: ' . $e->getMessage(),
                    DEBUG_DEVELOPER,
                );
                continue;
            }

            if ($record === null) {
                debug_log::log('source.nomatch', ['source' => $source->get_name()]);
                continue;
            }

            $result = self::result_from_record($reference, $record, $source->get_name());

            debug_log::log('source.scored', [
                'source' => $source->get_name(),
                'matchstatus' => $result['matchstatus'] ?? '',
                'confidence' => $result['confidence'] ?? '',
                'title' => $result['titlescore'] ?? '',
                'author' => $result['authorscore'] ?? '',
                'journal' => $result['journalscore'] ?? '',
                'found' => $record['title'] ?? '',
            ]);

            if ($this->is_strong_match($reference, $result)) {
                // The chain stops here, so say so: otherwise the log looks as though the remaining
                // databases were simply never configured.
                debug_log::log('source.accepted', [
                    'source' => $source->get_name(),
                    'confidence' => $result['confidence'] ?? '',
                ]);
                return $this->finalise($reference, $result, $consulted, $unavailable);
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
        if ($consulted === [] && $unavailable !== []) {
            throw $transient ?? new transient_exception(
                'No bibliographic database was available: ' . implode(', ', $unavailable)
            );
        }

        return $this->finalise($reference, $bestresult ?? self::not_found(), $consulted, $unavailable);
    }

    /**
     * Record what the search covered, and what can be said without a candidate record.
     *
     * Only a negative is ever treated as degraded. Finding the work is a true positive however many
     * other databases were unreachable at the time, so it is safe to keep and to cache. Failing to
     * find it while a database was down is a different claim altogether, and one that must not be
     * written to a cache the whole site reads: a single outage would otherwise teach every later
     * submission that a real work does not exist.
     *
     * The reference-only issues are merged here rather than alongside the scoring because they are
     * the ones that still hold when nothing was found, which is the case they exist for.
     *
     * @param array $reference Parsed reference.
     * @param array $result
     * @param string[] $consulted Sources that answered.
     * @param string[] $unavailable Sources that could not be consulted.
     * @return array
     */
    protected function finalise(array $reference, array $result, array $consulted, array $unavailable): array {
        $result['consulted'] = $consulted;
        $result['unavailable'] = $unavailable;
        $result['degraded'] = $unavailable !== []
            && $result['matchstatus'] === match_status::NOTFOUND;
        $result['issues'] = array_merge(
            (array) ($result['issues'] ?? []),
            matcher::reference_issues($reference),
        );

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
            && self::year_agrees($reference, $result);
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
    protected static function year_agrees(array $reference, array $result): bool {
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
     * Static, and separate from the loop above, because the same judgement has to be made in two
     * places: once when a database answers, and again when a reference is served from the shared
     * cache. Sharing it is what stops the two drifting on where the thresholds sit.
     *
     * @param array $reference Parsed reference.
     * @param array $record Candidate record from a database.
     * @param string|null $sourcename Which database it came from.
     * @return array
     */
    public static function result_from_record(array $reference, array $record, ?string $sourcename): array {
        $scores = matcher::score($reference, $record);

        // A convincing title is the precondition for reporting anything at all. Without it we may
        // have found a real work, but not the one that was cited.
        if ($scores['titlescore'] < matcher::MIN_TITLE_SIMILARITY) {
            return array_merge(self::not_found(), [
                'titlescore' => $scores['titlescore'],
            ]);
        }

        $record['predatory'] = predatory::is_predatory($record['journal'] ?? '');

        $confidence = (int) $scores['confidence'];

        // A name that is on no part of the work found means the citation as written does not describe
        // that work, so it cannot be called verified however exactly the title and venue line up.
        // The deduction inside the author score is not enough on its own: at a weight of 0.3 it moves
        // confidence by six points, and the report would show "Verified 94%" immediately above a list
        // of people who are not on the paper. References-Validation reports that case as verified;
        // this plugin does not, on the same grounds as the title floor above.
        if ($scores['extraauthors']) {
            $confidence = min($confidence, match_status::THRESHOLD_VERIFIED - 1);
        }

        $result = [
            'matchstatus' => match_status::from_confidence(true, $confidence),
            'confidence' => $confidence,
            'titlescore' => $scores['titlescore'],
            'authorscore' => $scores['authorscore'],
            'journalscore' => $scores['journalscore'],
            'issues' => $scores['issues'],
            'source' => $sourcename,
            'record' => $record,
            'yearagrees' => true,
        ];

        $result['yearagrees'] = self::year_agrees($reference, $result);

        return $result;
    }

    /**
     * Re-derive the verdict for a reference answered from the shared cache.
     *
     * The cache exists to save the request, not the arithmetic. Scoring again costs nothing and is
     * the only way the reader can be told anything that depends on what *this* student wrote: the
     * names in a citation are their text, so they are stripped on the way into a table the whole site
     * reads, and have to be worked out again on the way out.
     *
     * It is also the more truthful answer. The cache key normalises punctuation away, so two
     * references can share it while parsing differently — "Smith, J. (2020). Title." and
     * "Smith J 2020 Title" are the same key — and replaying the first student's verdict would answer
     * a question the second one did not ask.
     *
     * @param string $rawref The reference exactly as the student wrote it.
     * @param array $cached The stored payload.
     * @return array A result in the same shape as check().
     */
    public static function rescore_cached(string $rawref, array $cached): array {
        $reference = reference_parser::parse_metadata($rawref);
        $record = $cached['record'] ?? null;

        $result = is_array($record) && $record !== []
            ? self::result_from_record($reference, $record, $cached['source'] ?? null)
            : self::not_found();

        // What the search covered is a property of the search, not of the student, so it is kept.
        $result['consulted'] = (array) ($cached['consulted'] ?? []);
        $result['unavailable'] = (array) ($cached['unavailable'] ?? []);
        // A degraded result is never cached, so anything read back from it was a complete search.
        $result['degraded'] = false;
        $result['issues'] = array_merge($result['issues'], matcher::reference_issues($reference));

        return $result;
    }

    /**
     * The outcome when nothing convincing was found.
     *
     * @return array
     */
    protected static function not_found(): array {
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
            'consulted' => [],
            'unavailable' => [],
        ];
    }
}

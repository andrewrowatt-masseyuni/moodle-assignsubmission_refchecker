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
use assignsubmission_refchecker\local\exception\transient_exception;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\matcher;
use assignsubmission_refchecker\local\predatory;

/**
 * Asks each configured database in turn and returns the first convincing answer.
 *
 * The chain stops as soon as a source returns a match at or above the title threshold. A weaker
 * match is remembered but not accepted straight away, so that a later source still gets the chance
 * to do better before anything is reported to the student.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chain {
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
            $enabled = ['crossref', 'openalex'];
        }

        // CrossRef first regardless of how the multi-select stored the values: it is the source
        // whose coverage best matches what students cite.
        $available = ['crossref' => crossref::class, 'openalex' => openalex::class];

        $sources = [];
        foreach ($available as $name => $class) {
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

        foreach ($this->sources as $source) {
            try {
                $record = $source->check($reference);
            } catch (rate_limited_exception $e) {
                // Backpressure is the caller's decision to act on, not something to route around.
                throw $e;
            } catch (transient_exception $e) {
                // Remember it, but give the remaining sources a chance first.
                $transient = $e;
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

            if ($result['matchstatus'] !== match_status::NOTFOUND) {
                return $result;
            }

            // Below the title threshold. Hold on to the best near miss in case nothing better
            // turns up, but do not report it as a match.
            if ($bestresult === null || $result['titlescore'] > $bestresult['titlescore']) {
                $bestresult = $result;
            }
        }

        // Nothing matched, and at least one source never answered: the answer is not trustworthy,
        // so ask to be retried rather than telling the student their reference does not exist.
        if ($transient !== null && $bestresult === null) {
            throw $transient;
        }

        return $bestresult ?? $this->not_found();
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

        return [
            'matchstatus' => match_status::from_confidence(true, $scores['confidence']),
            'confidence' => $scores['confidence'],
            'titlescore' => $scores['titlescore'],
            'authorscore' => $scores['authorscore'],
            'journalscore' => $scores['journalscore'],
            'issues' => $scores['issues'],
            'source' => $source->get_name(),
            'record' => $record,
        ];
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
        ];
    }
}

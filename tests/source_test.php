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

use assignsubmission_refchecker\local\exception\permanent_exception;
use assignsubmission_refchecker\local\exception\rate_limited_exception;
use assignsubmission_refchecker\local\exception\service_refused_exception;
use assignsubmission_refchecker\local\exception\transient_exception;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\source\chain;
use assignsubmission_refchecker\local\circuit_breaker;
use assignsubmission_refchecker\local\rate_limiter;
use assignsubmission_refchecker\local\source\arxiv;
use assignsubmission_refchecker\local\source\crossref;
use assignsubmission_refchecker\local\source\dblp;
use assignsubmission_refchecker\local\source\semanticscholar;
use assignsubmission_refchecker\local\source\openalex;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for the bibliographic database clients.
 *
 * Every request is served from a mocked HTTP client, so nothing here touches the network.
 *
 * @package    assignsubmission_refchecker
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_refchecker\local\source\http_source
 * @covers     \assignsubmission_refchecker\local\source\crossref
 * @covers     \assignsubmission_refchecker\local\source\openalex
 * @covers     \assignsubmission_refchecker\local\source\chain
 */
final class source_test extends \advanced_testcase {
    /** @var array The request and response history of the mocked client. */
    private array $history = [];

    /**
     * Switch request pacing off.
     *
     * The throttle is exercised by its own tests. Left on, arXiv's three second gap would make the
     * second request in any test reschedule instead of running.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        rate_limiter::reset();

        foreach (chain::available_sources() as $source) {
            set_config('rateinterval_' . $source, 0, 'assignsubmission_refchecker');
        }
    }

    /**
     * Install a mocked HTTP client and queue the given responses.
     *
     * @param Response[] $responses Served in order.
     */
    private function mock_responses(array $responses): void {
        $this->history = [];
        $mocked = $this->get_mocked_http_client($this->history);
        foreach ($responses as $response) {
            $mocked['mock']->append($response);
        }
    }

    /**
     * The URI of the nth request the code under test made.
     *
     * @param int $index
     * @return string
     */
    private function requested_uri(int $index = 0): string {
        return (string) $this->history[$index]['request']->getUri();
    }

    /**
     * A CrossRef search response containing one convincing work.
     *
     * @return string
     */
    private static function crossref_body(): string {
        return json_encode(['message' => ['items' => [[
            'DOI' => '10.5555/attention',
            'title' => ['Attention Is All You Need'],
            'author' => [
                ['given' => 'Ashish', 'family' => 'Vaswani'],
                ['given' => 'Noam', 'family' => 'Shazeer'],
            ],
            'container-title' => ['Advances in Neural Information Processing Systems'],
            'issued' => ['date-parts' => [[2017, 6]]],
            'URL' => 'https://doi.org/10.5555/attention',
            'is-referenced-by-count' => 98765,
            'type' => 'journal-article',
        ]]]]);
    }

    /**
     * An OpenAlex search response containing the same work.
     *
     * @param bool $retracted
     * @return string
     */
    private static function openalex_body(bool $retracted = false): string {
        return json_encode(['results' => [[
            'id' => 'https://openalex.org/W1',
            'title' => 'Attention Is All You Need',
            'authorships' => [['author' => ['display_name' => 'Ashish Vaswani']]],
            'publication_year' => 2017,
            'primary_location' => ['source' => ['display_name' => 'NeurIPS']],
            'doi' => 'https://doi.org/10.5555/attention',
            'cited_by_count' => 42,
            'is_retracted' => $retracted,
        ]]]);
    }

    /**
     * An arXiv Atom feed containing the genuine 2017 paper.
     *
     * @return string
     */
    private static function arxiv_body(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<feed xmlns="http://www.w3.org/2005/Atom" '
            . 'xmlns:arxiv="http://arxiv.org/schemas/atom">'
            . '<entry>'
            . '<id>http://arxiv.org/abs/1706.03762v7</id>'
            . '<published>2017-06-12T17:57:34Z</published>'
            . '<title>Attention Is All You Need</title>'
            . '<summary>The dominant sequence transduction models...</summary>'
            . '<author><name>Ashish Vaswani</name></author>'
            . '<author><name>Noam Shazeer</name></author>'
            . '</entry></feed>';
    }

    /**
     * A DBLP search response.
     *
     * @return string
     */
    private static function dblp_body(): string {
        return json_encode(['result' => ['hits' => ['hit' => [
            ['info' => [
                'title' => 'Attention is all you need',
                'year' => '2017',
                'venue' => 'NIPS',
                'doi' => '10.5555/dblp',
                'authors' => ['author' => [
                    ['@pid' => '1', 'text' => 'Ashish Vaswani'],
                    ['@pid' => '2', 'text' => 'Noam Shazeer'],
                ]],
            ]],
        ]]]]);
    }

    /**
     * A Semantic Scholar search response.
     *
     * @return string
     */
    private static function semanticscholar_body(): string {
        return json_encode(['data' => [[
            'paperId' => 'abc',
            'title' => 'Attention Is All You Need',
            'authors' => [['name' => 'Ashish Vaswani']],
            'year' => 2017,
            'venue' => 'NeurIPS',
            'externalIds' => ['DOI' => '10.5555/s2'],
            'citationCount' => 123,
            'publicationTypes' => ['JournalArticle'],
        ]]]);
    }

    /**
     * The reference those responses are meant to match.
     *
     * @param array $overrides
     * @return array
     */
    private static function reference(array $overrides = []): array {
        return array_merge([
            'raw' => 'Vaswani, A., & Shazeer, N. (2017). Attention is all you need. '
                . 'Advances in Neural Information Processing Systems.',
            'title' => 'Attention is all you need',
            'authors' => 'Vaswani, A., & Shazeer, N.',
            'journal' => 'Advances in Neural Information Processing Systems',
            'year' => '2017',
            'doi' => null,
        ], $overrides);
    }

    /**
     * A CrossRef search maps into the shape the matcher expects.
     */
    public function test_crossref_maps_a_search_result(): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response(200, [], self::crossref_body())]);

        $record = (new crossref())->check(self::reference());

        $this->assertSame('Attention Is All You Need', $record['title']);
        $this->assertSame(['Ashish Vaswani', 'Noam Shazeer'], $record['authors']);
        $this->assertSame(2017, $record['year']);
        $this->assertSame('10.5555/attention', $record['doi']);
        $this->assertSame(98765, $record['citations']);
        $this->assertFalse($record['retracted']);
    }

    /**
     * The polite pool address is sent when one is configured.
     *
     * Without it both services throttle hard, so this is worth pinning down.
     */
    public function test_contact_email_is_sent(): void {
        $this->resetAfterTest();
        set_config('contactemail', 'moodle@example.com', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(200, [], self::crossref_body())]);

        (new crossref())->check(self::reference());

        $this->assertStringContainsString('mailto=moodle%40example.com', $this->requested_uri());
    }

    /**
     * A known DOI is resolved directly instead of searched for.
     */
    public function test_crossref_resolves_a_doi_directly(): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response(200, [], json_encode(['message' => [
            'DOI' => '10.5555/attention',
            'title' => ['Attention Is All You Need'],
            'author' => [['given' => 'Ashish', 'family' => 'Vaswani']],
            'issued' => ['date-parts' => [[2017]]],
        ]]))]);

        $record = (new crossref())->check(self::reference(['doi' => '10.5555/attention']));

        $this->assertSame('Attention Is All You Need', $record['title']);
        $this->assertStringContainsString('/works/10.5555', $this->requested_uri());
    }

    /**
     * CrossRef's Retraction Watch data is picked up from the update-to relationships.
     */
    public function test_crossref_detects_a_retraction(): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response(200, [], json_encode([
            'message' => ['items' => [[
                'DOI' => '10.5555/bad',
                'title' => ['Attention Is All You Need'],
                'issued' => ['date-parts' => [[2017]]],
                'update-to' => [['type' => 'retraction']],
            ]]],
        ]))]);

        $record = (new crossref())->check(self::reference());

        $this->assertTrue($record['retracted']);
    }

    /**
     * OpenAlex maps its own response shape, including its retraction flag.
     */
    public function test_openalex_maps_a_search_result(): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response(200, [], self::openalex_body(true))]);

        $record = (new openalex())->check(self::reference());

        $this->assertSame('Attention Is All You Need', $record['title']);
        $this->assertSame(['Ashish Vaswani'], $record['authors']);
        $this->assertSame('NeurIPS', $record['journal']);
        // The resolver prefix must be stripped so DOIs compare cleanly.
        $this->assertSame('10.5555/attention', $record['doi']);
        $this->assertTrue($record['retracted']);
    }

    /**
     * OpenAlex is not asked about a reference whose title could not be parsed.
     *
     * Sending a whole citation to a title filter returns confident nonsense.
     */
    public function test_openalex_skips_when_no_title_was_parsed(): void {
        $this->resetAfterTest();
        $this->mock_responses([]);

        $this->assertNull((new openalex())->check(self::reference(['title' => null, 'doi' => null])));
        $this->assertSame([], $this->history);
    }

    /**
     * An empty result set is "not found", not an error.
     */
    public function test_empty_results_are_not_an_error(): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response(200, [], json_encode(['results' => []]))]);

        $this->assertNull((new openalex())->check(self::reference()));
    }

    /**
     * HTTP statuses map onto the right kind of failure.
     *
     * This is the distinction the background tasks depend on: transient means back off and retry,
     * permanent means give up on this reference and move on.
     *
     * @dataProvider status_provider
     * @param int $status The HTTP status returned.
     * @param string $expected The exception class expected.
     */
    public function test_http_status_maps_to_exception_type(int $status, string $expected): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response($status, [], '{}')]);

        $this->expectException($expected);
        (new crossref())->check(self::reference());
    }

    /**
     * Data provider of HTTP statuses and the failure they represent.
     *
     * @return array[]
     */
    public static function status_provider(): array {
        return [
            'server error' => [500, transient_exception::class],
            'bad gateway' => [502, transient_exception::class],
            'refused' => [429, service_refused_exception::class],
            'bad request' => [400, permanent_exception::class],
        ];
    }

    /**
     * A Retry-After header is honoured rather than replaced with a guess.
     */
    public function test_retry_after_is_read_from_the_header(): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response(429, ['Retry-After' => '120'], '')]);

        try {
            (new crossref())->check(self::reference());
            $this->fail('Expected a service_refused_exception');
        } catch (service_refused_exception $e) {
            $this->assertSame(120, $e->get_retry_after());
        }
    }

    /**
     * A wildly large Retry-After is clamped so a task cannot be parked for days.
     */
    public function test_retry_after_is_clamped(): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response(429, ['Retry-After' => '999999'], '')]);

        try {
            (new crossref())->check(self::reference());
            $this->fail('Expected a service_refused_exception');
        } catch (service_refused_exception $e) {
            $this->assertSame(service_refused_exception::MAX_RETRY_AFTER, $e->get_retry_after());
        }
    }

    /**
     * A response that is not JSON is not worth retrying.
     */
    public function test_non_json_is_permanent(): void {
        $this->resetAfterTest();
        $this->mock_responses([new Response(200, [], '<html>error</html>')]);

        $this->expectException(permanent_exception::class);
        (new crossref())->check(self::reference());
    }

    /**
     * The chain accepts a convincing match from the first source and stops there.
     */
    public function test_chain_accepts_the_first_good_match(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref,openalex', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(200, [], self::crossref_body())]);

        $result = chain::from_config()->check(self::reference());

        $this->assertSame(match_status::VERIFIED, $result['matchstatus']);
        $this->assertSame('crossref', $result['source']);
        // OpenAlex must not have been asked.
        $this->assertCount(1, $this->history);
    }

    /**
     * A weak title match is reported as not found rather than as a different paper.
     *
     * Showing a student an unrelated work and calling it theirs is the worst failure this plugin
     * can produce, so the threshold is enforced in the chain as well as in the sources.
     */
    public function test_chain_rejects_a_weak_title_match(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(200, [], json_encode([
            'message' => ['items' => [[
                'DOI' => '10.5555/other',
                'title' => ['Soil erosion patterns in coastal wetlands'],
                'issued' => ['date-parts' => [[2017]]],
            ]]],
        ]))]);

        $result = chain::from_config()->check(self::reference());

        $this->assertSame(match_status::NOTFOUND, $result['matchstatus']);
        $this->assertNull($result['record']);
    }

    /**
     * When every source is down, ask to be retried.
     *
     * Reporting "not found" here would tell a student their reference does not exist on the
     * strength of a lookup that never happened.
     */
    public function test_chain_retries_rather_than_reporting_a_false_negative(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref,openalex', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(503, [], ''), new Response(503, [], '')]);

        $this->expectException(transient_exception::class);
        chain::from_config()->check(self::reference());
    }

    /**
     * A source that is down is skipped when another source can still answer.
     */
    public function test_chain_falls_through_a_failed_source(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref,openalex', 'assignsubmission_refchecker');
        $this->mock_responses([
            new Response(503, [], ''),
            new Response(200, [], self::openalex_body()),
        ]);

        $result = chain::from_config()->check(self::reference());

        $this->assertSame('openalex', $result['source']);
        $this->assertNotSame(match_status::NOTFOUND, $result['matchstatus']);
    }

    /**
     * A database refusing outright does not stop the others being asked.
     *
     * This is the difference between a submission that finishes and one that does not. Semantic
     * Scholar answers 429 while it is overloaded even to a keyed caller pacing itself well inside
     * the documented allowance, and it sits last in the chain: treating that as a reason to abandon
     * the search would throw away the answers the earlier databases had already given.
     */
    public function test_chain_carries_on_when_a_source_refuses(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref,openalex', 'assignsubmission_refchecker');
        $this->mock_responses([
            new Response(429, ['Retry-After' => '30'], ''),
            new Response(200, [], self::openalex_body()),
        ]);

        $result = chain::from_config()->check(self::reference());

        $this->assertSame('openalex', $result['source']);
        $this->assertNotSame(match_status::NOTFOUND, $result['matchstatus']);
        $this->assertSame(['crossref'], $result['unavailable']);
    }

    /**
     * A refusal stands its source down from the very first one.
     *
     * Unlike a 500, which might be a blip worth tolerating once, a refusal is the service saying
     * something definite. Asking again on the next reference is how a service that was merely busy
     * decides to start blocking the site properly.
     */
    public function test_a_refusal_stands_the_source_down_immediately(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref,openalex', 'assignsubmission_refchecker');
        $this->mock_responses([
            new Response(429, ['Retry-After' => '300'], ''),
            new Response(200, [], self::openalex_body()),
        ]);

        chain::from_config()->check(self::reference());

        $this->assertTrue(circuit_breaker::is_open('crossref'));
        // At least as long as the service asked for, rather than the breaker's opening backoff.
        $this->assertGreaterThan(circuit_breaker::BASE_BACKOFF, circuit_breaker::remaining('crossref'));
    }

    /**
     * Every database refusing is still a reason to try again later.
     *
     * Nothing was searched, so "not found" would be a claim about a lookup that never happened.
     * This is a transient failure of the reference rather than a pause of the whole task: the
     * reference's own attempt budget bounds it.
     */
    public function test_chain_retries_when_every_source_refuses(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref,openalex', 'assignsubmission_refchecker');
        $this->mock_responses([
            new Response(429, [], ''),
            new Response(429, [], ''),
        ]);

        $this->expectException(service_refused_exception::class);
        chain::from_config()->check(self::reference());
    }

    /**
     * The pacer declining to send anything still stops the chain.
     *
     * The one case that is genuinely about our own request rate rather than any one database, so
     * routing around it by asking the next source would defeat the point.
     */
    public function test_chain_propagates_the_pacers_refusal(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref,openalex', 'assignsubmission_refchecker');
        // Far enough ahead that the pacer hands the wait back rather than sleeping through it.
        set_config('rateinterval_crossref', 60000, 'assignsubmission_refchecker');
        rate_limiter::throttle('crossref');

        $this->expectException(rate_limited_exception::class);
        chain::from_config()->check(self::reference());
    }

    /**
     * An arXiv Atom feed maps into the shape the matcher expects.
     */
    public function test_arxiv_parses_atom(): void {
        $this->mock_responses([new Response(200, [], self::arxiv_body())]);

        $record = (new arxiv())->check(self::reference());

        $this->assertSame('Attention Is All You Need', $record['title']);
        $this->assertSame(['Ashish Vaswani', 'Noam Shazeer'], $record['authors']);
        $this->assertSame(2017, $record['year']);
        // No citation counts are published by this service, and inventing a zero would mislead.
        $this->assertNull($record['citations']);
    }

    /**
     * A reference carrying an arXiv identifier is looked up rather than searched for.
     */
    public function test_arxiv_uses_the_identifier_when_present(): void {
        $this->mock_responses([new Response(200, [], self::arxiv_body())]);

        (new arxiv())->check(self::reference(['arxivid' => '1706.03762']));

        $this->assertStringContainsString('id_list=1706.03762', $this->requested_uri());
    }

    /**
     * Only CrossRef and OpenAlex run polite pools, so only they are sent a contact address.
     *
     * @dataProvider polite_pool_provider
     * @param string $class The source class under test.
     * @param string $body A valid response body for it.
     * @param bool $expected Whether mailto should appear in the request.
     */
    public function test_polite_pool_is_opt_in(string $class, string $body, bool $expected): void {
        set_config('contactemail', 'moodle@example.com', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(200, [], $body)]);

        (new $class())->check(self::reference());

        if ($expected) {
            $this->assertStringContainsString('mailto=', $this->requested_uri());
        } else {
            $this->assertStringNotContainsString('mailto=', $this->requested_uri());
        }
    }

    /**
     * Data provider of sources and whether they take a polite pool address.
     *
     * @return array[]
     */
    public static function polite_pool_provider(): array {
        return [
            'crossref does' => [crossref::class, self::crossref_body(), true],
            'openalex does' => [openalex::class, self::openalex_body(), true],
            'arxiv does not' => [arxiv::class, self::arxiv_body(), false],
            'dblp does not' => [dblp::class, self::dblp_body(), false],
        ];
    }

    /**
     * DBLP author lists arrive in three different shapes, all of which occur in practice.
     *
     * @dataProvider dblp_author_provider
     * @param mixed $authors The authors node as DBLP returned it.
     * @param int $expected How many names should be recovered.
     */
    public function test_dblp_author_shapes($authors, int $expected): void {
        $this->mock_responses([new Response(200, [], json_encode(['result' => ['hits' => ['hit' => [
            ['info' => [
                'title' => 'Attention is all you need',
                'year' => '2017',
                'venue' => 'NIPS',
                'authors' => ['author' => $authors],
            ]],
        ]]]]))]);

        $record = (new dblp())->check(self::reference());

        $this->assertCount($expected, $record['authors']);
    }

    /**
     * Data provider of DBLP author shapes.
     *
     * @return array[]
     */
    public static function dblp_author_provider(): array {
        return [
            'a single author is not wrapped in an array' => [['@pid' => '1', 'text' => 'Solo Author'], 1],
            'an author may be a bare string' => ['Plain Name', 1],
            'the usual list of objects' => [
                [['@pid' => '1', 'text' => 'One Name'], ['@pid' => '2', 'text' => 'Two Name']],
                2,
            ],
        ];
    }

    /**
     * DBLP records the DOI in its link list when the dedicated field is absent.
     */
    public function test_dblp_recovers_the_doi_from_the_link(): void {
        $this->mock_responses([new Response(200, [], json_encode(['result' => ['hits' => ['hit' => [
            ['info' => [
                'title' => 'Attention is all you need',
                'year' => '2017',
                'ee' => 'https://doi.org/10.5555/FROMEE',
            ]],
        ]]]]))]);

        $record = (new dblp())->check(self::reference());

        $this->assertSame('10.5555/fromee', $record['doi']);
    }

    /**
     * DBLP signals overload with 500 rather than 429, which must be retried rather than given up on.
     */
    public function test_dblp_500_is_transient(): void {
        $this->mock_responses([new Response(500, [], '<html>error</html>')]);

        $this->expectException(transient_exception::class);
        (new dblp())->check(self::reference());
    }

    /**
     * The Semantic Scholar field list must never ask for isRetracted.
     *
     * Regression test. The upstream project requests it, but the field no longer exists and the
     * API rejects the entire call with "Unrecognized or unsupported fields", so copying its list
     * verbatim breaks every request.
     */
    public function test_semanticscholar_does_not_request_the_removed_field(): void {
        set_config('semanticscholarkey', 'test-key', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(200, [], self::semanticscholar_body())]);

        (new semanticscholar())->check(self::reference());

        $uri = $this->requested_uri();
        $this->assertStringNotContainsString('isRetracted', $uri);
        $this->assertStringContainsString('publicationTypes', $uri);
    }

    /**
     * The API key is sent as a header when one is configured.
     */
    public function test_semanticscholar_sends_the_api_key(): void {
        set_config('semanticscholarkey', 'test-key', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(200, [], self::semanticscholar_body())]);

        (new semanticscholar())->check(self::reference());

        $this->assertSame('test-key', $this->history[0]['request']->getHeaderLine('x-api-key'));
    }

    /**
     * Without a key the source reports itself unavailable rather than failing request by request.
     */
    public function test_semanticscholar_is_unavailable_without_a_key(): void {
        set_config('semanticscholarkey', '', 'assignsubmission_refchecker');

        $this->assertFalse((new semanticscholar())->is_available());
    }

    /**
     * A high confidence match whose year disagrees does not end the search.
     *
     * The case this exists for: widely cited papers get re-deposited under new identifiers, so
     * CrossRef and OpenAlex answer "Attention Is All You Need" with 2025 copies while arXiv still
     * holds the 2017 original the student actually read.
     */
    public function test_chain_prefers_the_year_consistent_source(): void {
        set_config('sources', 'crossref,arxiv', 'assignsubmission_refchecker');

        $this->mock_responses([
            // Same title from CrossRef, but a re-deposit published years later.
            new Response(200, [], json_encode(['message' => ['items' => [[
                'DOI' => '10.9999/redeposit',
                'title' => ['Attention Is All You Need'],
                'author' => [['given' => 'Ashish', 'family' => 'Vaswani']],
                'issued' => ['date-parts' => [[2025]]],
            ]]]])),
            new Response(200, [], self::arxiv_body()),
        ]);

        $result = chain::from_config()->check(self::reference());

        $this->assertCount(2, $this->history, 'Both sources should have been consulted.');
        $this->assertSame('arxiv', $result['source']);
        $this->assertSame(2017, $result['record']['year']);
        $this->assertTrue($result['yearagrees']);
    }

    /**
     * A strong match that agrees on the year still stops the search immediately.
     */
    public function test_chain_still_stops_on_a_strong_match(): void {
        set_config('sources', 'crossref,arxiv', 'assignsubmission_refchecker');

        $this->mock_responses([new Response(200, [], self::crossref_body())]);

        $result = chain::from_config()->check(self::reference());

        $this->assertCount(1, $this->history, 'arXiv should not have been consulted.');
        $this->assertSame('crossref', $result['source']);
    }

    /**
     * The registry, not the settings order, decides which source is asked first.
     */
    public function test_chain_order_follows_the_registry(): void {
        set_config('sources', 'dblp,crossref', 'assignsubmission_refchecker');

        $names = array_map(
            static fn($source) => $source->get_name(),
            chain::from_config()->get_sources(),
        );

        $this->assertSame(['crossref', 'dblp'], $names);
    }

    /**
     * A flaky database must not be able to fail a lookup the others answered.
     *
     * This is the case seen in production: a reference no database indexes, plus DBLP returning
     * 500s. Before, that failed the whole task and eventually marked the entire submission as
     * failed. Three services saying "not in my index" is a real answer.
     */
    public function test_one_flaky_source_does_not_fail_the_lookup(): void {
        set_config('sources', 'crossref,dblp', 'assignsubmission_refchecker');

        $this->mock_responses([
            // CrossRef has nothing matching.
            new Response(200, [], json_encode(['message' => ['items' => []]])),
            // DBLP falls over, as it does under load.
            new Response(500, [], '<html>error</html>'),
        ]);

        $result = chain::from_config()->check(self::reference());

        $this->assertSame(match_status::NOTFOUND, $result['matchstatus']);
        $this->assertTrue($result['degraded']);
        $this->assertSame(['dblp'], $result['unavailable']);
    }

    /**
     * When nothing answered at all, the caller is asked to try again.
     *
     * Reporting "not found" here would mean saying a work does not exist on the strength of a
     * search that never happened.
     */
    public function test_no_source_answering_still_throws(): void {
        set_config('sources', 'crossref,dblp', 'assignsubmission_refchecker');

        $this->mock_responses([
            new Response(503, [], ''),
            new Response(500, [], ''),
        ]);

        $this->expectException(transient_exception::class);
        chain::from_config()->check(self::reference());
    }

    /**
     * Finding the work is a true positive however many other databases were down.
     *
     * Only a negative is degraded, because only a negative could have been changed by the missing
     * database. This matters because degraded results are kept out of the shared cache.
     */
    public function test_a_positive_match_is_not_degraded(): void {
        set_config('sources', 'dblp,crossref', 'assignsubmission_refchecker');

        $this->mock_responses([
            new Response(500, [], ''),
            new Response(200, [], self::crossref_body()),
        ]);

        $result = chain::from_config()->check(self::reference());

        $this->assertSame(match_status::VERIFIED, $result['matchstatus']);
        $this->assertFalse($result['degraded']);
        $this->assertSame(['dblp'], $result['unavailable']);
    }

    /**
     * A source stood down by the circuit breaker is skipped rather than asked.
     */
    public function test_a_stood_down_source_is_skipped(): void {
        set_config('sources', 'crossref,dblp', 'assignsubmission_refchecker');

        circuit_breaker::record_failure('dblp');
        circuit_breaker::record_failure('dblp');

        $this->mock_responses([new Response(200, [], json_encode(['message' => ['items' => []]]))]);

        $result = chain::from_config()->check(self::reference());

        // Only CrossRef was contacted.
        $this->assertCount(1, $this->history);
        $this->assertSame(['dblp'], $result['unavailable']);
        $this->assertTrue($result['degraded']);
    }

    /**
     * Repeated failures stand a source down without any extra bookkeeping by the caller.
     */
    public function test_failures_feed_the_circuit_breaker(): void {
        set_config('sources', 'dblp', 'assignsubmission_refchecker');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->mock_responses([new Response(500, [], '')]);
            try {
                chain::from_config()->check(self::reference());
            } catch (transient_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertTrue(circuit_breaker::is_open('dblp'));
    }

    /**
     * A successful answer clears a source's failure record.
     */
    public function test_a_success_clears_the_breaker(): void {
        set_config('sources', 'crossref', 'assignsubmission_refchecker');

        circuit_breaker::record_failure('crossref');

        $this->mock_responses([new Response(200, [], self::crossref_body())]);
        chain::from_config()->check(self::reference());

        $this->assertFalse(circuit_breaker::is_open('crossref'));
    }

    /**
     * A predatory journal on a matched record is flagged.
     */
    public function test_chain_flags_a_predatory_journal(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(200, [], json_encode([
            'message' => ['items' => [[
                'DOI' => '10.5555/attention',
                'title' => ['Attention Is All You Need'],
                'container-title' => ['OMICS Publishing Group'],
                'issued' => ['date-parts' => [[2017]]],
            ]]],
        ]))]);

        $result = chain::from_config()->check(self::reference());

        $this->assertTrue($result['record']['predatory']);
    }
}

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
use assignsubmission_refchecker\local\exception\transient_exception;
use assignsubmission_refchecker\local\match_status;
use assignsubmission_refchecker\local\source\chain;
use assignsubmission_refchecker\local\source\crossref;
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
            'rate limited' => [429, rate_limited_exception::class],
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
            $this->fail('Expected a rate_limited_exception');
        } catch (rate_limited_exception $e) {
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
            $this->fail('Expected a rate_limited_exception');
        } catch (rate_limited_exception $e) {
            $this->assertSame(rate_limited_exception::MAX_RETRY_AFTER, $e->get_retry_after());
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
     * Rate limiting is not routed around by trying the next source.
     */
    public function test_chain_propagates_rate_limiting(): void {
        $this->resetAfterTest();
        set_config('sources', 'crossref,openalex', 'assignsubmission_refchecker');
        $this->mock_responses([new Response(429, ['Retry-After' => '30'], '')]);

        $this->expectException(rate_limited_exception::class);
        chain::from_config()->check(self::reference());
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

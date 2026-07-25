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
use assignsubmission_refchecker\local\rate_limiter;
use core\http_client;
use core_text;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Shared plumbing for the JSON over HTTP bibliographic databases.
 *
 * Holds the one decision that matters most to the pipeline: turning an HTTP outcome into either a
 * retryable or an unretryable failure, so the background tasks never have to interpret status
 * codes themselves.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class http_source implements reference_source {
    /** @var int How many candidates to ask for when searching. */
    protected const CANDIDATES = 5;

    /**
     * Constructor.
     *
     * @param http_client|null $client Injected so tests can supply a mock handler.
     */
    public function __construct(
        /** @var http_client|null The HTTP client, resolved lazily from the DI container. */
        protected ?http_client $client = null,
    ) {
    }

    /**
     * The HTTP client to use.
     *
     * Resolved through the DI container rather than constructed directly, because that is what
     * advanced_testcase::get_mocked_http_client() replaces.
     *
     * @return http_client
     */
    protected function client(): http_client {
        if ($this->client === null) {
            $this->client = \core\di::get(http_client::class);
        }

        return $this->client;
    }

    /**
     * Fetch and decode a JSON document.
     *
     * @param string $url
     * @return array|null Decoded JSON, or null when the record simply does not exist.
     * @throws transient_exception
     * @throws permanent_exception
     */
    protected function fetch(string $url): ?array {
        $body = $this->send($url, 'application/json');
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new permanent_exception($this->get_name() . ' returned a response that was not JSON');
        }

        return $decoded;
    }

    /**
     * Fetch and parse an XML document.
     *
     * arXiv answers in Atom rather than JSON, so it needs its own decoding step. Everything before
     * the decoding is shared, which is what keeps the two paths from drifting apart on how they
     * interpret a status code.
     *
     * @param string $url
     * @return \SimpleXMLElement|null Null when the record simply does not exist.
     * @throws transient_exception
     * @throws permanent_exception
     */
    protected function fetch_xml(string $url): ?\SimpleXMLElement {
        $body = $this->send($url, 'application/atom+xml, application/xml');
        if ($body === null) {
            return null;
        }

        // Parse errors must not leak into the page as PHP warnings.
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new permanent_exception($this->get_name() . ' returned a response that was not XML');
        }

        return $xml;
    }

    /**
     * Send a request and return its body, mapping the outcome onto our two failure kinds.
     *
     * This is where every request is paced, so no source can bypass the throttle by accident.
     *
     * @param string $url
     * @param string $accept The Accept header to send.
     * @return string|null The body, or null when the record does not exist.
     * @throws transient_exception
     * @throws permanent_exception
     */
    protected function send(string $url, string $accept): ?string {
        rate_limiter::throttle($this->get_name());

        $timeout = (int) get_config('assignsubmission_refchecker', 'requesttimeout') ?: 30;

        try {
            $response = $this->client()->request('GET', $url, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::HEADERS => array_merge(
                    ['Accept' => $accept],
                    $this->extra_headers(),
                ),
            ]);
        } catch (GuzzleException | Throwable $e) {
            // Connection refused, DNS failure, timeout: all worth trying again later.
            throw new transient_exception($this->get_name() . ': ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 404) {
            // A direct lookup for something that is not there. Not an error.
            return null;
        }

        if ($status === 429) {
            throw new rate_limited_exception($this->retry_after($response->getHeaderLine('Retry-After')));
        }

        if ($status >= 500) {
            // DBLP signals overload with 500 rather than 429, so this path is routine for it
            // rather than exceptional.
            throw new transient_exception($this->get_name() . ' returned HTTP ' . $status);
        }

        if ($status >= 400) {
            throw new permanent_exception($this->get_name() . ' rejected the request: HTTP ' . $status);
        }

        return (string) $response->getBody();
    }

    /**
     * Any headers this source needs beyond the defaults, such as an API key.
     *
     * @return array<string, string>
     */
    protected function extra_headers(): array {
        return [];
    }

    /**
     * Interpret a Retry-After header, which may be seconds or an HTTP date.
     *
     * @param string $header
     * @return int Seconds.
     */
    protected function retry_after(string $header): int {
        $header = trim($header);
        if ($header === '') {
            return rate_limited_exception::DEFAULT_RETRY_AFTER;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false
            ? rate_limited_exception::DEFAULT_RETRY_AFTER
            : max(1, $timestamp - time());
    }

    /**
     * The site's contact address, sent to put requests in the services' polite pools.
     *
     * @return string
     */
    protected function contact_email(): string {
        return trim((string) get_config('assignsubmission_refchecker', 'contactemail'));
    }

    /**
     * Whether this service operates a polite pool keyed on a contact address.
     *
     * Only CrossRef and OpenAlex do. Sending mailto to the others achieves nothing and, on a
     * service that treats unknown parameters as part of the query, could actively distort results.
     *
     * @return bool
     */
    protected function uses_polite_pool(): bool {
        return false;
    }

    /**
     * Add the polite pool address to a query if this service wants one and it is configured.
     *
     * @param array $query
     * @return array
     */
    protected function with_contact(array $query): array {
        $email = $this->contact_email();
        if ($this->uses_polite_pool() && $email !== '') {
            $query['mailto'] = $email;
        }

        return $query;
    }

    /**
     * Whether a title reads like a retraction notice.
     *
     * Only CrossRef and OpenAlex publish real retraction data. For the rest this prefix convention
     * is all there is, so it is a weak signal that must never be read as an all-clear when absent.
     *
     * @param string $title
     * @return bool
     */
    protected function looks_retracted(string $title): bool {
        $title = core_text::strtolower(trim($title));

        foreach (['retracted', 'retraction', 'withdrawn', 'withdrawal'] as $marker) {
            if (str_starts_with($title, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a request URL.
     *
     * @param string $base
     * @param array $query
     * @return string
     */
    protected function url(string $base, array $query): string {
        return $base . '?' . http_build_query($this->with_contact($query), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Default availability probe: a cheap search that should always succeed.
     *
     * @return bool
     */
    public function is_available(): bool {
        try {
            return $this->fetch($this->availability_url()) !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * A URL that is cheap to fetch and proves the service is answering.
     *
     * @return string
     */
    abstract protected function availability_url(): string;
}

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
use core\http_client;
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
        $timeout = (int) get_config('assignsubmission_refchecker', 'requesttimeout') ?: 30;

        try {
            $response = $this->client()->request('GET', $url, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::HEADERS => ['Accept' => 'application/json'],
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
            throw new transient_exception($this->get_name() . ' returned HTTP ' . $status);
        }

        if ($status >= 400) {
            throw new permanent_exception($this->get_name() . ' rejected the request: HTTP ' . $status);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new permanent_exception($this->get_name() . ' returned a response that was not JSON');
        }

        return $decoded;
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
     * Add the polite pool address to a query if one is configured.
     *
     * @param array $query
     * @return array
     */
    protected function with_contact(array $query): array {
        $email = $this->contact_email();
        if ($email !== '') {
            $query['mailto'] = $email;
        }

        return $query;
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

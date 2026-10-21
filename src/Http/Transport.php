<?php

namespace OwaSdk\Http;

/**
 * Sends one HTTP request and returns the response.
 *
 * Implementations must not throw on HTTP error statuses or on network failure:
 * a failure is reported as a Response with status 0 and an error message.
 */
interface Transport {

    /**
     * @param array<string, string> $headers
     * @param array{timeout?: float, connect_timeout?: float} $options
     */
    public function send( string $method, string $url, array $headers = [], ?string $body = null, array $options = [] ): Response;
}

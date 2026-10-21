<?php

namespace OwaSdk\Tests\Support;

use OwaSdk\Http\Response;
use OwaSdk\Http\Transport;

/**
 * Records requests and answers them from a queue (default: 200, empty body).
 */
class FakeTransport implements Transport {

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, options: array}> */
    public array $requests = [];

    /** @var list<Response> */
    private array $queue = [];

    public function queue( Response ...$responses ): self {

        array_push( $this->queue, ...$responses );
        return $this;
    }

    public function send( string $method, string $url, array $headers = [], ?string $body = null, array $options = [] ): Response {

        $this->requests[] = compact( 'method', 'url', 'headers', 'body', 'options' );

        return array_shift( $this->queue ) ?? new Response( 200 );
    }

    public function last(): array {

        return $this->requests[ count( $this->requests ) - 1 ];
    }

    /**
     * Decoded params of the last request: the query for GET, the body for POST.
     *
     * @return array<string, mixed>
     */
    public function lastParams(): array {

        $r = $this->last();
        $raw = $r['method'] === 'GET' ? (string) parse_url( $r['url'], PHP_URL_QUERY ) : (string) $r['body'];
        parse_str( $raw, $params );

        return $params;
    }
}

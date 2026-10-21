<?php

namespace OwaSdk\Http;

class Response {

    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly string $error = ''
    ) {}

    /**
     * True for a 2xx response.
     */
    public function isSuccess(): bool {

        return $this->status >= 200 && $this->status < 300;
    }

    public function getHeader( string $name ): ?string {

        return $this->headers[ strtolower( $name ) ] ?? null;
    }

    /**
     * The decoded JSON body, or null when the body is not JSON.
     */
    public function json(): mixed {

        if ( $this->body === '' ) {

            return null;
        }

        $decoded = json_decode( $this->body, true );

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Parses raw "Name: value" header lines. Later duplicates win.
     *
     * @param list<string> $lines
     * @return array<string, string>
     */
    public static function parseHeaderLines( array $lines ): array {

        $headers = [];

        foreach ( $lines as $line ) {

            $pos = strpos( $line, ':' );

            if ( $pos === false ) {

                // a status line starts a new response (redirects, 100 Continue)
                if ( str_starts_with( $line, 'HTTP/' ) ) {

                    $headers = [];
                }

                continue;
            }

            $headers[ strtolower( trim( substr( $line, 0, $pos ) ) ) ] = trim( substr( $line, $pos + 1 ) );
        }

        return $headers;
    }
}

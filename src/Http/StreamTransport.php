<?php

namespace OwaSdk\Http;

/**
 * Transport built on PHP's http:// stream wrapper, for hosts without ext-curl.
 * Needs allow_url_fopen.
 */
class StreamTransport implements Transport {

    public function send( string $method, string $url, array $headers = [], ?string $body = null, array $options = [] ): Response {

        $header_lines = [];

        foreach ( $headers as $name => $value ) {

            $header_lines[] = $name . ': ' . $value;
        }

        $http = [
            'method'          => strtoupper( $method ),
            'header'          => implode( "\r\n", $header_lines ),
            'timeout'         => (float) ( $options['timeout'] ?? 5 ),
            'ignore_errors'   => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        ];

        if ( $body !== null ) {

            $http['content'] = $body;
        }

        $context = stream_context_create( [ 'http' => $http ] );

        $error = '';

        set_error_handler( function ( $errno, $errstr ) use ( &$error ) {
            $error = $errstr;
            return true;
        } );

        $result = false;
        $raw_headers = [];

        try {

            $fp = fopen( $url, 'rb', false, $context );

            if ( $fp ) {

                $meta = stream_get_meta_data( $fp );
                $raw_headers = is_array( $meta['wrapper_data'] ?? null ) ? $meta['wrapper_data'] : [];
                $result = stream_get_contents( $fp );
                fclose( $fp );
            }

        } finally {

            restore_error_handler();
        }

        if ( $result === false || ! $raw_headers ) {

            return new Response( 0, '', [], $error ?: 'Request failed.' );
        }

        $status = 0;

        // the last status line is the final response
        foreach ( $raw_headers as $line ) {

            if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {

                $status = (int) $m[1];
            }
        }

        return new Response( $status, (string) $result, Response::parseHeaderLines( $raw_headers ) );
    }
}

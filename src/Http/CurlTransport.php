<?php

namespace OwaSdk\Http;

class CurlTransport implements Transport {

    public function send( string $method, string $url, array $headers = [], ?string $body = null, array $options = [] ): Response {

        $ch = curl_init();

        $header_lines = [];

        foreach ( $headers as $name => $value ) {

            $header_lines[] = $name . ': ' . $value;
        }

        $response_headers = [];

        curl_setopt_array( $ch, [
            CURLOPT_URL             => $url,
            CURLOPT_CUSTOMREQUEST   => strtoupper( $method ),
            CURLOPT_HTTPHEADER      => $header_lines,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_TIMEOUT_MS      => (int) ( ( $options['timeout'] ?? 5 ) * 1000 ),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ( ( $options['connect_timeout'] ?? 2 ) * 1000 ),
            CURLOPT_HEADERFUNCTION  => function ( $ch, $line ) use ( &$response_headers ) {
                $response_headers[] = rtrim( $line, "\r\n" );
                return strlen( $line );
            },
        ] );

        if ( $body !== null ) {

            curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
        }

        $result = curl_exec( $ch );

        if ( $result === false ) {

            $error = curl_error( $ch );

            return new Response( 0, '', [], $error ?: 'Request failed.' );
        }

        $status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );

        return new Response( $status, (string) $result, Response::parseHeaderLines( $response_headers ) );
    }
}

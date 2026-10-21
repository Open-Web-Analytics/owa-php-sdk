<?php

// Router for `php -S`: echoes the request back as JSON so transports can be
// tested against a real socket.

// Never answer under a real web server: it would echo visitors' cookies back.
if ( PHP_SAPI !== 'cli-server' ) {

    http_response_code( 404 );
    return;
}

if ( ( $_SERVER['REQUEST_URI'] ?? '' ) === '/status/404' ) {

    http_response_code( 404 );
    header( 'Content-Type: application/json' );
    echo json_encode( [ 'status' => 'error' ] );
    return true;
}

$headers = [];

foreach ( $_SERVER as $k => $v ) {

    if ( str_starts_with( $k, 'HTTP_' ) ) {

        $headers[ strtolower( str_replace( '_', '-', substr( $k, 5 ) ) ) ] = $v;
    }
}

header( 'Content-Type: application/json' );
header( 'X-Echo: yes' );

echo json_encode( [
    'method'  => $_SERVER['REQUEST_METHOD'],
    'uri'     => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body'    => file_get_contents( 'php://input' ),
] );

return true;

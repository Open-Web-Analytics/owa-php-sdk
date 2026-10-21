<?php

namespace OwaSdk\Tests\Http;

use OwaSdk\Http\CurlTransport;
use OwaSdk\Http\StreamTransport;
use OwaSdk\Http\Transport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs both transports against a real `php -S` server.
 */
class TransportTest extends TestCase {

    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    public static function setUpBeforeClass(): void {

        $sock = stream_socket_server( 'tcp://127.0.0.1:0' );
        $port = (int) substr( strrchr( stream_socket_get_name( $sock, false ), ':' ), 1 );
        fclose( $sock );

        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg( PHP_BINARY ),
            $port,
            escapeshellarg( dirname( __DIR__ ) . '/fixtures/echo-server.php' )
        );

        self::$server = proc_open( $cmd, [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
        self::$base = "http://127.0.0.1:$port";

        // wait for the listener
        for ( $i = 0; $i < 50; $i++ ) {

            $c = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 );

            if ( $c ) {

                fclose( $c );
                return;
            }

            usleep( 50000 );
        }

        self::fail( 'php -S did not start.' );
    }

    public static function tearDownAfterClass(): void {

        if ( self::$server ) {

            proc_terminate( self::$server );
            proc_close( self::$server );
            self::$server = null;
        }
    }

    /**
     * @return array<string, array{0: Transport}>
     */
    public static function transports(): array {

        $t = [ 'stream' => [ new StreamTransport() ] ];

        if ( extension_loaded( 'curl' ) ) {

            $t['curl'] = [ new CurlTransport() ];
        }

        return $t;
    }

    #[DataProvider( 'transports' )]
    public function testGetSendsHeadersAndQuery( Transport $transport ): void {

        $res = $transport->send( 'GET', self::$base . '/log.php?owa_a=1&owa_b=two%20words', [ 'X-Test' => 'abc', 'User-Agent' => 'ua/1' ] );

        $this->assertSame( 200, $res->status );
        $this->assertSame( 'yes', $res->getHeader( 'X-Echo' ) );

        $echo = $res->json();

        $this->assertSame( 'GET', $echo['method'] );
        $this->assertSame( '/log.php?owa_a=1&owa_b=two%20words', $echo['uri'] );
        $this->assertSame( 'abc', $echo['headers']['x-test'] );
        $this->assertSame( 'ua/1', $echo['headers']['user-agent'] );
    }

    #[DataProvider( 'transports' )]
    public function testPostSendsBody( Transport $transport ): void {

        $res = $transport->send( 'POST', self::$base . '/api/x', [ 'Content-Type' => 'application/x-www-form-urlencoded' ], 'owa_domain=example.com' );

        $echo = $res->json();

        $this->assertSame( 'POST', $echo['method'] );
        $this->assertSame( 'owa_domain=example.com', $echo['body'] );
    }

    #[DataProvider( 'transports' )]
    public function testErrorStatusIsReturnedNotThrown( Transport $transport ): void {

        $res = $transport->send( 'GET', self::$base . '/status/404' );

        $this->assertSame( 404, $res->status );
        $this->assertFalse( $res->isSuccess() );
        $this->assertSame( [ 'status' => 'error' ], $res->json() );
    }

    #[DataProvider( 'transports' )]
    public function testConnectionFailureIsStatusZero( Transport $transport ): void {

        // port 9 (discard) on loopback: nothing listens there
        $res = $transport->send( 'GET', 'http://127.0.0.1:9/', [], null, [ 'timeout' => 1, 'connect_timeout' => 1 ] );

        $this->assertSame( 0, $res->status );
        $this->assertNotSame( '', $res->error );
    }
}

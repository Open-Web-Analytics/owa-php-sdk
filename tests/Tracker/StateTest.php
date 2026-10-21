<?php

namespace OwaSdk\Tests\Tracker;

use OwaSdk\Tracker\State;
use PHPUnit\Framework\TestCase;

class StateTest extends TestCase {

    /** @var list<array{name: string, value: string, options: array}> */
    private array $written = [];

    private function makeState( array $cookies = [], array $config = [] ): State {

        $this->written = [];

        $state = new State(
            $config + [ 'cookie_domain' => '.example.com', 'cookie_secure' => false ],
            $cookies,
            function ( $name, $value, $options ) {
                $this->written[] = [ 'name' => $name, 'value' => $value, 'options' => $options ];
            }
        );

        $state->registerStore( 'v', 364 );
        $state->registerStore( 'c', 60 );
        $state->registerStore( 's_site1', 364, true );

        return $state;
    }

    private function cookie( array $value ): string {

        return State::jsEscape( json_encode( $value, JSON_UNESCAPED_SLASHES ) );
    }

    private function lastWritten( string $name ): ?array {

        foreach ( array_reverse( $this->written ) as $w ) {

            if ( $w['name'] === $name ) {

                return $w;
            }
        }

        return null;
    }

    public function testCookieDomainHashMatchesJsTracker(): void {

        // crc32('.example.com') as unsigned hex, the value the JS tracker stamps
        $this->assertSame( dechex( crc32( '.example.com' ) ), State::crc32AsHex( '.example.com' ) );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{1,8}$/', State::crc32AsHex( '.example.com' ) );
    }

    public function testJsEscapeMatchesJavaScript(): void {

        // escape('{"a":"x y+z/é€"}') in a browser
        $this->assertSame( '%7B%22a%22%3A%22x%20y+z/%E9%u20AC%22%7D', State::jsEscape( '{"a":"x y+z/é€"}' ) );
        $this->assertSame( '@*_+-./AZaz09', State::jsEscape( '@*_+-./AZaz09' ) );
    }

    public function testJsUnescapeRoundTripsAndKeepsPlus(): void {

        $raw = '{"landing_url":"https://example.com/?q=a+b&x=é😀"}';

        $this->assertSame( $raw, State::jsUnescape( State::jsEscape( $raw ) ) );
        $this->assertSame( 'a+b', State::jsUnescape( 'a+b' ) );
    }

    public function testReadsJsonCookieWithMatchingDomainHash(): void {

        $hash = State::crc32AsHex( '.example.com' );
        $state = $this->makeState( [ 'owa_v' => [ $this->cookie( [ 'vid' => '123', 'nps' => '2', 'cdh' => $hash, 'sv' => 1 ] ) ] ] );

        $this->assertSame( '123', $state->get( 'v', 'vid' ) );
        $this->assertSame( '2', $state->get( 'v', 'nps' ) );
    }

    public function testAcceptsSingleValuePerCookieName(): void {

        // a framework's cookie bag is name => string, not name => list
        $hash = State::crc32AsHex( '.example.com' );
        $state = $this->makeState( [ 'owa_v' => $this->cookie( [ 'vid' => '123', 'cdh' => $hash, 'sv' => 1 ] ) ] );

        $this->assertSame( '123', $state->get( 'v', 'vid' ) );
    }

    public function testIgnoresCookieWithoutOrWithForeignDomainHash(): void {

        $state = $this->makeState( [ 'owa_v' => [
            $this->cookie( [ 'vid' => 'nohash' ] ),
            $this->cookie( [ 'vid' => 'foreign', 'cdh' => State::crc32AsHex( '.other.com' ) ] ),
        ] ] );

        $this->assertNull( $state->get( 'v', 'vid' ) );
    }

    public function testFirstDuplicateWithMatchingHashWins(): void {

        $hash = State::crc32AsHex( '.example.com' );
        $state = $this->makeState( [ 'owa_v' => [
            $this->cookie( [ 'vid' => 'sub', 'cdh' => State::crc32AsHex( '.www.example.com' ) ] ),
            $this->cookie( [ 'vid' => 'first', 'cdh' => $hash ] ),
            $this->cookie( [ 'vid' => 'second', 'cdh' => $hash ] ),
        ] ] );

        $this->assertSame( 'first', $state->get( 'v', 'vid' ) );
    }

    public function testIgnoresCookieFromNewerStoreVersion(): void {

        $hash = State::crc32AsHex( '.example.com' );
        $state = $this->makeState( [ 'owa_v' => [ $this->cookie( [ 'vid' => '1', 'cdh' => $hash, 'sv' => 2 ] ) ] ] );

        $this->assertNull( $state->get( 'v', 'vid' ) );
    }

    public function testReadsLegacyAssocFormat(): void {

        $hash = State::crc32AsHex( '.example.com' );
        $state = $this->makeState( [ 'owa_v' => [ rawurlencode( "vid=>42|||fsts=>1600000000|||cdh=>$hash" ) ] ] );

        $this->assertSame( '42', $state->get( 'v', 'vid' ) );
        $this->assertSame( '1600000000', $state->get( 'v', 'fsts' ) );
    }

    public function testSetPersistsJsonWithVersionAndHash(): void {

        $state = $this->makeState();
        $state->set( 'v', 'vid', '99' );

        $w = $this->lastWritten( 'owa_v' );
        $this->assertNotNull( $w );

        $value = json_decode( State::jsUnescape( $w['value'] ), true );
        $this->assertSame( [ 'vid' => '99', 'sv' => 1, 'cdh' => State::crc32AsHex( '.example.com' ) ], $value );

        $this->assertSame( '.example.com', $w['options']['domain'] );
        $this->assertSame( '/', $w['options']['path'] );
        $this->assertSame( 'Lax', $w['options']['samesite'] );
        $this->assertFalse( $w['options']['httponly'] );
        $this->assertEqualsWithDelta( time() + 364 * 86400, $w['options']['expires'], 5 );
    }

    public function testCookiePersistenceOffWritesSessionCookies(): void {

        $state = $this->makeState( [], [ 'cookie_persistence' => false ] );
        $state->set( 'v', 'vid', '1' );

        $this->assertSame( 0, $this->lastWritten( 'owa_v' )['options']['expires'] );
    }

    public function testDeferredStoreIsNotReadOrWrittenUntilSettled(): void {

        $hash = State::crc32AsHex( '.example.com' );
        $state = $this->makeState( [ 'owa_s_site1' => [ $this->cookie( [ 'sid' => 'old', 'last_req' => 100, 'cdh' => $hash ] ) ] ] );

        $state->set( 's_site1', 'landing_url', 'https://example.com/' );

        $this->assertNull( $state->get( 's_site1', 'sid' ), 'cookie must not merge before settle()' );
        $this->assertSame( 100, $state->getPersisted( 's_site1', 'last_req' ) );
        $this->assertNull( $this->lastWritten( 'owa_s_site1' ), 'deferred store must not write on set()' );

        $state->settle( 's_site1', false );

        $this->assertSame( 'old', $state->get( 's_site1', 'sid' ) );
        $this->assertSame( 'https://example.com/', $state->get( 's_site1', 'landing_url' ) );
    }

    public function testSettleWithDiscardErasesCookieAndKeepsMemory(): void {

        $hash = State::crc32AsHex( '.example.com' );
        $state = $this->makeState( [ 'owa_s_site1' => [ $this->cookie( [ 'sid' => 'old', 'referer' => 'x', 'cdh' => $hash ] ) ] ] );

        $state->set( 's_site1', 'is_new_visitor', true );
        $state->settle( 's_site1', true );

        $this->assertNull( $state->get( 's_site1', 'sid' ) );
        $this->assertNull( $state->get( 's_site1', 'referer' ) );
        $this->assertTrue( $state->get( 's_site1', 'is_new_visitor' ) );

        $erase = $this->lastWritten( 'owa_s_site1' );
        $this->assertSame( '', $erase['value'] );
        $this->assertLessThan( time(), $erase['options']['expires'] );
    }

    public function testClearWholeStoreErasesCookie(): void {

        $hash = State::crc32AsHex( '.example.com' );
        $state = $this->makeState( [ 'owa_c' => [ $this->cookie( [ 'attribs' => [], 'cdh' => $hash ] ) ] ] );

        $state->clear( 'c' );

        $this->assertNull( $state->get( 'c' ) );
        $this->assertSame( '', $this->lastWritten( 'owa_c' )['value'] );
    }

    public function testReadRequestCookiesKeepsDuplicatesAndRawValues(): void {

        $_SERVER['HTTP_COOKIE'] = 'owa_v=%7B%22a%22%3A1%7D; other=x; owa_v=second; owa_s=a+b=c';

        $cookies = State::readRequestCookies();

        $this->assertSame( [ '%7B%22a%22%3A1%7D', 'second' ], $cookies['owa_v'] );
        $this->assertSame( [ 'a+b=c' ], $cookies['owa_s'] );
    }

    public function testReadRequestCookiesFallsBackToSuperglobal(): void {

        unset( $_SERVER['HTTP_COOKIE'] );
        $_COOKIE = [ 'owa_v' => 'x' ];

        $this->assertSame( [ 'owa_v' => [ 'x' ] ], State::readRequestCookies() );
    }
}

<?php

namespace OwaSdk\Tests\Tracker;

use OwaSdk\Http\Response;
use OwaSdk\Tests\Support\FakeTransport;
use OwaSdk\Tracker\State;
use OwaSdk\Tracker\TrackerClient;
use OwaSdk\Tracker\TrackingEvent;
use PHPUnit\Framework\TestCase;

class TrackerClientTest extends TestCase {

    const SITE = 'aaaabbbbccccddddeeeeffff00001111';

    private FakeTransport $transport;

    /** @var list<array{name: string, value: string, options: array}> */
    private array $written = [];

    protected function setUp(): void {

        $_SERVER = [
            'HTTP_HOST'         => 'www.example.com',
            'REQUEST_URI'       => '/products?id=7',
            'HTTPS'             => 'on',
            'HTTP_USER_AGENT'   => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0',
            'REMOTE_ADDR'       => '203.0.113.9',
        ];
        $_GET = [];

        $this->transport = new FakeTransport();
        $this->written = [];
    }

    private function tracker( array $cookies = [], array $config = [] ): TrackerClient {

        $tracker = new TrackerClient( $config + [
            'instance_url'  => 'https://owa.example.com/owa',
            'transport'     => $this->transport,
            'cookies'       => $cookies,
            'cookie_writer' => function ( $name, $value, $options ) {
                $this->written[] = [ 'name' => $name, 'value' => $value, 'options' => $options ];
            },
        ] );

        $tracker->setSiteId( self::SITE );

        return $tracker;
    }

    /**
     * A cookie as the JS tracker writes it.
     */
    private function jsCookie( array $value, string $domain = '.example.com' ): string {

        $value += [ 'cdh' => State::crc32AsHex( $domain ), 'sv' => 1 ];

        return State::jsEscape( json_encode( $value, JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Last value written to a cookie, decoded; '' for an erase, null if never written.
     */
    private function cookieValue( string $name ): array|string|null {

        foreach ( array_reverse( $this->written ) as $w ) {

            if ( $w['name'] === $name ) {

                return $w['value'] === '' ? '' : State::decodeValue( $w['value'] );
            }
        }

        return null;
    }

    public function testFirstPageViewOfNewVisitor(): void {

        $tracker = $this->tracker();
        $tracker->setPageTitle( 'Products' );

        $this->assertTrue( $tracker->trackPageView() );

        $req = $this->transport->last();
        $this->assertSame( 'GET', $req['method'] );
        $this->assertStringStartsWith( 'https://owa.example.com/owa/log.php?', $req['url'] );

        $p = $this->transport->lastParams();

        $this->assertSame( 'base.page_request', $p['owa_event_type'] );
        $this->assertSame( self::SITE, $p['owa_site_id'] );
        $this->assertSame( 'Products', $p['owa_page_title'] );
        $this->assertSame( 'https://www.example.com/products?id=7', $p['owa_page_url'] );
        $this->assertSame( 'https://www.example.com/products?id=7', $p['owa_landing_url'] );
        $this->assertMatchesRegularExpression( '/^\d{19}$/', $p['owa_visitor_id'] );
        $this->assertMatchesRegularExpression( '/^\d{19}$/', $p['owa_session_id'] );
        $this->assertSame( 'true', $p['owa_is_new_session'] );
        $this->assertSame( 'true', $p['owa_is_new_session_start'] );
        $this->assertSame( 'true', $p['owa_is_new_visitor'] );
        $this->assertSame( 'true', $p['owa_is_new_visitor_created'] );
        $this->assertSame( '0', $p['owa_nps'] );
        $this->assertSame( '', $p['owa_last_req'] );
        $this->assertSame( $p['owa_timestamp'], $p['owa_fsts'] );
        $this->assertSame( $p['owa_timestamp'], $p['owa_sts'] );

        // dropped by OWA 1.14 or no longer sent by its tracker
        foreach ( [ 'dsfs', 'dsps', 'medium', 'source', 'search_terms', 'HTTP_USER_AGENT', 'ip_address', 'attribs', 'prior_session_id', 'psts' ] as $gone ) {

            $this->assertArrayNotHasKey( 'owa_' . $gone, $p, $gone );
        }

        $v = $this->cookieValue( 'owa_v' );
        $this->assertSame( $p['owa_visitor_id'], $v['vid'] );
        $this->assertSame( '0', $v['nps'] );
        $this->assertSame( 1, $v['sv'] );
        $this->assertSame( State::crc32AsHex( '.example.com' ), $v['cdh'] );

        $s = $this->cookieValue( 'owa_s_' . self::SITE );
        $this->assertSame( $p['owa_session_id'], $s['sid'] );
        $this->assertSame( (int) $p['owa_timestamp'], $s['last_req'] );
        $this->assertSame( '', $s['prior_last_req'] );
        $this->assertTrue( $s['is_new_visitor'] );
    }

    public function testContinuesSessionFromJsTrackerCookies(): void {

        $now = time();
        $tracker = $this->tracker( [
            'owa_v' => [ $this->jsCookie( [ 'vid' => '1700000000123456789', 'fsts' => $now - 86400 * 3, 'nps' => '2' ] ) ],
            'owa_s_' . self::SITE => [ $this->jsCookie( [
                'sid' => '1700000000987654321',
                'last_req' => $now - 60,
                'prior_last_req' => $now - 86400,
                'sts' => $now - 300,
                'psts' => $now - 86400 - 600,
                'prior_session_id' => '1690000000111111111',
                'referer' => 'https://search.example.net/?q=a+b',
                'landing_url' => 'https://www.example.com/?owa_medium=email',
            ] ) ],
        ] );

        $tracker->trackPageView();
        $p = $this->transport->lastParams();

        $this->assertSame( '1700000000123456789', $p['owa_visitor_id'] );
        $this->assertSame( '1700000000987654321', $p['owa_session_id'] );
        $this->assertSame( '2', $p['owa_nps'] );
        $this->assertSame( (string) ( $now - 86400 ), $p['owa_last_req'] );
        $this->assertSame( (string) ( $now - 300 ), $p['owa_sts'] );
        $this->assertSame( '1690000000111111111', $p['owa_prior_session_id'] );
        // '+' survives: escape() leaves it raw and $_COOKIE would have made it a space
        $this->assertSame( 'https://search.example.net/?q=a+b', $p['owa_session_referer'] );
        $this->assertSame( 'https://www.example.com/?owa_medium=email', $p['owa_landing_url'] );

        foreach ( [ 'is_new_session', 'is_new_session_start', 'is_new_visitor', 'is_new_visitor_created' ] as $flag ) {

            $this->assertArrayNotHasKey( 'owa_' . $flag, $p, $flag );
        }

        $s = $this->cookieValue( 'owa_s_' . self::SITE );
        $this->assertSame( '1700000000987654321', $s['sid'] );
        $this->assertGreaterThanOrEqual( $now, $s['last_req'] );
    }

    public function testExpiredSessionStartsNewOneAndIncrementsPriorSessions(): void {

        $now = time();
        $tracker = $this->tracker( [
            'owa_v' => [ $this->jsCookie( [ 'vid' => '1700000000123456789', 'fsts' => $now - 86400 * 10, 'nps' => '0' ] ) ],
            'owa_s_' . self::SITE => [ $this->jsCookie( [
                'sid' => '1700000000987654321',
                'last_req' => $now - 3600,
                'sts' => $now - 4000,
                'referer' => 'https://old.example.net/',
                'cv2' => 'plan=old',
            ] ) ],
        ] );

        $tracker->trackPageView();
        $p = $this->transport->lastParams();

        $this->assertNotSame( '1700000000987654321', $p['owa_session_id'] );
        $this->assertSame( '1700000000987654321', $p['owa_prior_session_id'] );
        $this->assertSame( (string) ( $now - 4000 ), $p['owa_psts'] );
        $this->assertSame( (string) ( $now - 3600 ), $p['owa_last_req'] );
        // "0" is truthy in JS; the 1.0.x SDK reset it to "0" on every session
        $this->assertSame( '1', $p['owa_nps'] );
        $this->assertSame( 'true', $p['owa_is_new_session'] );
        $this->assertArrayNotHasKey( 'owa_is_new_visitor', $p );

        // the ended session's values are discarded
        $this->assertArrayNotHasKey( 'owa_cv2', $p );
        $this->assertArrayNotHasKey( 'owa_session_referer', $p );

        $s = $this->cookieValue( 'owa_s_' . self::SITE );
        $this->assertSame( $p['owa_session_id'], $s['sid'] );
        $this->assertArrayNotHasKey( 'cv2', $s );
    }

    public function testSessionCookieIsNotWrittenWhenLogRequestFails(): void {

        $this->transport->queue( new Response( 503 ) );
        $tracker = $this->tracker();

        $this->assertFalse( $tracker->trackPageView() );
        $this->assertNull( $this->cookieValue( 'owa_s_' . self::SITE ) );
        $this->assertIsArray( $this->cookieValue( 'owa_v' ) );
        $this->assertSame( [ 'HTTP 503' ], $tracker->getLastErrors() );
    }

    public function testRedirectFromPostIsAccepted(): void {

        $this->transport->queue( new Response( 302 ) );

        $this->assertTrue( $this->tracker()->trackPageView() );
    }

    public function testSessionCookieIsNotWrittenOnTransportFailure(): void {

        $this->transport->queue( new Response( 0, '', [], 'Connection refused' ) );
        $tracker = $this->tracker();

        $this->assertFalse( @$tracker->trackPageView() );
        $this->assertNull( $this->cookieValue( 'owa_s_' . self::SITE ) );
        $this->assertSame( [ 'Connection refused' ], $tracker->getLastErrors() );
    }

    public function testMigratesLegacySessionCookieWrittenBySdk10(): void {

        $now = time();
        $hash = State::crc32AsHex( '.example.com' );
        $legacy = rawurlencode( "sid=>1600000000111222333|||last_req=>" . ( $now - 30 ) . "|||cdh=>$hash" );

        $tracker = $this->tracker( [ 'owa_s' => [ $legacy ] ] );
        $tracker->trackPageView();

        $this->assertSame( '1600000000111222333', $this->transport->lastParams()['owa_session_id'] );
        $this->assertSame( '', $this->cookieValue( 'owa_s' ), 'legacy cookie erased' );
        $this->assertSame( '1600000000111222333', $this->cookieValue( 'owa_s_' . self::SITE )['sid'] );
    }

    public function testCollapsesLegacySessionCustomVarStore(): void {

        $now = time();
        $tracker = $this->tracker( [
            'owa_s_' . self::SITE => [ $this->jsCookie( [ 'sid' => '1700000000987654321', 'last_req' => $now - 30 ] ) ],
            'owa_b' => [ $this->jsCookie( [ 'cv3' => 'tier=gold' ] ) ],
        ] );

        $tracker->trackPageView();

        $this->assertSame( 'tier=gold', $this->transport->lastParams()['owa_cv3'] );
        $this->assertSame( '', $this->cookieValue( 'owa_b' ) );
    }

    public function testIgnoresCookiesHashedToAnotherDomain(): void {

        $now = time();
        $tracker = $this->tracker( [
            'owa_v' => [ $this->jsCookie( [ 'vid' => '1700000000123456789' ], '.other.org' ) ],
        ] );

        $tracker->trackPageView();

        $this->assertNotSame( '1700000000123456789', $this->transport->lastParams()['owa_visitor_id'] );
    }

    public function testCampaignTouchIsRecordedAndSent(): void {

        $_SERVER['REQUEST_URI'] = '/?OWA_MEDIUM=email&owa_campaign=fall&owa_source=newsletter&owa_ad=banner1';

        $tracker = $this->tracker();
        $tracker->trackPageView();

        $p = $this->transport->lastParams();
        $touch = [ 'md' => 'email', 'cn' => 'fall', 'sr' => 'newsletter', 'ad' => 'banner1', 'at' => '(not set)' ];

        $this->assertSame( [ $touch ], json_decode( $p['owa_attribs'], true ) );
        $this->assertSame( [ $touch ], $this->cookieValue( 'owa_c' )['attribs'] );
        $this->assertEqualsWithDelta( time() + 60 * 86400, $this->lastWrite( 'owa_c' )['options']['expires'], 5 );
    }

    public function testDirectAttributionKeepsFiveMostRecentTouches(): void {

        $_SERVER['REQUEST_URI'] = '/?owa_medium=cpc';

        $prior = [];

        for ( $i = 1; $i <= 5; $i++ ) {

            $prior[] = [ 'md' => "m$i" ];
        }

        $tracker = $this->tracker( [ 'owa_c' => [ $this->jsCookie( [ 'attribs' => $prior ] ) ] ] );
        $tracker->trackPageView();

        $attribs = $this->cookieValue( 'owa_c' )['attribs'];

        $this->assertCount( 5, $attribs );
        $this->assertSame( 'm2', $attribs[0]['md'] );
        $this->assertSame( 'cpc', $attribs[4]['md'] );
    }

    public function testOriginalAttributionKeepsFirstTouch(): void {

        $_SERVER['REQUEST_URI'] = '/?owa_medium=cpc';

        $tracker = $this->tracker(
            [ 'owa_c' => [ $this->jsCookie( [ 'attribs' => [ [ 'md' => 'email' ] ] ] ) ] ],
            [ 'trafficAttributionMode' => 'original' ]
        );
        $tracker->trackPageView();

        $this->assertNull( $this->cookieValue( 'owa_c' ), 'history untouched' );
        $this->assertSame( [ [ 'md' => 'email' ] ], json_decode( $this->transport->lastParams()['owa_attribs'], true ) );
    }

    public function testLegacyCampaignTouchesAreReadUnderCurrentKeys(): void {

        $tracker = $this->tracker( [ 'owa_c' => [ $this->jsCookie( [ 'attribs' => [
            [ 'medium' => 'email', 'campaign' => 'spring', 'ts' => 1600000000 ],
        ] ] ) ] ] );
        $tracker->trackPageView();

        $this->assertSame( [ [ 'md' => 'email', 'cn' => 'spring' ] ], json_decode( $this->transport->lastParams()['owa_attribs'], true ) );
    }

    public function testForwardsVisitorHeaders(): void {

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';

        $this->tracker()->trackPageView();
        $h = $this->transport->last()['headers'];

        $this->assertSame( 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0', $h['User-Agent'] );
        $this->assertSame( '198.51.100.7, 203.0.113.9', $h['X-Forwarded-For'] );
        $this->assertSame( 'de-DE,de;q=0.9', $h['Accept-Language'] );
    }

    public function testExplicitIpAndUserAgentWin(): void {

        $tracker = $this->tracker();
        $tracker->setIpAddress( '192.0.2.44' );
        $tracker->setUserAgent( 'Custom/1.0' );
        $tracker->trackPageView();

        $h = $this->transport->last()['headers'];

        $this->assertSame( '192.0.2.44', $h['X-Forwarded-For'] );
        $this->assertSame( 'Custom/1.0', $h['User-Agent'] );
    }

    public function testStatelessEventDoesNotForwardRequestIdentity(): void {

        $tracker = $this->tracker();
        $tracker->addTransaction( 'order-9', 'web', 10.5, 1, 2, 'stripe', 'DE', 'BE', 'Berlin', '', '1700000000987654321' );
        $tracker->trackTransaction();

        $req = $this->transport->last();
        $p = $this->transport->lastParams();

        $this->assertSame( TrackerClient::USER_AGENT, $req['headers']['User-Agent'] );
        $this->assertArrayNotHasKey( 'X-Forwarded-For', $req['headers'] );
        $this->assertSame( '1700000000987654321', $p['owa_original_session_id'] );
        $this->assertArrayNotHasKey( 'owa_visitor_id', $p );
        $this->assertSame( [], $this->written, 'stateless tracking sets no cookies' );
    }

    public function testTransactionUsesCtAddressFieldsAndNestedLineItems(): void {

        $tracker = $this->tracker();
        $tracker->addTransaction( 1001, 'web', '25.00', 0, 5, 'paypal', 'US', 'CA', 'Oakland' );
        $tracker->addTransactionLineItem( 1001, 'SKU-1', 'Mug', 'kitchen', 10, 2 );
        $tracker->trackTransaction();

        $p = $this->transport->lastParams();

        $this->assertSame( 'ecommerce.transaction', $p['owa_event_type'] );
        $this->assertSame( 'US', $p['owa_ct_country'] );
        $this->assertSame( 'CA', $p['owa_ct_state'] );
        $this->assertSame( 'Oakland', $p['owa_ct_city'] );
        $this->assertArrayNotHasKey( 'owa_country', $p );
        $this->assertSame( 'SKU-1', $p['owa_ct_line_items'][0]['li_sku'] );
        $this->assertSame( '2', $p['owa_ct_line_items'][0]['li_quantity'] );
        $this->assertArrayHasKey( 'owa_session_id', $p );
    }

    public function testRespectsDoNotTrack(): void {

        $_SERVER['HTTP_DNT'] = '1';
        $tracker = $this->tracker();

        $this->assertFalse( $tracker->trackPageView() );
        $this->assertSame( [], $this->transport->requests );
        $this->assertSame( [], $this->written );
    }

    public function testDoNotTrackCanBeIgnored(): void {

        $_SERVER['HTTP_DNT'] = '1';

        $this->assertTrue( $this->tracker( [], [ 'respect_do_not_track' => false ] )->trackPageView() );
    }

    public function testNoSiteIdSendsNothing(): void {

        $tracker = $this->tracker();
        $tracker->setSiteId( '' );

        $this->assertFalse( $tracker->trackPageView() );
        $this->assertSame( [], $this->transport->requests );
    }

    public function testLongPayloadIsPosted(): void {

        $tracker = $this->tracker();
        $tracker->setProperty( 'notes', str_repeat( 'x', 2500 ) );
        $tracker->trackPageView();

        $req = $this->transport->last();

        $this->assertSame( 'POST', $req['method'] );
        $this->assertSame( 'https://owa.example.com/owa/log.php', $req['url'] );
        $this->assertSame( 'application/x-www-form-urlencoded', $req['headers']['Content-Type'] );
        $this->assertSame( 'base.page_request', $this->transport->lastParams()['owa_event_type'] );
    }

    public function testSecondEventOnSamePageSharesSessionAndSpendsOneShotFlags(): void {

        $tracker = $this->tracker();
        $tracker->trackPageView();
        $first = $this->transport->lastParams();

        $tracker->trackAction( 'signup', 'forms', 'footer', 1 );
        $second = $this->transport->lastParams();

        $this->assertSame( 'track.action', $second['owa_event_type'] );
        $this->assertSame( 'signup', $second['owa_action_name'] );
        $this->assertSame( $first['owa_session_id'], $second['owa_session_id'] );
        $this->assertSame( $first['owa_visitor_id'], $second['owa_visitor_id'] );
        $this->assertArrayNotHasKey( 'owa_is_new_session_start', $second );
        $this->assertArrayNotHasKey( 'owa_is_new_visitor_created', $second );
        // page state rides every event of the page, as in the JS tracker
        $this->assertSame( 'true', $second['owa_is_new_session'] );
    }

    public function testEventPropertyBeatsGlobalBeatsState(): void {

        $tracker = $this->tracker();
        $tracker->setProperty( 'page_url', 'https://www.example.com/global' );
        $tracker->setProperty( 'session_id', 'from-global' );

        $event = new TrackingEvent();
        $event->set( 'page_url', 'https://www.example.com/event' );
        $tracker->trackPageView( $event );

        $p = $this->transport->lastParams();

        $this->assertSame( 'https://www.example.com/event', $p['owa_page_url'] );
        $this->assertSame( 'from-global', $p['owa_session_id'] );
    }

    public function testCustomVariableScopes(): void {

        $tracker = $this->tracker();
        $tracker->setCustomVar( 1, 'section', 'shop' );
        $tracker->setCustomVar( 2, 'plan', 'pro', 'session' );
        $tracker->setCustomVar( 3, 'cohort', 'b', 'visitor' );
        $tracker->setCustomVar( 4, str_repeat( 'k', 40 ), str_repeat( 'v', 30 ) );
        $tracker->trackPageView();

        $p = $this->transport->lastParams();

        $this->assertSame( 'section=shop', $p['owa_cv1'] );
        $this->assertSame( 'plan=pro', $p['owa_cv2'] );
        $this->assertSame( 'cohort=b', $p['owa_cv3'] );
        $this->assertArrayNotHasKey( 'owa_cv4', $p, 'over 65 characters is dropped' );

        $this->assertSame( 'plan=pro', $this->cookieValue( 'owa_s_' . self::SITE )['cv2'] );
        $this->assertSame( 'cohort=b', $this->cookieValue( 'owa_v' )['cv3'] );
        $this->assertArrayNotHasKey( 'cv1', $this->cookieValue( 'owa_v' ) );
    }

    public function testUserNameIsStoredOnVisitor(): void {

        $tracker = $this->tracker();
        $tracker->setUserName( 'member-17' );
        $tracker->trackPageView();

        $this->assertSame( 'member-17', $this->transport->lastParams()['owa_user_name'] );
        $this->assertSame( 'member-17', $this->cookieValue( 'owa_v' )['user_name'] );
    }

    public function testCookiesAreSecureOnHttps(): void {

        $this->tracker()->trackPageView();

        $this->assertTrue( $this->lastWrite( 'owa_v' )['options']['secure'] );
        $this->assertSame( '.example.com', $this->lastWrite( 'owa_v' )['options']['domain'] );
    }

    public function testRunsWithoutWebServerVariables(): void {

        $_SERVER = [];

        $this->assertTrue( $this->tracker()->trackPageView() );
        $this->assertSame( TrackerClient::USER_AGENT, $this->transport->last()['headers']['User-Agent'] );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function cookieDomains(): array {

        return [
            'host, www stripped'     => [ '', 'www.example.com', '.example.com' ],
            'host with port'         => [ '', 'example.com:8080', '.example.com' ],
            'explicit keeps www'     => [ 'www.example.com', 'ignored.test', '.www.example.com' ],
            'explicit leading dot'   => [ '.example.com', '', '.example.com' ],
            'nothing to resolve'     => [ '', '', '' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'cookieDomains' )]
    public function testResolveCookieDomain( string $domain, string $host, string $expected ): void {

        $this->assertSame( $expected, TrackerClient::resolveCookieDomain( $domain, $host ) );
    }

    private function lastWrite( string $name ): array {

        foreach ( array_reverse( $this->written ) as $w ) {

            if ( $w['name'] === $name ) {

                return $w;
            }
        }

        $this->fail( "$name was never written" );
    }
}

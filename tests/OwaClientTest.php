<?php

namespace OwaSdk\Tests;

use OwaSdk\Http\Response;
use OwaSdk\OwaClient;
use OwaSdk\Sites\SitesClient;
use OwaSdk\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

class OwaClientTest extends TestCase {

    const API_KEY = '0123456789abcdef0123456789abcdef';
    const AUTH_KEY = 'install-auth-key';

    private FakeTransport $transport;

    private string $tmp = '';

    protected function setUp(): void {

        $this->transport = new FakeTransport();
    }

    protected function tearDown(): void {

        putenv( 'OWA_API_KEY' );
        putenv( 'OWA_AUTH_KEY' );

        if ( $this->tmp && is_dir( $this->tmp ) ) {

            exec( 'rm -rf ' . escapeshellarg( $this->tmp ) );
        }
    }

    private function sites( array $config = [] ): SitesClient {

        return new SitesClient( $config + [
            'instance_url'  => 'https://owa.example.com/owa',
            'credentials'   => [ 'api_key' => self::API_KEY, 'auth_key' => self::AUTH_KEY ],
            'transport'     => $this->transport,
        ] );
    }

    /**
     * The server's formula, Auth.php in OWA 1.14.
     */
    private static function expectedSignature( string $url ): string {

        return base64_encode( hash( 'sha256', 'OWASIGNATURE' . self::API_KEY . $url . gmdate( 'Ymd' ) . self::AUTH_KEY ) );
    }

    public function testListSitesSendsSignedRequestAndReturnsData(): void {

        $this->transport->queue( new Response( 200, json_encode( [ 'requestId' => 'r1', 'data' => [ [ 'site_id' => 'abc' ] ] ] ) ) );

        $sites = $this->sites()->listSites();

        $req = $this->transport->last();

        $this->assertSame( 'GET', $req['method'] );
        $this->assertSame( 'https://owa.example.com/owa/api/base/v1/sites', $req['url'] );
        $this->assertSame( self::API_KEY, $req['headers']['X-API-KEY'] );
        $this->assertSame( self::expectedSignature( $req['url'] ), $req['headers']['X-SIGNATURE'] );
        $this->assertSame( [ [ 'site_id' => 'abc' ] ], $sites );
    }

    public function testSignatureCoversTheUrlExactlyAsSent(): void {

        $client = $this->sites();
        $client->makeRequest( [ 'http_method' => 'GET', 'uri' => '/base/v1/reports/x', 'query' => [ 'label' => 'two words~' ] ] );

        $req = $this->transport->last();

        // RFC 3986: the 1.0.x SDK signed '+' and Guzzle sent '%20', so the signature never matched
        $this->assertSame( 'https://owa.example.com/owa/api/base/v1/reports/x?label=two%20words~', $req['url'] );
        $this->assertSame( self::expectedSignature( $req['url'] ), $req['headers']['X-SIGNATURE'] );
    }

    public function testGenerateRequestSignatureAcceptsRequestArray(): void {

        $client = $this->sites();
        $url = 'https://owa.example.com/owa/api/base/v1/sites';

        $this->assertSame( self::expectedSignature( $url ), $client->generateRequestSignature( [ 'uri' => '/base/v1/sites' ] ) );
        $this->assertSame( self::expectedSignature( $url ), $client->generateRequestSignature( $url ) );
    }

    public function testAddSitePostsNamespacedForm(): void {

        $this->transport->queue( new Response( 201, json_encode( [ 'data' => [ 'site_id' => 'new' ] ] ) ) );

        $site = $this->sites()->addSite( [ 'domain' => 'https://shop.example.com', 'name' => 'Shop', 'streamType' => 'web' ] );

        $req = $this->transport->last();
        parse_str( $req['body'], $body );

        $this->assertSame( 'POST', $req['method'] );
        $this->assertSame( 'application/x-www-form-urlencoded', $req['headers']['Content-Type'] );
        $this->assertSame( [ 'owa_domain' => 'https://shop.example.com', 'owa_name' => 'Shop', 'owa_description' => '', 'owa_site_family' => '', 'owa_streamType' => 'web' ], $body );
        $this->assertSame( [ 'site_id' => 'new' ], $site );
    }

    public function testValidationErrorsAreReturnedNotThrown(): void {

        $this->transport->queue( new Response( 422, json_encode( [
            'data'  => null,
            'error' => [ 'validation_errors' => [ 'name' => 'Name is required.' ] ],
        ] ) ) );

        $client = $this->sites();

        $this->assertNull( $client->addSite( [ 'domain' => 'x.example.com' ] ) );
        $this->assertSame( [ 'Name is required.' ], $client->getLastErrors() );
        $this->assertSame( 422, $client->getLastResponse()->status );
    }

    public function testTransportFailureIsReported(): void {

        $this->transport->queue( new Response( 0, '', [], 'Could not resolve host' ) );

        $client = $this->sites();

        $this->assertNull( $client->listSites() );
        $this->assertSame( [ 'Could not resolve host' ], $client->getLastErrors() );
    }

    public function testFailureLogCarriesNoCredentialsOrQuery(): void {

        $this->tmp = sys_get_temp_dir() . '/owa-sdk-test-' . bin2hex( random_bytes( 4 ) );
        mkdir( $this->tmp );
        $log = $this->tmp . '/error.log';
        $previous = ini_set( 'error_log', $log );

        try {

            $this->transport->queue( new Response( 401 ) );
            $this->sites()->makeRequest( [ 'http_method' => 'GET', 'uri' => '/base/v1/sites', 'query' => [ 'apiKey' => self::API_KEY ] ] );

        } finally {

            ini_set( 'error_log', (string) $previous );
        }

        $written = (string) file_get_contents( $log );

        $this->assertStringContainsString( 'GET https://owa.example.com/owa/api/base/v1/sites failed: HTTP 401', $written );
        $this->assertStringNotContainsString( self::API_KEY, $written );
        $this->assertStringNotContainsString( 'X-SIGNATURE', $written );
    }

    public function testCredentialsFromEnvironment(): void {

        putenv( 'OWA_API_KEY=' . self::API_KEY );
        putenv( 'OWA_AUTH_KEY=' . self::AUTH_KEY );

        $client = new OwaClient( [ 'transport' => $this->transport ] );

        // 1.0.x copied the api key into auth_key here
        $this->assertSame( [ 'api_key' => self::API_KEY, 'auth_key' => self::AUTH_KEY ], $client->getCredentials() );
    }

    /**
     * 1.0.x read [defaults]; its wiki documented [default].
     */
    #[\PHPUnit\Framework\Attributes\TestWith( [ '[defaults]' ] )]
    #[\PHPUnit\Framework\Attributes\TestWith( [ '[default]' ] )]
    #[\PHPUnit\Framework\Attributes\TestWith( [ '' ] )]
    public function testCredentialsFromFile( string $section ): void {

        $this->tmp = sys_get_temp_dir() . '/owa-sdk-test-' . bin2hex( random_bytes( 4 ) );
        mkdir( $this->tmp . '/.owa', 0700, true );
        file_put_contents( $this->tmp . '/.owa/credentials', "$section\napi_key = " . self::API_KEY . "\nauth_key = " . self::AUTH_KEY . "\n" );

        $home = getenv( 'HOME' );
        putenv( 'HOME=' . $this->tmp );

        try {

            $creds = ( new OwaClient( [] ) )->getCredentials();

        } finally {

            putenv( $home === false ? 'HOME' : 'HOME=' . $home );
        }

        $this->assertSame( [ 'api_key' => self::API_KEY, 'auth_key' => self::AUTH_KEY ], $creds );
    }

    public function testInstanceUrlGetsTrailingSlash(): void {

        $this->assertSame( 'https://owa.example.com/owa/', OwaClient::normalizeInstanceUrl( 'https://owa.example.com/owa' ) );
        $this->assertSame( 'https://owa.example.com/owa/', OwaClient::normalizeInstanceUrl( 'https://owa.example.com/owa/' ) );
        $this->assertSame( '', OwaClient::normalizeInstanceUrl( '' ) );
    }
}

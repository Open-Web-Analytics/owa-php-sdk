<?php

namespace OwaSdk\Tests;

use OwaSdk\Sites\SitesClient;
use OwaSdk\Tracker\TrackerClient;
use OwaSdk\sdk;
use PHPUnit\Framework\TestCase;

class SdkTest extends TestCase {

    protected function tearDown(): void {

        sdk::$inDebug = false;
        sdk::$debugLog = '';
    }

    public function testCreatesClientsWithMergedConfig(): void {

        $sdk = new sdk( [ 'instance_url' => 'https://owa.example.com/owa', 'cookie_domain' => 'example.com' ] );

        $tracker = $sdk->createTracker( [ 'session_length' => 600 ] );
        $sites = $sdk->createSites();

        $this->assertInstanceOf( TrackerClient::class, $tracker );
        $this->assertInstanceOf( SitesClient::class, $sites );
        $this->assertSame( 'https://owa.example.com/owa/', $tracker->getSetting( 'instance_url' ) );
        $this->assertSame( 600, $tracker->getSetting( 'session_length' ) );
        $this->assertSame( '.example.com', $tracker->getSetting( 'cookie_domain' ) );
    }

    public function testUnknownMethodThrows(): void {

        $this->expectException( \BadMethodCallException::class );

        ( new sdk() )->fetchSomething();
    }

    public function testUnknownServiceThrows(): void {

        $this->expectException( \InvalidArgumentException::class );

        ( new sdk() )->createReports();
    }

    public function testDebugWritesToConfiguredLogWithFormatting(): void {

        $log = sys_get_temp_dir() . '/owa-sdk-debug-' . bin2hex( random_bytes( 4 ) ) . '.log';

        try {

            new sdk( [ 'debug' => true, 'debug_log' => $log ] );
            sdk::debug( 'store %s has %d keys', 'v', 3 );

            $this->assertSame( "store v has 3 keys\n", file_get_contents( $log ) );

        } finally {

            @unlink( $log );
        }
    }

    public function testDebugDoesNotChangeHostErrorSettings(): void {

        $before = [ ini_get( 'display_errors' ), error_reporting() ];

        new sdk( [ 'debug' => true, 'debug_log' => '/dev/null' ] );

        $this->assertSame( $before, [ ini_get( 'display_errors' ), error_reporting() ] );
    }
}

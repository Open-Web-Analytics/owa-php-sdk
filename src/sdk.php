<?php

/**
 * Open Web Analytics PHP SDK
 *
 */

namespace OwaSdk;

/**
 * SDK entry point and client factory.
 *
 * `$sdk->createTracker()` and `$sdk->createSites()` resolve through the
 * service manifest in data/manifest.php.
 *
 * @method \OwaSdk\Tracker\TrackerClient createTracker(array $config = [])
 * @method \OwaSdk\Sites\SitesClient createSites(array $config = [])
 */
class sdk {

    const VERSION = '1.1.1';

    private array $config;

    public static bool $inDebug = false;

    /**
     * Destination for debug messages. Empty sends them to PHP's error_log.
     */
    public static string $debugLog = '';

    public function __construct( array $config = [] ) {

        $this->config = $config;

        self::$inDebug = (bool) $this->getSetting( 'debug' );
        self::$debugLog = (string) ( $this->getSetting( 'debug_log' ) ?? '' );
    }

    public function getSetting( string $name ): mixed {

        return $this->config[ $name ] ?? null;
    }

    public function __call( string $name, array $args ): OwaClient {

        $config = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : [];

        if ( str_starts_with( $name, 'create' ) ) {

            return $this->createClient( substr( $name, 6 ), $config );
        }

        throw new \BadMethodCallException( "Unknown method: {$name}." );
    }

    public function createClient( string $name, array $config = [] ): OwaClient {

        // Lookup service in the manifest
        $service = $this->lookupService( $name );

        if ( ! $service ) {

            throw new \InvalidArgumentException( "Unknown service: {$name}." );
        }

        $namespace = $service['namespace'];

        // Create the client
        $client = "OwaSdk\\{$namespace}\\{$namespace}Client";

        return new $client( $this->addToConfig( $service, $config ) );
    }

    private function lookupService( string $name ): ?array {

        static $manifest;

        if ( empty( $manifest ) ) {

            $manifest = include __DIR__ . '/data/manifest.php';
        }

        return $manifest[ strtolower( $name ) ] ?? null;
    }

    private function addToConfig( array $service, array $config ): array {

        if ( isset( $service['namespace'] ) ) {

            $config['namespace'] = $service['namespace'];
        }

        return $config + $this->config;
    }

    /**
     * Writes a debug message when the SDK was created with 'debug' => true.
     *
     * Extra arguments are applied to $msg with sprintf().
     */
    public static function debug( mixed $msg, mixed ...$args ): void {

        if ( ! self::$inDebug ) {

            return;
        }

        if ( is_object( $msg ) || is_array( $msg ) ) {

            $msg = print_r( $msg, true );

        } elseif ( $args ) {

            $msg = vsprintf( (string) $msg, array_map( fn( $a ) => is_scalar( $a ) || $a === null ? $a : print_r( $a, true ), $args ) );
        }

        if ( self::$debugLog ) {

            error_log( $msg . "\n", 3, self::$debugLog );

        } else {

            error_log( 'OWA SDK: ' . $msg );
        }
    }
}

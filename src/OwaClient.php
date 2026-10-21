<?php

namespace OwaSdk;

use OwaSdk\Http\CurlTransport;
use OwaSdk\Http\Response;
use OwaSdk\Http\StreamTransport;
use OwaSdk\Http\Transport;

class OwaClient {

    const USER_AGENT = 'OWA SDK Client';

    /** @var array<string, mixed> */
    public array $config = [];

    public ?Response $last_response = null;

    /**
     * Errors from the last request: a transport failure, or the messages in
     * the REST envelope's 'error' member.
     *
     * @var list<string>
     */
    public array $last_errors = [];

    private ?Transport $transport = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct( array $config = [] ) {

        $this->config = [

            'instance_url'      => '',
            'ns'                => 'owa_',
            'endpoint'          => 'api',
            'credentials'       => [],
            'debug'             => false,
            'timeout'           => 5,
            'connect_timeout'   => 2,
            // an OwaSdk\Http\Transport; null picks curl, or streams without ext-curl
            'transport'         => null,
        ];

        // override default config with config array passed in.
        $this->config = array_merge( $this->config, $config );

        $this->config['instance_url'] = self::normalizeInstanceUrl( (string) $this->config['instance_url'] );

        if ( $this->config['transport'] instanceof Transport ) {

            $this->transport = $this->config['transport'];
        }
    }

    public function getSetting( string $name ): mixed {

        return $this->config[ $name ] ?? null;
    }

    public function setSetting( string $name, mixed $value ): void {

        $this->config[ $name ] = $value;
    }

    /**
     * Credentials for the REST API.
     *
     * api_key is the user's API key (their profile screen in OWA). auth_key is
     * the install's OWA_AUTH_KEY constant from owa-config.php.
     *
     * Looked up in order: the 'credentials' config array, the OWA_API_KEY /
     * OWA_AUTH_KEY environment variables, constants of the same names, then
     * the [defaults] or [default] section of ~/.owa/credentials.
     *
     * @return array{api_key: string, auth_key: string}
     */
    public function getCredentials(): array {

        $credentials = $this->config['credentials'];

        if ( is_array( $credentials ) && ! empty( $credentials['api_key'] ) && ! empty( $credentials['auth_key'] ) ) {

            return [ 'api_key' => (string) $credentials['api_key'], 'auth_key' => (string) $credentials['auth_key'] ];
        }

        $credentials = [
            'api_key'  => self::envOrConstant( 'OWA_API_KEY' ),
            'auth_key' => self::envOrConstant( 'OWA_AUTH_KEY' ),
        ];

        if ( ! $credentials['api_key'] && ! $credentials['auth_key'] ) {

            $credentials = $this->readCredentialsFile() ?? $credentials;
        }

        $this->config['credentials'] = $credentials;

        return $credentials;
    }

    private static function envOrConstant( string $name ): string {

        $value = getenv( $name );

        if ( $value !== false && $value !== '' ) {

            return $value;
        }

        return defined( $name ) ? (string) constant( $name ) : '';
    }

    /**
     * @return array{api_key: string, auth_key: string}|null
     */
    private function readCredentialsFile(): ?array {

        $home = $this->getHomeDirectory();

        if ( ! $home ) {

            return null;
        }

        $file = $home . '/.owa/credentials';

        if ( ! is_readable( $file ) ) {

            return null;
        }

        $ini = parse_ini_file( $file, true, INI_SCANNER_RAW );

        // [defaults] (what 1.0.x read), [default] (what its docs said), or bare keys
        $section = is_array( $ini ) ? ( $ini['defaults'] ?? $ini['default'] ?? $ini ) : null;

        if ( is_array( $section ) && isset( $section['api_key'], $section['auth_key'] ) ) {

            return [ 'api_key' => (string) $section['api_key'], 'auth_key' => (string) $section['auth_key'] ];
        }

        return null;
    }

    private function getHomeDirectory(): ?string {

        // On Unix systems, use the HOME environment variable
        if ( $home = getenv( 'HOME' ) ) {

            return $home;
        }

        // Get the HOMEDRIVE and HOMEPATH values for Windows hosts
        $home_drive = getenv( 'HOMEDRIVE' );
        $home_path = getenv( 'HOMEPATH' );

        return ( $home_drive && $home_path ) ? $home_drive . $home_path : null;
    }

    public function getHttpClient(): Transport {

        if ( ! $this->transport ) {

            $this->transport = extension_loaded( 'curl' ) ? new CurlTransport() : new StreamTransport();
        }

        return $this->transport;
    }

    /**
     * Full URL of a REST request, exactly as it is sent and signed.
     *
     * @param array{uri: string, query?: array<string, mixed>} $request
     */
    public function buildRequestUrl( array $request ): string {

        $url = $this->getSetting( 'instance_url' ) . $this->getSetting( 'endpoint' ) . $request['uri'];

        if ( ! empty( $request['query'] ) ) {

            $url .= '?' . http_build_query( $request['query'], '', '&', PHP_QUERY_RFC3986 );
        }

        return $url;
    }

    /**
     * The X-SIGNATURE value OWA expects for a URL.
     *
     * base64( sha256_hex( 'OWASIGNATURE' . api_key . url . Ymd(UTC) . auth_key ) ).
     * OWA rebuilds the URL from the scheme, Host and REQUEST_URI it receives, so
     * instance_url must use the same scheme, host and port the server sees.
     *
     * @param string|array{uri: string, query?: array<string, mixed>} $url the URL, or a request array as makeRequest() takes
     * @param array{api_key: string, auth_key: string}|array{} $credentials
     */
    public function generateRequestSignature( string|array $url, array $credentials = [] ): string {

        if ( is_array( $url ) ) {

            $url = $this->buildRequestUrl( $url );
        }

        if ( ! $credentials ) {

            $credentials = $this->getCredentials();
        }

        $date = gmdate( 'Ymd' );

        return base64_encode( hash( 'sha256', 'OWASIGNATURE' . $credentials['api_key'] . $url . $date . $credentials['auth_key'] ) );
    }

    /**
     * Sends a signed REST request.
     *
     * @param array{http_method: string, uri: string, query?: array<string, mixed>, form_params?: array<string, mixed>} $params
     * @return mixed the envelope's 'data' member, or null on failure (see getLastErrors())
     */
    public function makeRequest( array $params ): mixed {

        $this->last_errors = [];

        $url = $this->buildRequestUrl( $params );
        $credentials = $this->getCredentials();

        $headers = [
            'X-API-KEY'     => $credentials['api_key'],
            'X-SIGNATURE'   => $this->generateRequestSignature( $url, $credentials ),
            'User-Agent'    => self::USER_AGENT,
            'Accept'        => 'application/json',
        ];

        $body = null;

        if ( ! empty( $params['form_params'] ) ) {

            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query( $this->ns( $params['form_params'] ), '', '&', PHP_QUERY_RFC3986 );
        }

        $res = $this->send( $params['http_method'], $url, $headers, $body );

        if ( $res->status === 0 ) {

            return null;
        }

        $json = $res->json();

        if ( is_array( $json ) && ! empty( $json['error'] ) ) {

            $this->last_errors = self::flattenErrors( $json['error'] );
        }

        if ( ! $res->isSuccess() ) {

            if ( ! $this->last_errors ) {

                $this->last_errors[] = "HTTP {$res->status}";
            }

            $this->logFailure( $params['http_method'], $url, $res );

            return null;
        }

        return is_array( $json ) && array_key_exists( 'data', $json ) ? $json['data'] : null;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function send( string $method, string $url, array $headers, ?string $body = null ): Response {

        $res = $this->getHttpClient()->send( $method, $url, $headers, $body, [
            'timeout'         => (float) $this->getSetting( 'timeout' ),
            'connect_timeout' => (float) $this->getSetting( 'connect_timeout' ),
        ] );

        $this->last_response = $res;

        if ( $res->status === 0 ) {

            $this->last_errors = [ $res->error ];
            $this->logFailure( $method, $url, $res );
        }

        return $res;
    }

    /**
     * Logs a failed request by method and path only: headers carry
     * credentials and a log.php query string carries visitor data.
     */
    protected function logFailure( string $method, string $url, Response $res ): void {

        $what = $res->status === 0 ? $res->error : "HTTP {$res->status}";

        error_log( sprintf( 'OWA SDK: %s %s failed: %s', $method, strtok( $url, '?' ), $what ) );

        sdk::debug( 'Response body: %s', $res->body );
    }

    /**
     * @return list<string>
     */
    private static function flattenErrors( mixed $error ): array {

        $out = [];

        array_walk_recursive( $error, function ( $v ) use ( &$out ) {
            if ( is_scalar( $v ) && (string) $v !== '' ) {
                $out[] = (string) $v;
            }
        } );

        return $out;
    }

    public function getLastResponse(): ?Response {

        return $this->last_response;
    }

    /**
     * @return list<string>
     */
    public function getLastErrors(): array {

        return $this->last_errors;
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function setDefaultParams( array $defaults, array $params ): array {

        return array_merge( $defaults, array_filter( $params, fn( $v ) => $v !== null && $v !== '' ) );
    }

    /**
     * Prefixes a name, or every key of an array, with the namespace.
     *
     * @param array<string, mixed>|string $value
     * @return array<string, mixed>|string
     */
    public function ns( array|string $value ): array|string {

        $ns = $this->getSetting( 'ns' );

        if ( is_array( $value ) ) {

            $new = [];

            foreach ( $value as $k => $v ) {

                $new[ $ns . $k ] = $v;
            }

            return $new;
        }

        return $ns . trim( $value );
    }

    /**
     * Ensures a trailing slash, so 'https://host/owa' and 'https://host/owa/'
     * both resolve log.php and api/ under the install.
     */
    public static function normalizeInstanceUrl( string $url ): string {

        if ( $url === '' ) {

            return '';
        }

        return rtrim( $url, '/' ) . '/';
    }
}

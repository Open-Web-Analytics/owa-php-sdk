<?php

namespace OwaSdk\Tracker;
use OwaSdk\sdk as sdk;

/**
 * Open Web Analytics - An Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 *
 */

/**
 * State Store Class
 *
 * Reads and writes the same cookies as the OWA 1.14 JavaScript tracker
 * (modules/Base/src/common/StateManager.js), so a visitor tracked by both
 * keeps one visitor id and one session.
 *
 * Cookie contract:
 *   - name: <ns><store>, e.g. owa_v, owa_c, owa_s_<siteId>
 *   - value: JSON object, encoded with JavaScript escape() semantics
 *   - every object carries 'sv' (store version) and 'cdh' (cookie domain hash)
 *   - a cookie with a newer 'sv', or a missing or foreign 'cdh', is ignored
 *   - legacy 'k=>v|||k=>v' values are still read and rewritten as JSON
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 */
class State {

    const STORE_VERSION = 1;

    private array $config;

    /** @var array<string, array<string, mixed>|string> in-memory stores */
    private array $stores = [];

    /** @var array<string, array{days: int|float|null, deferred: bool, persist: bool}> */
    private array $meta = [];

    /** @var array<string, true> stores whose cookie has been merged into memory */
    private array $loaded = [];

    /** @var array<string, list<string>> raw cookie values by full name, in header order */
    private array $cookies;

    /** @var callable(string, string, array): void */
    private $cookieWriter;

    /**
     * @param array $config cookie_domain, ns, cookie_persistence, cookie_secure, hash_cookies_to_domain
     * @param array<string, list<string>|string>|null $cookies raw (still escaped) values by cookie name, one or a list of duplicates; null reads the request
     * @param callable|null $cookieWriter fn( string $name, string $raw_value, array $options ); null uses setrawcookie()
     */
    public function __construct( array $config = [], ?array $cookies = null, ?callable $cookieWriter = null ) {

        $this->config = array_merge( [
            'cookie_domain'          => '',
            'ns'                     => 'owa_',
            'cookie_persistence'     => true,
            'cookie_secure'          => null,
            'hash_cookies_to_domain' => true,
        ], $config );

        $this->cookies = array_map(
            fn( $v ) => is_array( $v ) ? array_values( $v ) : [ (string) $v ],
            $cookies ?? self::readRequestCookies()
        );
        $this->cookieWriter = $cookieWriter ?? [ self::class, 'sendCookie' ];
    }

    public function getSetting( string $name ): mixed {

        return $this->config[ $name ] ?? null;
    }

    public function setSetting( string $name, mixed $value ): void {

        $this->config[ $name ] = $value;
    }

    /**
     * @param int|float|null $days cookie lifetime; null makes a session cookie
     * @param bool $deferred the cookie is not merged on first read and not written on set;
     *                       the owner calls settle() and persist() (the JS session store)
     * @param bool $persist false keeps the store in memory only
     */
    public function registerStore( string $name, int|float|null $days, bool $deferred = false, bool $persist = true ): void {

        $this->meta[ $name ] = [ 'days' => $days, 'deferred' => $deferred, 'persist' => $persist ];
    }

    public function isRegistered( string $name ): bool {

        return isset( $this->meta[ $name ] );
    }

    /**
     * Value of one key, or the whole store when $key is empty. Null when absent.
     */
    public function get( string $store, string $key = '' ): mixed {

        $this->load( $store );

        if ( ! array_key_exists( $store, $this->stores ) ) {

            return null;
        }

        if ( $key === '' ) {

            return $this->stores[ $store ];
        }

        return is_array( $this->stores[ $store ] ) ? ( $this->stores[ $store ][ $key ] ?? null ) : null;
    }

    /**
     * What the cookie holds, without merging it into memory.
     */
    public function getPersisted( string $store, string $key = '' ): mixed {

        $value = $this->readPersisted( $store );

        if ( $key === '' ) {

            return $value;
        }

        return is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
    }

    public function set( string $store, string $key, mixed $value ): void {

        $this->load( $store );

        if ( ! isset( $this->stores[ $store ] ) || ! is_array( $this->stores[ $store ] ) ) {

            $this->stores[ $store ] = [];
        }

        $this->stores[ $store ][ $key ] = $value;

        $this->autoPersist( $store );
    }

    /**
     * Replaces a whole store.
     *
     * @param array<string, mixed>|string $value
     */
    public function replace( string $store, array|string $value ): void {

        $this->loaded[ $store ] = true;
        $this->stores[ $store ] = $value;

        $this->autoPersist( $store );
    }

    /**
     * Removes one key, or the whole store and its cookie when $key is empty.
     */
    public function clear( string $store, string $key = '' ): void {

        $this->load( $store );

        if ( $key === '' ) {

            unset( $this->stores[ $store ] );
            $this->loaded[ $store ] = true;
            $this->erase( $store );
            return;
        }

        if ( isset( $this->stores[ $store ] ) && is_array( $this->stores[ $store ] ) && array_key_exists( $key, $this->stores[ $store ] ) ) {

            unset( $this->stores[ $store ][ $key ] );
            $this->autoPersist( $store );
        }
    }

    /**
     * Settles a deferred store once its owner has decided whether the
     * persisted values still apply.
     *
     * $discard true: the cookie describes something that has ended, so it is
     * erased and only what was set in memory this request is kept.
     * $discard false: the cookie is merged in behind memory; memory wins.
     */
    public function settle( string $store, bool $discard ): void {

        if ( $discard ) {

            $this->loaded[ $store ] = true;
            $this->erase( $store );
            return;
        }

        $this->merge( $store );
    }

    /**
     * Writes a store to its cookie.
     */
    public function persist( string $store ): void {

        $meta = $this->meta[ $store ] ?? null;

        if ( ! $meta || ! $meta['persist'] ) {

            return;
        }

        if ( ! isset( $this->stores[ $store ] ) ) {

            sdk::debug( "Cannot persist state. Store $store is empty." );
            return;
        }

        $value = $this->stores[ $store ];

        if ( is_array( $value ) ) {

            $value = $this->stamp( $value );
            $this->stores[ $store ] = $value;
            $value = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        $this->writeStoreCookie( $store, (string) $value );
    }

    /**
     * Moves a legacy cookie's values into another store's cookie and erases
     * the legacy cookie.
     *
     * $collapse true: the legacy keys are merged over the target's (owa_b into
     * the session store). false: the legacy cookie is copied only when the
     * target has none (owa_s renamed to owa_s_<siteId>).
     */
    public function migrateCookie( string $from, string $to, bool $collapse ): void {

        $legacy = $this->readPersisted( $from );

        if ( ! is_array( $legacy ) ) {

            return;
        }

        $target = $this->readPersisted( $to );

        if ( ! $collapse && $target !== null ) {

            $this->erase( $from );
            return;
        }

        $merged = is_array( $target ) ? $target : [];

        foreach ( $legacy as $k => $v ) {

            if ( $k !== 'cdh' && $k !== 'sv' ) {

                $merged[ $k ] = $v;
            }
        }

        $raw = self::jsEscape( (string) json_encode( $this->stamp( $merged ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

        $this->writeStoreCookie( $to, $raw, false );
        $this->cookies[ $this->getSetting( 'ns' ) . $to ] = [ $raw ];
        $this->erase( $from );

        sdk::debug( 'Migrated legacy store %s into %s.', $from, $to );
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function stamp( array $value ): array {

        $value['sv'] = self::STORE_VERSION;

        if ( $this->getSetting( 'hash_cookies_to_domain' ) ) {

            $value['cdh'] = $this->getCookieDomainHash();
        }

        return $value;
    }

    private function writeStoreCookie( string $store, string $value, bool $escape = true ): void {

        $days = $this->meta[ $store ]['days'] ?? null;
        $expires = 0;

        if ( $days && $this->getSetting( 'cookie_persistence' ) ) {

            $expires = time() + (int) round( $days * 86400 );
        }

        $this->writeCookie( $store, $escape ? self::jsEscape( $value ) : $value, $expires );
    }

    public function erase( string $store ): void {

        $name = $this->getSetting( 'ns' ) . $store;

        // nothing to erase, and erasing would send a needless header
        if ( empty( $this->cookies[ $name ] ) ) {

            return;
        }

        $this->writeCookie( $store, '', time() - 86400 * 2 );

        unset( $this->cookies[ $name ] );
    }

    public function getCookieDomainHash( string $domain = '' ): string {

        if ( ! $domain ) {

            $domain = (string) $this->getSetting( 'cookie_domain' );
        }

        return self::crc32AsHex( $domain );
    }

    /**
     * Unsigned lower-case hex CRC32, as Util.dechex(Util.crc32()) in the JS tracker.
     */
    public static function crc32AsHex( string $string ): string {

        return dechex( crc32( $string ) );
    }

    /**
     * Cookie values by name, keeping duplicates, in header order.
     *
     * Read from the raw Cookie header because the same name can be set on
     * several domains (example.com and .example.com) and $_COOKIE keeps only
     * one, and because $_COOKIE url-decodes '+' to a space, which escape()
     * does not.
     *
     * @return array<string, list<string>>
     */
    public static function readRequestCookies(): array {

        $cookies = [];

        if ( ! empty( $_SERVER['HTTP_COOKIE'] ) && is_string( $_SERVER['HTTP_COOKIE'] ) ) {

            foreach ( explode( ';', $_SERVER['HTTP_COOKIE'] ) as $pair ) {

                $pair = trim( $pair );

                if ( $pair === '' || ! str_contains( $pair, '=' ) ) {

                    continue;
                }

                [ $name, $value ] = explode( '=', $pair, 2 );
                $cookies[ trim( $name ) ][] = trim( $value );
            }

            return $cookies;
        }

        foreach ( $_COOKIE as $name => $value ) {

            if ( is_string( $value ) ) {

                $cookies[ $name ][] = $value;
            }
        }

        return $cookies;
    }

    /**
     * JavaScript escape(): A-Z a-z 0-9 @*_+-./ stay as they are, other code
     * units below 256 become %XX, the rest %uXXXX.
     */
    public static function jsEscape( string $value ): string {

        $out = '';
        $units = unpack( 'n*', (string) iconv( 'UTF-8', 'UTF-16BE//IGNORE', $value ) ) ?: [];

        foreach ( $units as $u ) {

            if ( $u < 128 && preg_match( '#[A-Za-z0-9@*_+\-./]#', chr( $u ) ) ) {

                $out .= chr( $u );

            } elseif ( $u < 256 ) {

                $out .= sprintf( '%%%02X', $u );

            } else {

                $out .= sprintf( '%%u%04X', $u );
            }
        }

        return $out;
    }

    /**
     * JavaScript unescape(): %XX and %uXXXX are code units, '+' stays '+'.
     */
    public static function jsUnescape( string $value ): string {

        if ( ! str_contains( $value, '%' ) ) {

            return $value;
        }

        $utf16 = '';
        $len = strlen( $value );

        for ( $i = 0; $i < $len; $i++ ) {

            $c = $value[ $i ];

            if ( $c === '%' ) {

                if ( $i + 5 < $len && ( $value[ $i + 1 ] === 'u' || $value[ $i + 1 ] === 'U' ) && ctype_xdigit( substr( $value, $i + 2, 4 ) ) ) {

                    $utf16 .= pack( 'n', hexdec( substr( $value, $i + 2, 4 ) ) );
                    $i += 5;
                    continue;
                }

                if ( $i + 2 < $len && ctype_xdigit( substr( $value, $i + 1, 2 ) ) ) {

                    $utf16 .= pack( 'n', hexdec( substr( $value, $i + 1, 2 ) ) );
                    $i += 2;
                    continue;
                }
            }

            $utf16 .= pack( 'n', ord( $c ) );
        }

        return (string) iconv( 'UTF-16BE', 'UTF-8//IGNORE', $utf16 );
    }

    /**
     * Decodes one cookie value: JSON when it starts with '{', else the legacy
     * assoc format, else the plain string.
     *
     * @return array<string, mixed>|string
     */
    public static function decodeValue( string $raw ): array|string {

        $value = self::jsUnescape( $raw );

        if ( str_starts_with( $value, '{' ) ) {

            $decoded = json_decode( $value, true );

            return is_array( $decoded ) ? $decoded : '';
        }

        if ( str_contains( $value, '|||' ) || str_contains( $value, '=>' ) ) {

            $state = [];

            foreach ( explode( '|||', $value ) as $pair ) {

                $parts = explode( '=>', $pair, 2 );

                if ( count( $parts ) === 2 ) {

                    $state[ $parts[0] ] = $parts[1];
                }
            }

            return $state;
        }

        return $value;
    }

    private function load( string $store ): void {

        if ( isset( $this->loaded[ $store ] ) ) {

            return;
        }

        // a deferred store is merged only by settle()
        if ( ! empty( $this->meta[ $store ]['deferred'] ) ) {

            return;
        }

        $this->merge( $store );
    }

    private function merge( string $store ): void {

        $this->loaded[ $store ] = true;

        $persisted = $this->readPersisted( $store );

        if ( $persisted === null ) {

            return;
        }

        $memory = $this->stores[ $store ] ?? null;

        if ( is_array( $persisted ) && ( is_array( $memory ) || $memory === null ) ) {

            $this->stores[ $store ] = array_merge( $persisted, $memory ?? [] );

        } elseif ( $memory === null ) {

            $this->stores[ $store ] = $persisted;
        }
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function readPersisted( string $store ): array|string|null {

        $values = $this->cookies[ $this->getSetting( 'ns' ) . $store ] ?? [];
        $hash = $this->getCookieDomainHash();
        $state = null;

        foreach ( $values as $i => $raw ) {

            $value = self::decodeValue( $raw );

            if ( $value === '' || $value === [] ) {

                continue;
            }

            if ( is_array( $value ) && isset( $value['sv'] ) && (int) $value['sv'] > self::STORE_VERSION ) {

                sdk::debug( 'Cookie %s index %s was written by a newer tracker. Not loading.', $store, $i );
                continue;
            }

            if ( $this->getSetting( 'hash_cookies_to_domain' ) ) {

                if ( is_array( $value ) && isset( $value['cdh'] ) && (string) $value['cdh'] === $hash ) {

                    $state = $value;
                    break;
                }

                sdk::debug( 'Cookie %s index %s has no matching domain hash. Not loading.', $store, $i );
                continue;
            }

            // without domain hashing the last cookie of that name wins
            $state = $value;
        }

        return $state;
    }

    private function autoPersist( string $store ): void {

        $meta = $this->meta[ $store ] ?? null;

        if ( $meta && $meta['persist'] && ! $meta['deferred'] ) {

            $this->persist( $store );
        }
    }

    private function writeCookie( string $store, string $raw_value, int $expires ): void {

        $name = $this->getSetting( 'ns' ) . $store;

        $options = [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => (string) $this->getSetting( 'cookie_domain' ),
            'samesite' => 'Lax',
            'secure'   => $this->getSetting( 'cookie_secure' ) ?? self::isHttps(),
            'httponly' => false,
        ];

        sdk::debug( 'Setting cookie %s with value: %s under domain: %s', $name, $raw_value, $options['domain'] );

        ( $this->cookieWriter )( $name, $raw_value, $options );
    }

    /**
     * Default cookie writer.
     */
    public static function sendCookie( string $name, string $raw_value, array $options ): void {

        if ( headers_sent( $file, $line ) ) {

            sdk::debug( "Cannot set cookie $name: output already started at $file:$line." );
            return;
        }

        setrawcookie( $name, $raw_value, $options );
    }

    private static function isHttps(): bool {

        $https = $_SERVER['HTTPS'] ?? '';

        if ( $https && strtolower( (string) $https ) !== 'off' ) {

            return true;
        }

        return strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) === 'https';
    }
}

<?php

namespace OwaSdk\Tracker;
use OwaSdk\Tracker\State;
use OwaSdk\OwaClient;
use OwaSdk\Tracker\TrackingEvent;
use OwaSdk\sdk as sdk;

/**
 * Open Web Analytics - An Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 *
 */

/**
 * Tracker Class
 *
 * Server-side counterpart of the OWA 1.14 JavaScript tracker
 * (modules/Base/src/tracker/Tracker.js). It derives the same visitor,
 * session and attribution state from the same cookies and sends the same
 * properties to log.php, so a visitor tracked from both PHP and JS keeps one
 * visitor id and one session.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */
class TrackerClient extends OwaClient {

    const LOG_ENDPOINT = 'log.php';

    /**
     * Longer log.php URLs are sent as a POST body, as the JS tracker does.
     */
    const GET_CHARACTER_LIMIT = 2000;

    /**
     * Campaign keys (logical name => key in the owa_c attribution history).
     */
    const CAMPAIGN_KEYS = [
        'medium'        => 'md',
        'campaign'      => 'cn',
        'source'        => 'sr',
        'search_terms'  => 'tr',
        'ad'            => 'ad',
        'ad_type'       => 'at',
    ];

    public State $state;

    public ?TrackingEvent $commerce_event = null;

    public TrackingEvent $pageview_event;

    /** @var array<string, mixed> */
    public array $global_event_properties = [];

    public bool $stateInit = false;

    public string $site_id = '';

    /**
     * Page-scoped state: the JS tracker's 'd' store. Never persisted.
     *
     * @var array<string, mixed>
     */
    private array $page_state = [];

    private bool $isNewSessionFlag = false;

    private bool $pendingSessionStart = false;

    private bool $pendingVisitorCreated = false;

    private ?string $ip_address = null;

    private ?string $user_agent = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct( array $config = [] ) {

        $defaults = [

            'instance_url'                      => '',
            'campaign_params'                   => [
                'campaign'      => 'owa_campaign',
                'medium'        => 'owa_medium',
                'source'        => 'owa_source',
                'search_terms'  => 'owa_search_terms',
                'ad'            => 'owa_ad',
                'ad_type'       => 'owa_ad_type' ],
            'trafficAttributionMode'            => 'direct',
            // lifetime of the owa_c attribution history, in days
            'campaignAttributionWindow'         => 60,
            // inactivity, in seconds, after which the next request starts a new session
            'session_length'                    => 1800,
            'maxCustomVars'                     => 5,
            'max_prior_campaigns'               => 5,
            'ns'                                => 'owa_',
            'cookie_domain'                     => '',
            // false makes every cookie a session cookie
            'cookie_persistence'                => true,
            'hash_cookies_to_domain'            => true,
            // the JS tracker does not run for a browser sending DNT: 1
            'respect_do_not_track'              => true,
            // for tests: raw cookies by name, and a fn( $name, $value, $options ) cookie writer
            'cookies'                           => null,
            'cookie_writer'                     => null,
        ];

        // parent will override the default config with passed values.
        parent::__construct( array_merge( $defaults, $config ) );

        $this->state = new State(
            [
                'cookie_domain'          => self::resolveCookieDomain( (string) $this->getSetting( 'cookie_domain' ), (string) $this->getServerParam( 'HTTP_HOST' ) ),
                'ns'                     => $this->getSetting( 'ns' ),
                'cookie_persistence'     => $this->getSetting( 'cookie_persistence' ),
                'hash_cookies_to_domain' => $this->getSetting( 'hash_cookies_to_domain' ),
            ],
            $this->getSetting( 'cookies' ),
            $this->getSetting( 'cookie_writer' )
        );

        $this->setSetting( 'cookie_domain', $this->state->getSetting( 'cookie_domain' ) );

        $this->state->registerStore( 'v', 364 );
        $this->state->registerStore( 'c', (int) $this->getSetting( 'campaignAttributionWindow' ) );
        // legacy stores, read only to migrate them
        $this->state->registerStore( 's', 364 );
        $this->state->registerStore( 'b', null );

        $this->pageview_event = $this->makeEvent();
        $this->pageview_event->setEventType( 'base.page_request' );
    }

    /**
     * The cookie domain, resolved as the JS tracker's setCookieDomain() does:
     * a leading '.', and 'www.' stripped only when the domain was not passed
     * explicitly.
     */
    public static function resolveCookieDomain( string $domain, string $http_host = '' ): string {

        $explicit = $domain !== '';

        if ( ! $explicit ) {

            // HTTP_HOST may carry a port; document.domain never does
            $domain = (string) preg_replace( '/:\d+$/', '', $http_host );
        }

        $domain = ltrim( $domain, '.' );

        if ( $domain === '' ) {

            return '';
        }

        if ( ! $explicit && str_starts_with( $domain, 'www.' ) ) {

            $domain = substr( $domain, 4 );
        }

        return '.' . $domain;
    }

    public function setPageTitle( string $value ): void {

        $this->page_state['page_title'] = $value;
    }

    public function setPageType( string $value ): void {

        $this->page_state['page_type'] = $value;
    }

    public function setProperty( string $name, mixed $value ): void {

        $this->setGlobalEventProperty( $name, $value );
    }

    /**
     * The visitor's IP address, forwarded to OWA in X-Forwarded-For.
     *
     * OWA ignores an ip_address event property and reads the address from the
     * request headers. Without this the SDK forwards the incoming
     * X-Forwarded-For chain plus REMOTE_ADDR, and OWA takes the leftmost
     * public address, as it would for the JS tracker. Set it when your own
     * proxy setup tells you the real address.
     */
    public function setIpAddress( string $ip ): void {

        $this->ip_address = $ip;
    }

    /**
     * The visitor's user agent, forwarded to OWA as the User-Agent header.
     * Defaults to the incoming request's User-Agent.
     */
    public function setUserAgent( string $user_agent ): void {

        $this->user_agent = $user_agent;
    }

    private function setGlobalEventProperty( string $name, mixed $value ): void {

        $this->global_event_properties[ $name ] = $value;
    }

    private function getGlobalEventProperty( string $name ): mixed {

        return $this->global_event_properties[ $name ] ?? null;
    }

    private function deleteGlobalEventProperty( string $name ): void {

        unset( $this->global_event_properties[ $name ] );
    }

    /**
     * Name of this site's session store: s_<siteId>.
     */
    private function sessionStore(): string {

        return 's_' . $this->getSiteId();
    }

    /**
     * Registers the per-site session store and runs the JS tracker's
     * migrations: legacy owa_s becomes owa_s_<siteId>, and owa_b's
     * session-scoped custom variables are folded into it.
     */
    private function initSessionStore(): void {

        $s = $this->sessionStore();

        if ( $this->state->isRegistered( $s ) ) {

            return;
        }

        $this->state->registerStore( $s, 364, true );
        $this->state->migrateCookie( 's', $s, false );
        $this->state->migrateCookie( 'b', $s, true );
        $this->state->registerStore( 'ss_' . $this->getSiteId(), null );
    }

    /**
     * A value from the pre-1.x ss_<siteId> store: 's' (session id) or 'last_req'.
     */
    private function legacySessionValue( string $key ): mixed {

        return $this->state->get( 'ss_' . $this->getSiteId(), $key );
    }

    private function manageState( TrackingEvent $event ): void {

        if ( ! $this->stateInit ) {

            $this->initSessionStore();

            $now = (int) ( $event->get( 'timestamp' ) ?: time() );

            $this->setVisitorId();
            $this->setFirstSessionTimestamp( $now );
            // the session decision is made against the previous request,
            // before the last-request time is advanced below
            $this->setSessionId( $now );
            $this->setNumberPriorSessions();
            $this->setTrafficAttribution( $event );
            $this->stateInit = true;
        }

        // advanced for every event, so the session timeout measures inactivity
        $this->state->set( $this->sessionStore(), 'last_req', (int) ( $event->get( 'timestamp' ) ?: time() ) );
    }

    private function setVisitorId(): void {

        $visitor_id = $this->state->get( 'v', 'vid' );

        if ( ! $visitor_id ) {

            // very old trackers stored the bare id as the whole cookie
            $old = $this->state->get( 'v' );

            if ( is_string( $old ) && $old !== '' ) {

                $visitor_id = $old;
                $this->state->replace( 'v', [] );
            }
        }

        if ( ! $visitor_id ) {

            $visitor_id = TrackingEvent::generateRandomGuid();

            // session state: this session is the visitor's first
            $this->state->set( $this->sessionStore(), 'is_new_visitor', true );
            $this->pendingVisitorCreated = true;
            sdk::debug( 'Creating new visitor id' );
        }

        $this->state->set( 'v', 'vid', (string) $visitor_id );
    }

    private function setFirstSessionTimestamp( int $now ): void {

        if ( ! $this->state->get( 'v', 'fsts' ) ) {

            $this->state->set( 'v', 'fsts', $now );
        }
    }

    private function setSessionId( int $now ): void {

        $s = $this->sessionStore();

        $previous_request = $this->state->get( $s, 'last_req' )
            ?? $this->state->getPersisted( $s, 'last_req' )
            ?? $this->legacySessionValue( 'last_req' );

        $is_new_session = $this->isNewSession( $now, (int) $previous_request );

        if ( $is_new_session ) {

            $prior_session_start = $this->state->getPersisted( $s, 'sts' );

            if ( $prior_session_start ) {

                $this->state->set( $s, 'psts', $prior_session_start );
            }

            $this->state->set( $s, 'sts', $now );

            $prior_session_id = $this->state->getPersisted( $s, 'sid' ) ?: $this->legacySessionValue( 's' );

            if ( $prior_session_id ) {

                $this->state->set( $s, 'prior_session_id', (string) $prior_session_id );
            }
        }

        // a new session discards what the cookie holds; a continuing one merges it in
        $this->state->settle( $s, $is_new_session );

        if ( $is_new_session ) {

            sdk::debug( 'This request is the start of a new session.' );
            $session_id = TrackingEvent::generateRandomGuid();
            $this->markNewSession();

        } else {

            sdk::debug( 'This request is part of an active session.' );
            $session_id = $this->state->get( $s, 'sid' ) ?: $this->legacySessionValue( 's' );

            // fail-safe: an active session without an id
            if ( ! $session_id ) {

                $session_id = TrackingEvent::generateRandomGuid();
                $this->markNewSession();

                if ( ! $this->state->get( $s, 'sts' ) ) {

                    $this->state->set( $s, 'sts', $now );
                }
            }
        }

        $this->state->set( $s, 'sid', (string) $session_id );

        // The previous request's time, sent as last_req. Captured once: on a
        // later page of the same session it hydrates from the cookie.
        if ( $this->state->get( $s, 'prior_last_req' ) === null ) {

            $this->state->set( $s, 'prior_last_req', $previous_request ?? '' );
        }
    }

    private function markNewSession(): void {

        $this->page_state['is_new_session'] = true;
        $this->pendingSessionStart = true;
        $this->isNewSessionFlag = true;
    }

    public function getSessionId(): ?string {

        $s = $this->sessionStore();
        $sid = $this->state->get( $s, 'sid' ) ?? $this->state->getPersisted( $s, 'sid' );

        return $sid === null ? null : (string) $sid;
    }

    /**
     * Check to see if request is a new or active session
     */
    private function isNewSession( int $timestamp = 0, int $last_req = 0 ): bool {

        if ( ! $timestamp ) {

            $timestamp = time();
        }

        return ( $timestamp - $last_req ) >= (int) $this->getSetting( 'session_length' );
    }

    private function setNumberPriorSessions(): void {

        if ( ! $this->isNewSessionFlag ) {

            return;
        }

        $nps = $this->state->get( 'v', 'nps' );

        // "0" on the first session: a string, as the JS tracker stores it
        $nps = ( $nps === null || $nps === '' ) ? '0' : (int) $nps + 1;

        $this->state->set( 'v', 'nps', $nps );
    }

    /**
     * Tracks Event with Full state and globals
     *
     * This function processes a tracking event by adding full state and global properties to it
     * Typically used by syncronous events such as Pageviews
     *
     * @return bool true when OWA accepted the request
     */
    public function trackEvent( TrackingEvent $event ): bool {

        if ( ! $this->getSiteId() ) {

            sdk::debug( 'No site id set. Call setSiteId() before tracking.' );
            return false;
        }

        if ( $this->getSetting( 'respect_do_not_track' ) && $this->getServerParam( 'HTTP_DNT' ) === '1' ) {

            sdk::debug( 'Not tracking: the browser sent DNT: 1.' );
            return false;
        }

        // set various state properties.
        $this->manageState( $event );

        $event = $this->setAllGlobalEventProperties( $event );

        $accepted = $this->logEvent( $event, true );

        // the session store reaches the cookie only once a request carrying it is accepted
        if ( $accepted ) {

            $this->state->persist( $this->sessionStore() );
        }

        return $accepted;
    }

    /**
     * Tracks Event without state or globals
     *
     * This function processes a raw tracking event without any state or globals added to it
     * Typically used by asyncronous events such as Delayed commerce transactions or programaticly derived events.
     * The current request's IP and user agent are not forwarded, since they are
     * not the visitor's; use setIpAddress() / setUserAgent() to supply them.
     *
     * @return bool true when OWA accepted the request
     */
    public function trackStatelessEvent( TrackingEvent $event ): bool {

        $event->set( 'site_id', $this->getSiteId() );

        // log event to server.
        return $this->logEvent( $event, false );
    }

    /**
     * Add Global properties to Tracking Event
     *
     * Precedence: a property set on the event, then global properties, then
     * page, state and custom variable properties.
     */
    public function setAllGlobalEventProperties( TrackingEvent $event ): TrackingEvent {

        if ( ! $event->get( 'site_id' ) ) {

            $event->set( 'site_id', $this->getSiteId() );
        }

        // one-event flags
        if ( $this->pendingSessionStart ) {

            $this->pendingSessionStart = false;

            if ( ! $event->isSet( 'is_new_session_start' ) ) {

                $event->set( 'is_new_session_start', true );
            }
        }

        if ( $this->pendingVisitorCreated ) {

            $this->pendingVisitorCreated = false;

            if ( ! $event->isSet( 'is_new_visitor_created' ) ) {

                $event->set( 'is_new_visitor_created', true );
            }
        }

        $event->setNewProperties( $this->global_event_properties );
        $event->setNewProperties( $this->collectPageProperties() + $this->collectStateProperties() + $this->collectCustomVars() );

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllEventProperties( TrackingEvent $event ): array {

        $event = $this->setAllGlobalEventProperties( $event );
        return $event->getProperties();
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPageProperties(): array {

        $collected = [
            'page_url'      => $this->getCurrentUrl(),
            'HTTP_REFERER'  => (string) $this->getServerParam( 'HTTP_REFERER' ),
        ];

        foreach ( $this->page_state as $k => $v ) {

            if ( ! preg_match( '/^cv[0-9]+$/', $k ) ) {

                $collected[ $k ] = $v;
            }
        }

        return $collected;
    }

    /**
     * Properties read from the state stores, as the JS tracker's
     * collectStateProperties().
     *
     * @return array<string, mixed>
     */
    private function collectStateProperties(): array {

        if ( ! $this->stateInit ) {

            return [];
        }

        $s = $this->sessionStore();

        $map = [
            [ 'v', 'vid', 'visitor_id' ],
            [ 'v', 'nps', 'nps' ],
            [ $s, 'sid', 'session_id' ],
            [ $s, 'referer', 'session_referer' ],
            [ $s, 'landing_url', 'landing_url' ],
            [ $s, 'prior_session_id', 'prior_session_id' ],
            [ $s, 'is_new_visitor', 'is_new_visitor' ],
            [ $s, 'psts', 'psts' ],
            [ $s, 'sts', 'sts' ],
        ];

        $collected = [];

        foreach ( $map as [ $store, $key, $name ] ) {

            $value = $this->state->get( $store, $key );

            if ( $value !== null && $value !== '' ) {

                $collected[ $name ] = $value;
            }
        }

        // present as '' on a visitor's first request
        $prior_last_req = $this->state->get( $s, 'prior_last_req' );

        if ( $prior_last_req !== null ) {

            $collected['last_req'] = $prior_last_req;
        }

        $attribs = $this->getCampaignState();

        if ( $attribs ) {

            $collected['attribs'] = json_encode( $attribs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        $fsts = $this->state->get( 'v', 'fsts' );

        if ( $fsts ) {

            $collected['fsts'] = $fsts;
        }

        $user_name = $this->state->get( 'v', 'user_name' );

        if ( $user_name ) {

            $collected['user_name'] = $user_name;
        }

        return $collected;
    }

    /**
     * Custom variables from every scope; the narrowest wins.
     *
     * @return array<string, string>
     */
    private function collectCustomVars(): array {

        $collected = [];
        $max = (int) $this->getSetting( 'maxCustomVars' );

        for ( $slot = 1; $slot <= $max; $slot++ ) {

            $value = $this->getCustomVar( $slot );

            if ( $value ) {

                $collected[ 'cv' . $slot ] = $value;
            }
        }

        return $collected;
    }

    public function trackPageview( ?TrackingEvent $event = null ): bool {

        if ( $event ) {

            $event->setEventType( 'base.page_request' );
            $this->pageview_event = $event;
        }

        return $this->trackEvent( $this->pageview_event );
    }

    public function trackSyndicationFeed( string $feed_format = 'rss', string $feed_subscription_id = '' ): bool {

        $event = $this->makeEvent();

        $event->setEventType( 'base.feed_request' );
        $event->set( 'feed_format', $feed_format );
        $event->set( 'feed_subscription_id', $feed_subscription_id );

        // track
        return $this->trackEvent( $event );
    }

    public function trackAction( string $action_name, string $action_group = '', string $action_label = '', int|float|string $numeric_value = 0 ): bool {

        $event = $this->makeEvent();
        $event->setEventType( 'track.action' );
        $event->set( 'action_group', $action_group );
        $event->set( 'action_name', $action_name );
        $event->set( 'action_label', $action_label );
        $event->set( 'numeric_value', $numeric_value );
        $event->set( 'site_id', $this->getSiteId() );
        return $this->trackEvent( $event );
    }

    /**
     * Creates a ecommerce Transaction event
     *
     * Creates a parent commerce.transaction event. $country, $state and $city
     * are the billing address, sent as ct_country / ct_state / ct_city.
     * Pass $session_id to attribute a delayed transaction to the session that
     * placed the order; it is then tracked without the current request's state.
     */
    public function addTransaction(
            string|int $order_id,
            string $order_source = '',
            int|float|string $total = 0,
            int|float|string $tax = 0,
            int|float|string $shipping = 0,
            string $gateway = '',
            string $country = '',
            string $state = '',
            string $city = '',
            string $page_url = '',
            string|int $session_id = ''
        ): void {

        $this->commerce_event = $this->makeEvent();
        $this->commerce_event->setEventType( 'ecommerce.transaction' );
        $this->commerce_event->set( 'ct_order_id', $order_id );
        $this->commerce_event->set( 'ct_order_source', $order_source );
        $this->commerce_event->set( 'ct_total', $total );
        $this->commerce_event->set( 'ct_tax', $tax );
        $this->commerce_event->set( 'ct_shipping', $shipping );
        $this->commerce_event->set( 'ct_gateway', $gateway );
        $this->commerce_event->set( 'ct_line_items', [] );
        $this->commerce_event->set( 'ct_country', $country );
        $this->commerce_event->set( 'ct_state', $state );
        $this->commerce_event->set( 'ct_city', $city );

        if ( $page_url ) {

            $this->commerce_event->set( 'page_url', $page_url );
        }

        if ( $session_id ) {

            $this->commerce_event->set( 'original_session_id', $session_id );
        }
    }

    /**
     * Adds a line item to a commerce transaction
     *
     * Creates and a commerce.line_item event and adds it to the parent transaction event
     */
    public function addTransactionLineItem( string|int $order_id, string|int $sku = '', string $product_name = '', string $category = '', int|float|string $unit_price = 0, int|float|string $quantity = 0 ): void {

        if ( empty( $this->commerce_event ) ) {

            $this->addTransaction( 'none set' );
        }

        $items = $this->commerce_event->get( 'ct_line_items' );
        $items[] = [
            'li_order_id'       => $order_id,
            'li_sku'            => $sku,
            'li_product_name'   => $product_name,
            'li_category'       => $category,
            'li_unit_price'     => $unit_price,
            'li_quantity'       => $quantity,
        ];
        $this->commerce_event->set( 'ct_line_items', $items );
    }

    /**
     * tracks a commerce events
     *
     * Tracks a parent transaction event by sending it to the event queue
     */
    public function trackTransaction(): bool {

        if ( empty( $this->commerce_event ) ) {

            return false;
        }

        $event = $this->commerce_event;
        $this->commerce_event = null;

        if ( $event->get( 'original_session_id' ) ) {

            return $this->trackStatelessEvent( $event );
        }

        return $this->trackEvent( $event );
    }

    public function createSiteId( string $value ): string {

        return md5( $value );
    }

    private function setCampaignKey( string $name, string $key ): void {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $campaign_params[ $name ] = $key;
        $this->setSetting( 'campaign_params', $campaign_params );
    }

    public function setCampaignNameKey( string $key ): void {

        $this->setCampaignKey( 'campaign', $key );
    }

    public function setCampaignMediumKey( string $key ): void {

        $this->setCampaignKey( 'medium', $key );
    }

    public function setCampaignSourceKey( string $key ): void {

        $this->setCampaignKey( 'source', $key );
    }

    public function setCampaignSearchTermsKey( string $key ): void {

        $this->setCampaignKey( 'search_terms', $key );
    }

    public function setCampaignAdKey( string $key ): void {

        $this->setCampaignKey( 'ad', $key );
    }

    public function setCampaignAdTypeKey( string $key ): void {

        $this->setCampaignKey( 'ad_type', $key );
    }

    /**
     * Stored on the visitor, so later requests carry it too.
     */
    public function setUserName( string $value ): void {

        $this->state->set( 'v', 'user_name', $value );
    }

    public function setUserEmail( string $value ): void {

        $this->setGlobalEventProperty( 'user_email', $value );
    }

    /**
     * Campaign parameters on the event or the page URL, under their
     * attribution-history keys (md, cn, sr, tr, ad, at).
     *
     * @return array<string, string>
     */
    public function getCampaignProperties( TrackingEvent $event ): array {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $query = $this->getPageQueryParams();
        $campaign_properties = [];

        foreach ( self::CAMPAIGN_KEYS as $name => $short ) {

            if ( empty( $campaign_params[ $name ] ) ) {

                continue;
            }

            $param = strtolower( $campaign_params[ $name ] );

            //look for property on the event
            $property = $event->get( $param );

            // then on the page URL
            if ( ! $property && isset( $query[ $param ] ) && is_string( $query[ $param ] ) ) {

                $property = $query[ $param ];
            }

            if ( $property !== false && $property !== '' ) {

                $campaign_properties[ $short ] = (string) $property;
            }
        }

        // backfill values for incomplete param combos
        if ( isset( $campaign_properties['at'] ) && ! isset( $campaign_properties['ad'] ) ) {

            $campaign_properties['ad'] = '(not set)';
        }

        if ( isset( $campaign_properties['ad'] ) && ! isset( $campaign_properties['at'] ) ) {

            $campaign_properties['at'] = '(not set)';
        }

        return $campaign_properties;
    }

    /**
     * Query parameters of the page being tracked, keys lower-cased as the
     * JS tracker's parseUrlParams() does.
     *
     * @return array<string, mixed>
     */
    private function getPageQueryParams(): array {

        $url = $this->getGlobalEventProperty( 'page_url' ) ?: $this->getCurrentUrl();
        $query = parse_url( (string) $url, PHP_URL_QUERY );

        if ( ! $query ) {

            return [];
        }

        parse_str( $query, $params );

        return array_change_key_case( $params, CASE_LOWER );
    }

    /**
     * @param array<string, string> $campaign_properties
     */
    private function directAttributionModel( array $campaign_properties ): void {

        if ( ! $campaign_properties ) {

            return;
        }

        $campaign_state = $this->getCampaignState();
        $campaign_state[] = $campaign_properties;

        // drop the oldest touches to make room
        while ( count( $campaign_state ) > (int) $this->getSetting( 'max_prior_campaigns' ) ) {

            array_shift( $campaign_state );
        }

        $this->setCampaignCookie( $campaign_state );
    }

    /**
     * @param array<string, string> $campaign_properties
     */
    private function originalAttributionModel( array $campaign_properties ): void {

        // the original touch was recorded previously; keep it
        if ( $this->getCampaignState() || ! $campaign_properties ) {

            return;
        }

        $this->setCampaignCookie( [ $campaign_properties ] );
    }

    /**
     * Prior campaign touches from the owa_c cookie.
     *
     * Touches written by SDK 1.0.x used long key names and a timestamp; they
     * are read under the current keys.
     *
     * @return list<array<string, mixed>>
     */
    public function getCampaignState(): array {

        $campaign_state = $this->state->get( 'c', 'attribs' );

        if ( ! is_array( $campaign_state ) ) {

            return [];
        }

        $touches = [];

        foreach ( $campaign_state as $touch ) {

            if ( ! is_array( $touch ) ) {

                continue;
            }

            $normalized = [];

            foreach ( $touch as $k => $v ) {

                if ( $k === 'ts' ) {

                    continue;
                }

                $normalized[ self::CAMPAIGN_KEYS[ $k ] ?? $k ] = $v;
            }

            $touches[] = $normalized;
        }

        return $touches;
    }

    private function setTrafficAttribution( TrackingEvent $event ): void {

        $campaign_properties = $this->getCampaignProperties( $event );

        // choose attribution model.
        if ( $this->getSetting( 'trafficAttributionMode' ) === 'original' ) {

            $this->originalAttributionModel( $campaign_properties );

        } else {

            $this->directAttributionModel( $campaign_properties );
        }

        // the server parses campaign tags out of the landing URL
        if ( $this->isNewSessionFlag ) {

            $this->state->set( $this->sessionStore(), 'referer', (string) $this->getServerParam( 'HTTP_REFERER' ) );
            $this->state->set( $this->sessionStore(), 'landing_url', $this->getGlobalEventProperty( 'page_url' ) ?: $this->getCurrentUrl() );
        }
    }

    /**
     * @param list<array<string, mixed>> $values
     */
    public function setCampaignCookie( array $values ): void {

        $this->state->set( 'c', 'attribs', $values );
    }

    // sets cookies domain
    public function setCookieDomain( string $domain ): void {

        if ( $domain !== '' ) {

            $domain = self::resolveCookieDomain( $domain );
            $this->setSetting( 'cookie_domain', $domain );
            $this->state->setSetting( 'cookie_domain', $domain );
        }
    }

    /**
     * Set a custom variable
     *
     * @param    int    $slot   the identifying number for the custom variable. 1-5.
     * @param    string $name   the key of the custom variable.
     * @param    string $value  the value of the varible
     * @param    string $scope  page (the default), session, or visitor
     */
    public function setCustomVar( int $slot, string $name, string $value, string $scope = 'page' ): void {

        $cv_param_name = 'cv' . $slot;
        $cv_param_value = $name . '=' . $value;

        if ( strlen( $cv_param_value ) > 65 ) {

            sdk::debug( 'Custom variable name + value is too large. Must be less than 64 characters.' );
            return;
        }

        switch ( $scope ) {

            case 'session':

                if ( ! $this->getSiteId() ) {

                    sdk::debug( 'Set the site id before setting a session-scoped custom variable.' );
                    return;
                }

                $this->initSessionStore();
                $this->state->set( $this->sessionStore(), $cv_param_name, $cv_param_value );
                unset( $this->page_state[ $cv_param_name ] );
                $this->state->clear( 'b', $cv_param_name );
                break;

            case 'visitor':

                $this->state->set( 'v', $cv_param_name, $cv_param_value );
                unset( $this->page_state[ $cv_param_name ] );

                if ( $this->getSiteId() ) {

                    $this->initSessionStore();
                    $this->state->clear( $this->sessionStore(), $cv_param_name );
                }

                $this->state->clear( 'b', $cv_param_name );
                break;

            default:

                $this->page_state[ $cv_param_name ] = $cv_param_value;
        }
    }

    public function getCustomVar( int $slot ): string {

        $cv_param_name = 'cv' . $slot;

        // check request/page level
        $cv = $this->getGlobalEventProperty( $cv_param_name ) ?: ( $this->page_state[ $cv_param_name ] ?? null );

        //check session store
        if ( ! $cv && $this->getSiteId() ) {

            $cv = $this->state->get( $this->sessionStore(), $cv_param_name );
        }

        // check visitor store
        if ( ! $cv ) {

            $cv = $this->state->get( 'v', $cv_param_name );
        }

        return (string) $cv;
    }

    public function deleteCustomVar( int $slot ): void {

        $cv_param_name = 'cv' . $slot;
        unset( $this->page_state[ $cv_param_name ] );

        if ( $this->getSiteId() ) {

            $this->state->clear( $this->sessionStore(), $cv_param_name );
        }

        $this->state->clear( 'b', $cv_param_name );
        //clear visitor level
        $this->state->clear( 'v', $cv_param_name );
        // clear page level
        $this->deleteGlobalEventProperty( $cv_param_name );

        sdk::debug( "Deleting custom variable named $cv_param_name in slot $slot." );
    }

    /**
     * @deprecated OWA infers organic search on the server; the tracker no longer does.
     */
    public function addOrganicSearchEngine( string $domain, string $query_param, bool $prepend = false ): void {
    }

    /**
     *  Use this function to parse out the url and query array element from
     *  a url.
     *
     * @return array<string, mixed>
     */
    public static function parse_url( string $url ): array {

        $parts = parse_url( $url );

        if ( ! is_array( $parts ) ) {

            return [];
        }

        if ( isset( $parts['query'] ) ) {

            parse_str( html_entity_decode( $parts['query'] ), $params );
            $parts['query_params'] = $params;
        }

        return $parts;
    }

    public function getServerParam( string $key ): mixed {

        return $_SERVER[ $key ] ?? null;
    }

    /**
     * Assembles the current URL from request params
     */
    private function getCurrentUrl(): string {

        $host = $this->getServerParam( 'HTTP_HOST' );

        if ( ! $host ) {

            $name = $this->getServerParam( 'SERVER_NAME' );

            if ( ! $name ) {

                return '';
            }

            $port = (int) $this->getServerParam( 'SERVER_PORT' );
            $host = $name . ( $port && $port !== 80 && $port !== 443 ? ':' . $port : '' );
        }

        $https = strtolower( (string) $this->getServerParam( 'HTTPS' ) );
        $secure = ( $https && $https !== 'off' )
            || (int) $this->getServerParam( 'SERVER_PORT' ) === 443
            || strtolower( (string) $this->getServerParam( 'HTTP_X_FORWARDED_PROTO' ) ) === 'https';

        return ( $secure ? 'https' : 'http' ) . '://' . $host . ( $this->getServerParam( 'REQUEST_URI' ) ?? '/' );
    }

    /**
     * Headers that let OWA attribute a request to the visitor rather than to
     * this server: OWA reads the IP, user agent and language from the request,
     * never from event properties.
     *
     * @return array<string, string>
     */
    private function visitorHeaders( bool $from_request ): array {

        $headers = [];

        $ua = $this->user_agent ?? ( $from_request ? $this->getServerParam( 'HTTP_USER_AGENT' ) : null );
        $headers['User-Agent'] = $ua ? (string) $ua : self::USER_AGENT;

        $ip = $this->ip_address;

        if ( $ip === null && $from_request ) {

            $chain = array_filter( [
                trim( (string) $this->getServerParam( 'HTTP_X_FORWARDED_FOR' ) ),
                trim( (string) $this->getServerParam( 'REMOTE_ADDR' ) ),
            ] );

            $ip = $chain ? implode( ', ', $chain ) : null;
        }

        if ( $ip ) {

            $headers['X-Forwarded-For'] = $ip;
        }

        $language = $from_request ? $this->getServerParam( 'HTTP_ACCEPT_LANGUAGE' ) : null;

        if ( $language ) {

            $headers['Accept-Language'] = (string) $language;
        }

        return $headers;
    }

    /**
     * Sends an event to log.php.
     *
     * GET normally; a POST form body when the URL would exceed
     * GET_CHARACTER_LIMIT. log.php answers before processing the event (a GIF
     * for GET, a redirect for POST), so acceptance means the request was
     * received, not that the event was stored.
     */
    private function logEvent( TrackingEvent $event, bool $from_request ): bool {

        $params = $event->getProperties();
        $params['event_type'] = $event->getEventType();

        $params = $this->ns( self::wireValues( $params ) );
        $query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

        $url = $this->getSetting( 'instance_url' ) . self::LOG_ENDPOINT;

        $headers = $this->visitorHeaders( $from_request ) + [
            'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ];

        $this->last_errors = [];

        if ( strlen( $url ) + 1 + strlen( $query ) > self::GET_CHARACTER_LIMIT ) {

            $method = 'POST';
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $res = $this->send( $method, $url, $headers, $query );

        } else {

            $method = 'GET';
            $res = $this->send( $method, $url . '?' . $query, $headers );
        }

        $accepted = $res->status >= 200 && $res->status < 400;

        if ( ! $accepted && $res->status !== 0 ) {

            $this->last_errors = [ "HTTP {$res->status}" ];
            $this->logFailure( $method, $url, $res );
        }

        return $accepted;
    }

    /**
     * Wire encoding as the JS tracker sends it: true as 'true', false and null
     * left out.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function wireValues( array $params ): array {

        $out = [];

        foreach ( $params as $k => $v ) {

            if ( $v === null || $v === false ) {

                continue;
            }

            if ( $v === true ) {

                $v = 'true';

            } elseif ( is_array( $v ) ) {

                $v = self::wireValues( $v );
            }

            $out[ $k ] = $v;
        }

        return $out;
    }

    private function makeEvent(): TrackingEvent {

        return new TrackingEvent;
    }

    public function setSiteId( string $site_id ): void {

        if ( $this->stateInit && $site_id !== $this->site_id ) {

            sdk::debug( 'Site id changed after state was initialized; session state stays with the first site.' );
        }

        $this->site_id = $site_id;
    }

    public function getSiteId(): string {

        return $this->site_id;
    }
}

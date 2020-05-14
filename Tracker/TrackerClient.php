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
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */

class TrackerClient extends OwaClient {
	
	var $config;
	
	var $state;
	
    var $commerce_event;

    var $pageview_event;

    var $global_event_properties = array();

    var $stateInit;

    var $organicSearchEngines;

    // set one traffic has been attributed.
    var $isTrafficAttributed;

    public function __construct($config = null) {

		$this->config = [
			
			'instance_url'						=> '',
			'visitor_param'                     => 'v',
			'campaign_params' 					=> [
                'campaign'      => 'owa_campaign',
                'medium'        => 'owa_medium',
                'source'        => 'owa_source',
                'search_terms'  => 'owa_search_terms',
                'ad'            => 'owa_ad',
                'ad_type'       => 'owa_ad_type' ],
            'trafficAttributionMode'            => 'direct',
            'campaignAttributionWindow'         => 60,
            'site_session_param'                => 'ss',
            'session_length'                    => 1800,
            'maxCustomVars'                     => 5,
            'max_prior_campaigns'                => 5,
            'trafficAttributionMode'            => 'direct',
            'campaignAttributionWindow'            => 60,
            'ns'								=> 'owa_',
            'cookie_persistence'                => true,  // Controls persistence of cookies, only for use in europe needed
		];
		
		// parent will override the default config with passed values.
		parent::__construct($config);
		
		if (array_key_exists( 'cookie_domain',  $config) ) {
			
			$domain = $config['cookie_domain'];			
		}

		// set the cookie domain
		$this->setCookieDomain( $domain );
		
		// inititiate state store
		$state_config = [
			'cookie_domain' 		=> $this->getSetting( 'cookie_domain' ),
			'ns'					=> $this->getSetting( 'ns' ),
			'cookie_persistence' 	=> $this->getSetting( 'cookie_persistence' ) 
		];
		
		$this->state = new State($state_config);
		
        $this->pageview_event = $this->makeEvent();
        $this->pageview_event->setEventType('base.page_request');
        // Set the page url from environmental vars
        $this->setGlobalEventProperty( 'page_url', $this->getCurrentUrl() );
        $this->registerStateStore('v', time()+3600*24*365*10, '', 'assoc', 'cookie', true);
        $this->registerStateStore('s', time()+3600*24*365*10, '', 'assoc', 'cookie', true);
        $this->registerStateStore('b', null, '', 'json', 'cookie', true);
        $cwindow = $this->getSetting( 'campaignAttributionWindow' );
        $this->registerStateStore('c', time() + 3600 * 24 * $cwindow , '', 'json', 'cookie', true);

        $this->organicSearchEngines = [
            ['d' => 'google', 'q' => 'q'],
            ['d' => 'yahoo', 'q' => 'p'],
            ['d' => 'msn', 'q' => 'q'],
            ['d' => 'bing', 'q' => 'q'],
            ['d' => 'images.google', 'q' => 'q'],
            ['d' => 'images.search.yahoo.com', 'q' => 'p'],
            ['d' => 'aol', 'q' => 'query'],
            ['d' => 'aol', 'q' => 'encquery'],
            ['d' => 'aol', 'q' => 'q'],
            ['d' => 'lycos', 'q' => 'query'],
            ['d' => 'ask', 'q' => 'q'],
            ['d' => 'altavista', 'q' => 'q'],
            ['d' => 'netscape', 'q' => 'query'],
            ['d' => 'cnn', 'q' => 'query'],
            ['d' => 'about', 'q' => 'terms'],
            ['d' => 'mamma', 'q' => 'q'],
            ['d' => 'daum', 'q' => 'q'],
            ['d' => 'eniro', 'q' => 'search_word'],
            ['d' => 'naver', 'q' => 'query'],
            ['d' => 'pchome', 'q' => 'q'],
            ['d' => 'alltheweb', 'q' => 'q'],
            ['d' => 'voila', 'q' => 'rdata'],
            ['d' => 'virgilio', 'q' => 'qs'],
            ['d' => 'live', 'q' => 'q'],
            ['d' => 'baidu', 'q' => 'wd'],
            ['d' => 'alice', 'q' => 'qs'],
            ['d' => 'yandex', 'q' => 'text'],
            ['d' => 'najdi', 'q' => 'q'],
            ['d' => 'mama', 'q' => 'query'],
            ['d' => 'seznam', 'q' => 'q'],
            ['d' => 'search', 'q' => 'q'],
            ['d' => 'wp', 'q' => 'szukaj'],
            ['d' => 'onet', 'q' => 'qt'],
            ['d' => 'szukacz', 'q' => 'q'],
            ['d' => 'yam', 'q' => 'k'],
            ['d' => 'kvasir', 'q' => 'q'],
            ['d' => 'sesam', 'q' => 'q'],
            ['d' => 'ozu', 'q' => 'q'],
            ['d' => 'terra', 'q' => 'query'],
            ['d' => 'mynet', 'q' => 'q'],
            ['d' => 'ekolay', 'q' => 'q'],
            ['d' => 'rambler', 'q' => 'query'],
            ['d' => 'rambler', 'q' => 'words'],
            ['d' => 'duckduckgo', 'q' => 'q'],
        ];
    }

    public function setPageTitle($value) {
        $this->pageview_event->set('page_title', $value);
    }

    public function setPageType($value) {
        $this->pageview_event->set('page_type', $value);
    }

    public function setProperty($name, $value) {
        $this->setGlobalEventProperty($name, $value);
    }

    private function setGlobalEventProperty($name, $value) {

        $this->global_event_properties[$name] = $value;
    }

    private function getGlobalEventProperty($name) {

        if ( array_key_exists($name, $this->global_event_properties) ) {
            return $this->global_event_properties[$name];
        }
    }

    private function deleteGlobalEventProperty( $name ) {

        if ( array_key_exists($name, $this->global_event_properties) ) {
            unset($this->global_event_properties[$name]);
        }
    }

    private function manageState( &$event ) {

        if ( ! $this->stateInit ) {

            $this->setVisitorId( $event );
            $this->setFirstSessionTimestamp( $event );
            $this->setLastRequestTime( $event );
            $this->setSessionId( $event );
            $this->setNumberPriorSessions( $event );
            $this->setDaysSinceLastSession( $event );
            $this->setTrafficAttribution( $event );
            $this->stateInit = true;
        }
    }

    private function setVisitorId( &$event ) {

        $visitor_id =  $this->state->get( 'v', 'vid' );

        if ( ! $visitor_id ) {
            $visitor_id =  $this->state->get( 'v' );
            $this->state->clear( 'v' );
            $this->state->set( 'v', 'vid', $visitor_id, 'cookie', true );

        }

        if ( ! $visitor_id ) {
            $visitor_id = $event->getSiteSpecificGuid( $this->site_id );
            $this->setGlobalEventProperty( 'is_new_visitor', true );
            $this->state->set( 'v', 'vid', $visitor_id, 'cookie', true );
        }
        // set property on event object
        $this->setGlobalEventProperty( 'visitor_id', $visitor_id );
    }

    private function setNumberPriorSessions( &$event ) {
        // if check for nps value in vistor cookie.
        $nps = $this->state->get('v', 'nps');

        // if new session, increment visit count and persist to state store
        if ( $this->getGlobalEventProperty('is_new_session' ) ) {

            // set value to 0 if not found.
            if ( ! $nps ) {

                $nps = "0";

            } else {

                $nps = $nps + 1;
            }

            $this->state->set('v', 'nps', $nps, 'cookie', true);
        }

        // set property on the event object
        $this->setGlobalEventProperty('nps', $nps);
    }

    private function setFirstSessionTimestamp( &$event ) {

        $fsts = $this->state->get( 'v', 'fsts' );

        if ( ! $fsts ) {
            $fsts = $event->get('timestamp');
            $this->state->set($this->getSetting('visitor_param'), 'fsts', $fsts , 'cookie', true);
        }

        $this->setGlobalEventProperty( 'fsts', $fsts );

        // calc days since first session
        $dsfs = round( ( $fsts - $event->get( 'timestamp' ) ) / ( 3600 * 24 ) ) ;
        $this->state->set($this->getSetting( 'visitor_param' ), 'dsfs', $dsfs , 'cookie', true);
        $this->setGlobalEventProperty( 'dsfs', $dsfs );
    }

    private function setDaysSinceLastSession( &$event ) {

        sdk::debug('setting days since last session.');
        $dsps = '';
        if ( $this->getGlobalEventProperty( 'is_new_session' ) ) {
            sdk::debug( 'timestamp: ' . $event->get( 'timestamp' ) );
            $last_req = $this->getGlobalEventProperty( 'last_req' );
            if ( ! $last_req ) {
                $last_req = $event->get( 'timestamp' );
            }
            sdk::debug( 'last_req: ' . $last_req );
            $dsps = round( ( $event->get( 'timestamp' ) - $last_req ) / ( 3600*24 ) );
            $this->state->set('s', 'dsps', $dsps , 'cookie', true);
        }

        if ( ! $dsps ) {
            $dsps = $this->state->get( 's', 'dsps' );

            if ( ! $dsps ) {
                $dsps = 0;
            }
        }

        $this->setGlobalEventProperty( 'dsps', $dsps );
    }

    private function setSessionId( &$event ) {

        $is_new_session = $this->isNewSession( $event->get( 'timestamp' ),  $this->getGlobalEventProperty( 'last_req' ) );
        if ( $is_new_session ) {
	        sdk::debug("is new session");
            //set prior_session_id
            $prior_session_id = $this->state->get('s', 'sid');
            if ( ! $prior_session_id ) {
                $state_store_name = sprintf('%s_%s', $this->getSetting('site_session_param'), $this->site_id);
                $prior_session_id = $this->state->get($state_store_name, 's');
            }
            if ($prior_session_id) {
                $this->setGlobalEventProperty( 'prior_session_id', $prior_session_id );
            }

            $this->resetSessionState();

            $session_id = $event->getSiteSpecificGuid( $this->site_id );

               //mark new session flag on current request
            $this->setGlobalEventProperty( 'is_new_session', true );
            $this->state->set( 's', 'sid', $session_id, 'cookie', true );

        } else {
            // Must be an active session so just pull the session id from the state store
            $session_id = $this->state->get('s', 'sid');

            // support for old style cookie
            if ( ! $session_id ) {
                $state_store_name = sprintf('%s_%s', $this->getSetting('site_session_param'), $this->site_id);
                $session_id = $this->state->get($state_store_name, 's');

            }

            // fail-safe just in case there is no session_id
            if ( ! $session_id ) {
                $session_id = $event->getSiteSpecificGuid( $this->site_id );
                //mark new session flag on current request
                $this->setGlobalEventProperty( 'is_new_session', true );
                sdk::debug('setting failsafe session id');
            }
        }

        // set global event property
           $this->setGlobalEventProperty( 'session_id', $session_id );
        // set sid state
        $this->state->set( 's', 'sid', $session_id, 'cookie', true );
    }

    private function setLastRequestTime( &$event ) {

        $last_req = $this->state->get('s', 'last_req');

        // suppport for old style cookie
        if ( ! $last_req ) {
            $state_store_name = sprintf( '%s_%s', $this->getSetting( 'site_session_param' ), $this->site_id );
            $last_req = $this->state->get( $state_store_name, 'last_req' );
        }
        // set property on event object
        $this->setGlobalEventProperty( 'last_req', $last_req );
        sdk::debug("setting last_req value of $last_req as global event property.");
        // store new state value
        $this->state->set( 's', 'last_req', $event->get( 'timestamp' ), 'cookie', true );
    }

    /**
     * Check to see if request is a new or active session
     *
     * @return boolean
     */
    private function isNewSession($timestamp = '', $last_req = 0) {
		
        $is_new_session = false;

        if ( ! $timestamp ) {
            $timestamp = time();
        }

        $time_since_lastreq = $timestamp - $last_req;
        $len = $this->getSetting( 'session_length' );
        if ( $time_since_lastreq < $len ) {
            sdk::debug("This request is part of an active session.");
            return false;
        } else {
            //NEW SESSION. prev session expired, because no requests since some time.
            sdk::debug("This request is the start of a new session. Prior session expired.");
            return true;
        }
    }

    /**
     * Logs tracking event
     *
     * This function fires a tracking event that will be processed and then dispatched
     *
     * @param object $event
     * @return boolean
     */
    public function trackEvent($event) {

        // do not track anything if user is in overlay mode
        if ($this->state->get('overlay')) {
            return false;
        }

        $this->setGlobalEventProperty( 'HTTP_REFERER', $this->getServerParam('HTTP_REFERER') );
		$this->setGlobalEventProperty( 'HTTP_USER_AGENT', $this->getServerParam('HTTP_USER_AGENT') );

        // needed by helper page tags function so it can append to first hit tag url
        if (!$this->getSiteId()) {
            $this->setSiteId($event->get('site_id'));
        }
		
		// is this needed?
        if (!$this->getSiteId()) {
            $this->setSiteId( $_GET['site_id'] );
        }

        // set various state properties.
        $this->manageState( $event );

        $event = $this->setAllGlobalEventProperties( $event );

        // send event to log API for processing.
        return $this->logEvent($event->getEventType(), $event);
    }

    public function setAllGlobalEventProperties( $event ) {

        if ( ! $event->get('site_id') ) {
            $event->set( 'site_id', $this->getSiteId() );
        }

        // add custom variables to global properties if not there already
        for ( $i=1; $i <= $this->getSetting( 'maxCustomVars' ); $i++ ) {
            $cv_param_name = 'cv' . $i;
            $cv_value = '';

            // if the custom var is not already a global property
            if ( ! $this->getGlobalEventProperty( $cv_param_name ) ) {
                // check to see if it exists
                $cv_value = $this->getCustomVar( $i );
                // if so add it
                if ( $cv_value ) {
                    $this->setGlobalEventProperty( $cv_param_name, $cv_value );
                }
            }
        }

        // merge global event properties
        $event->setNewProperties( $this->global_event_properties );

        return $event;

    }

    public function getAllEventProperties( $event ) {

        $event = $this->setAllGlobalEventProperties( $event );
        return $event->getProperties();
    }

    public function trackPageview($event = '') {

        if ($event) {
            $event->setEventType('base.page_request');
            $this->pageview_event = $event;
        }
        return $this->trackEvent($this->pageview_event);
    }

    public function trackAction($action_group = '', $action_name, $action_label = '', $numeric_value = 0) {

        $event = $this->makeEvent();
        $event->setEventType('track.action');
        $event->set('action_group', $action_group);
        $event->set('action_name', $action_name);
        $event->set('action_label', $action_label);
        $event->set('numeric_value', $numeric_value);
        $event->set('site_id', $this->getSiteId());
        return $this->trackEvent($event);
    }

    /**
     * Creates a ecommerce Transaction event
     *
     * Creates a parent commerce.transaction event
     */
    public function addTransaction(
            $order_id,
            $order_source = '',
            $total = 0,
            $tax = 0,
            $shipping = 0,
            $gateway = '',
            $country = '',
            $state = '',
            $city = '',
            $page_url = '',
            $session_id = ''
        ) {

        $this->commerce_event = $this->makeEvent();
        $this->commerce_event->setEventType( 'ecommerce.transaction' );
        $this->commerce_event->set( 'ct_order_id', $order_id );
        $this->commerce_event->set( 'ct_order_source', $order_source );
        $this->commerce_event->set( 'ct_total', $total );
        $this->commerce_event->set( 'ct_tax', $tax );
        $this->commerce_event->set( 'ct_shipping', $shipping );
        $this->commerce_event->set( 'ct_gateway', $gateway );
        $this->commerce_event->set( 'page_url', $page_url );
        $this->commerce_event->set( 'ct_line_items', array() );
        $this->commerce_event->set( 'country', $country );
        $this->commerce_event->set( 'state', $state );
        $this->commerce_event->set( 'city', $city );
        if ( $session_id ) {
            $this->commerce_event->set( 'original_session_id', $session_id );
            // tells the client to NOT manage state properties as we are
            // going to look them up from the session later.
            $this->commerce_event->set( 'is_state_set', true );
        }
    }

    /**
     * Adds a line item to a commerce transaction
     *
     * Creates and a commerce.line_item event and adds it to the parent transaction event
     */
    public function addTransactionLineItem($order_id, $sku = '', $product_name = '', $category = '', $unit_price = 0, $quantity = 0) {

        if ( empty( $this->commerce_event ) ) {
            $this->addTransaction('none set');
        }

        $li = array();
        $li['li_order_id'] = $order_id ;
        $li['li_sku'] = $sku ;
        $li['li_product_name'] = $product_name ;
        $li['li_category'] = $category ;
        $li['li_unit_price'] = $unit_price ;
        $li['li_quantity'] = $quantity ;

        $items = $this->commerce_event->get( 'ct_line_items' );
        $items[] = $li;
        $this->commerce_event->set( 'ct_line_items', $items );
    }

    /**
     * tracks a commerce events
     *
     * Tracks a parent transaction event by sending it to the event queue
     */
    public function trackTransaction() {

        if ( ! empty( $this->commerce_event ) ) {
            $this->trackEvent( $this->commerce_event );
            $this->commerce_event = '';
        }
    }

    public function createSiteId($value) {

        return md5($value);
    }

    public function setCampaignNameKey( $key ) {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $campaign_params[ 'campaign' ] = $key;
        $this->setSetting('campaign_params', $campaign_params);
    }

    public function setCampaignMediumKey( $key ) {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $campaign_params[ 'medium' ] = $key;
        $this->setSetting('campaign_params', $campaign_params);
    }

    public function setCampaignSourceKey( $key ) {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $campaign_params[ 'source' ] = $key;
        $this->setSetting('campaign_params', $campaign_params);
    }

    public function setCampaignSearchTermsKey( $key ) {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $campaign_params[ 'search_terms' ] = $key;
        $this->setSetting('campaign_params', $campaign_params);
    }

    public function setCampaignAdKey( $key ) {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $campaign_params[ 'ad' ] = $key;
        $this->setSetting('campaign_params', $campaign_params);
    }

    public function setCampaignAdTypeKey( $key ) {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $campaign_params[ 'ad_type' ] = $key;
        $this->setSetting('campaign_params', $campaign_params);
    }

    public function setUserName( $value ) {

        $this->setGlobalEventProperty( 'user_name', $value );
    }
    
    public function setUserEmail( $value ) {

        $this->setGlobalEventProperty( 'user_email', $value );
    }

    function getCampaignProperties( $event ) {

        $campaign_params = $this->getSetting( 'campaign_params' );
        $campaign_properties = array();
        $campaign_state = array();
        
        foreach ( $campaign_params as $k => $param ) {
            //look for property on the event
            $property = $event->get( $param );

            // look for property on the request scope.
            if ( ! $property ) {
                $property = $this->getRequestParam( $param );
            }
            if ( $property ) {
                $campaign_properties[ $k ] = $property;
            }
        }

        // backfill values for incomplete param combos

        if (array_key_exists('ad_type', $campaign_properties) && !array_key_exists('ad', $campaign_properties)) {
            $campaign_properties['ad'] = '(not set)';
        }

        if (array_key_exists('ad', $campaign_properties) && !array_key_exists('ad_type', $campaign_properties)) {
            $campaign_properties['ad_type'] = '(not set)';
        }

        if (!empty($campaign_properties)) {
            //$campaign_properties['ts'] = $event->get('timestamp');
        }

        sdk::debug('campaign properties: '. print_r($campaign_properties, true));

        return $campaign_properties;
    }

    private function setCampaignSessionState( $properties ) {

        $campaign_params = $this->getSetting( 'campaign_params' );
        foreach ($campaign_params as $k => $v) {

            if (array_key_exists( $k, $properties ) ) {

                $this->state->set( 's', $k, $properties[$k] );
            }
        }
    }

    function directAttributionModel( &$campaign_properties ) {

        // add new campaign info to existing campaign cookie.
        if ( $campaign_properties ) {

            // get prior campaing touches from c cookie
            $campaign_state = $this->getCampaignState();

            // add new campaign into state array
            $campaign_state[] = (object) $campaign_properties;

            // if more than x slice the first one off to make room
            $count = count( $campaign_state );
            $max = $this->getSetting( 'max_prior_campaigns' );
            if ($count > $max ) {
                array_shift( $campaign_state );
            }

            // reset state
            $this->setCampaignCookie($campaign_state);

            // set flag
            $this->isTrafficAttributed = true;

            // persist state to session store
            $this->setCampaignSessionState( $campaign_properties );
        }

    }

    function originalAttributionModel( &$campaign_properties ) {

        $campaign_state = $this->getCampaignState();
        // orignal touch was set previously. jus use that.
        if (!empty($campaign_state)) {
            // do nothing
            // set the attributes from the first campaign touch
            $campaign_properties = $campaign_state[0];
            $this->isTrafficAttributed = true;

        // no orginal touch, set one if it's a new campaign touch
        } else {

            if (!empty($campaign_properties)) {
                // add timestamp
                //$campaign_properties['ts'] = $event->get('timestamp');
                sdk::debug('Setting original Campaign attrbution.');
                $campaign_state[] = $campaign_properties;
                // set cookie
                $this->setCampaignCookie($campaign_state);
                $this->isTrafficAttributed = true;
            }
        }

        // persist state to session store
        $this->setCampaignSessionState( $campaign_properties );
    }

    function getCampaignState() {

        $campaign_state = $this->state->get( 'c', 'attribs' );
        if ( ! $campaign_state ) {

            $campaign_state = array();
        }

        return $campaign_state;
    }

    function setTrafficAttribution( &$event ) {

        // if not then look for individual campaign params on the request.
        // this happens when the client is php and the params are on the url
        $campaign_properties = $this->getCampaignProperties( $event );
        if ( $campaign_properties ) {
            $campaign_properties['ts'] = $event->get('timestamp');
        }

        // choose attribution model.
        $model = $this->getSetting( 'trafficAttributionMode' );
        switch ( $model ) {

            case 'direct':
                sdk::debug( 'Applying "Direct" Traffic Attribution Model' );
                $this->directAttributionModel( $campaign_properties );
                break;
            case 'original':
                sdk::debug( 'Applying "Original" Traffic Attribution Model' );
                $this->originalAttributionModel( $campaign_properties );
                break;
            default:
                sdk::debug( 'Applying Default (Direct) Traffic Attribution Model' );
                $this->directAttributionModel( $campaign_properties );
        }

        // if one of the attribution methods attributes the traffic them
        // set attribution properties on the event object
        if ( $this->isTrafficAttributed ) {

            sdk::debug( 'Attributing Traffic to: %s', print_r($campaign_properties, true ) );

        } else {
            // infer the attribution from the referer
            // if the request is the start of a new session
            if ( $this->getGlobalEventProperty( 'is_new_session' ) ) {
                sdk::debug( 'Inferring traffic attribution.' );
                $this->inferTrafficAttribution();
            }
        }

        // apply traffic attribution realted properties to events
        // all properties should be set in the state store by this point.
        $campaign_params = $this->getSetting('campaign_params');
        foreach( $campaign_params as $k => $v ) {

            $value = $this->state->get( 's', $k );

            if ( $value ) {
                $this->setGlobalEventProperty( $k, $value );
            }
        }

        // set sesion referer
        $session_referer = $this->state->get('s', 'referer');
        if ( $session_referer ) {

            $this->setGlobalEventProperty( 'session_referer', $session_referer );
        }

        // set campaign touches
        $campaign_state = $this->state->get('c', 'attribs');
        if ( $campaign_state ) {

            $this->setGlobalEventProperty( 'attribs', json_encode( $campaign_state ));
        }
    }

    private function inferTrafficAttribution() {

        $ref = $this->getServerParam('HTTP_REFERER');
        $medium = 'direct';
        $source = '(none)';
        $search_terms = '(none)';
        $session_referer = '(none)';

        if ( $ref ) {
            $uri = $this->parse_url( $ref );

            // check for external referer
            $host = $this->getServerParam('HTTP_HOST');
            if ( $host != $uri['host'] ) {

                $medium = 'referral';
                $source = $this->stripWwwFromDomain( $uri['host'] );
                $engine = $this->isRefererSearchEngine( $uri );
                $session_referer = $ref;
                if ( $engine ) {
                    $medium = 'organic-search';
                    $search_terms = $engine['t'];
                }
            }
        }

        $this->state->set('s', 'referer', $session_referer);
        $this->state->set('s', 'medium', $medium);
        $this->state->set('s', 'source', $source);
        $this->state->set('s', 'search_terms', $search_terms);
    }
    

    private function isRefererSearchEngine( $uri ) {

        if ( !isset( $uri['host'] ) ) {
            return null;
        }

        $host = $uri['host'];

        $searchEngine = [];

        foreach ( $this->organicSearchEngines as $engine ) {
            $domain = $engine['d'];

            if (strpos($host, $domain) === false) {
                continue;
            }

            $query_param = $engine['q'];
            $term = '';

            if (isset($uri['query_params'][$query_param])) {
                $term = $uri['query_params'][$query_param];
            }

            sdk::debug( 'Found search engine: %s with query param %s:, query term: %s', $domain, $query_param, $term);

            $searchEngine = ['d' => $domain, 'q' => $query_param, 't' => $term];
            break;
        }

        return $searchEngine;
    }

    function setCampaignCookie($values) {
        // reset state
        $this->state->set('c', 'attribs', $values);
    }

    // sets cookies domain
    function setCookieDomain($domain) {

        if (!empty($domain)) {
           
            $this->setCookieDomainName($domain);
        }
    }

    /**
     * Set a custom variable
     *
     * @param    slot    int        the identifying number for the custom variable. 1-5.
     * @param    name    string    the key of the custom variable.
     * @param    value    string    the value of the varible
     * @param    scope    string    the scope of the variable. can be page, session, or visitor
     */
    public function setCustomVar( $slot, $name, $value, $scope = '' ) {

        $cv_param_name = 'cv' . $slot;
        $cv_param_value = $name . '=' . $value;

        if ( strlen( $cv_param_value ) > 65 ) {
            sdk::debug('Custom variable name + value is too large. Must be less than 64 characters.');
            return;
        }

        switch ( $scope ) {

            case 'session':

                // store in session cookie
                $this->state->set( 'b', $cv_param_name, $cv_param_value );
                sdk::debug( 'just set custom var on session.' );
                break;

            case 'visitor':

                // store in visitor cookie
                $this->state->set( 'v', $cv_param_name, $cv_param_value );
                // remove slot from session level cookie
                $this->state->clear( 'b', $cv_param_name );
                break;
        }

        $this->setGlobalEventProperty( $cv_param_name, $cv_param_value );
    }

    public function getCustomVar( $slot ) {

        $cv_param_name = 'cv' . $slot;
        $cv = '';
        // check request/page level
        $cv = $this->getGlobalEventProperty( $cv_param_name );
        //check session store
        if ( ! $cv ) {
            $cv = $this->state->get( 'b', $cv_param_name );
        }
        // check visitor store
        if ( ! $cv ) {
            $cv = $this->state->get( 'v', $cv_param_name );
        }

        return $cv;

    }

    public function deleteCustomVar( $slot ) {

        $cv_param_name = 'cv' . $slot;
        //clear session level
        $this->state->clear( 'b', $cv_param_name );
        //clear visitor level
        $this->state->clear( 'v', $cv_param_name );
        // clear page level
        $this->deleteGlobalEventProperty( $cv_param_name );

        sdk::debug("Deleting custom variable named $cv_param_name in slot $slot.");
    }

    private function resetSessionState() {

        $last_req = $this->state->get( 's', 'last_req' );
        $this->state->clear( 's' );
        $this->state->set( 's', 'last_req', $last_req);
    }

    public function addOrganicSearchEngine( $domain, $query_param, $prepend = '' ) {

        $engine = array('d' => $domain, 'q' => $query_param);
        if ( $prepend) {
            array_unshift($this->organicSearchEngines, $engine );
        } else {
                $this->organicSearchEngines[] = $engine;
        }
    }
    
    private function registerStateStore( $name, $expiration, $length = '', $format = '', $type = 'cookie', $cdh_required = ''  ) {
	
        return $this->state->registerStore( $name, $expiration, $length, $format, $type, $cdh_required );

    }
    
    /**
     * sets and checks the cookie domain setting
     *
     * @param unknown_type $domain
     */
    private function setCookieDomainName ($domain = '') {

        $explicit = false;

        if ( ! $domain ) {
            $domain = $this->getServerParam('HTTP_HOST');
            $explicit = true;
        }

        // strip port, add leading period etc.
        $domain = $this->sanitizeCookieDomain($domain);

        // Set the cookie domain only if the domain name is a Fully qualified domain name (FQDN)
        // i.e. avoid attempts to set cookie domain for e.g. "localhost" as that is not valid

        //check for two dots in the domain name
        $twodots = substr_count($domain, '.');

        if ( $twodots >= 2 ) {

            // unless www.domain.com is passed explicitly
            // strip the www from the domain.
            if ( ! $explicit ) {
                $part = substr( $domain, 0, 5 );
                if ($part === '.www.') {
                    //strip .www.
                    $domain = substr( $domain, 5);
                    // add back the leading period
                    $domain = '.'.$domain;
                }
            }

            $this->setSetting( 'cookie_domain', $domain );
            sdk::debug("Setting cookie domain to $domain");
         } else {
             sdk::debug("Not setting cookie domain as $domain is not a FQDN.");
         }
     }
     
     private function sanitizeCookieDomain($domain) {

        // Remove port information.
         $port = strpos( $domain, ':' );
        if ( $port ) {
            $domain = substr( $domain, 0, $port );
        }

        // check for leading period, add if missing
        $period = substr( $domain, 0, 1);
        if ( $period != '.' ) {
            $domain = '.'.$domain;
        }

        return $domain;
    }
    
    private function stripWWWFromDomain($domain) {

        $done = false;
        $part = substr( $domain, 0, 5 );
        if ($part === '.www.') {
            //strip .www.
            $domain = substr( $domain, 5);
            // add back the leading period
            $domain = '.'.$domain;
            $done = true;
        }

        if ( ! $done ) {
            $part = substr( $domain, 0, 4 );
            if ($part === 'www.') {
                //strip .www.
                $domain = substr( $domain, 4);
                $done = true;
            }

        }

        return $domain;
    }

    /**
     *  Use this function to parse out the url and query array element from
     *  a url.
     */
    public static function parse_url( $url ) {

        $url = parse_url($url);

        if ( isset( $url['query'] ) ) {
            $var = $url['query'];

            $var  = html_entity_decode($var);
            $var  = explode('&', $var);
            $arr  = array();

              foreach( $var as $val ) {

                if ( strpos($val, '=') ) {
                    $x = explode('=', $val);

                    if ( isset( $x[1] ) ) {
                        $arr[$x[0]] = urldecode($x[1]);
                    }
                } else {
                    $arr[$val] = '';
                }
               }
              unset($val, $x, $var);

              $url['query_params'] = $arr;

        }

          return $url;
    }
    
    public function getServerParam( $key ) {
	    
	    if ( isset( $_SERVER ) && array_key_exists( $key, $_SERVER ) ) {
		    
		    return $_SERVER[ $key ];
	    }
    }

	/**
     * Assembles the current URL from request params
     *
     * @return string
     */
    private function getCurrentUrl() {

        $url = 'http';

        // check for https
        if( isset( $_SERVER['HTTPS'] ) && strtolower( $_SERVER['HTTPS'] ) == 'on') {
            $url.= 's';
        } elseif ( isset( $_SERVER['SERVER_PORT'] ) && $_SERVER['SERVER_PORT'] == 443 ) {
            $url.= 's';
        } elseif ( isset( $_SERVER['HTTP_ORIGIN'] ) && substr( $_SERVER['HTTP_ORIGIN'], 0, 5 ) === 'https' ) {
            $url.= 's';
        } elseif ( isset( $_SERVER['HTTP_REFERER'] ) && substr( $_SERVER['HTTP_REFERER'], 0, 5 ) === 'https' ) {
            $url.= 's';
        }

        if ( isset( $_SERVER['HTTP_HOST'] ) ) {
            // contains port number
            $domain = $_SERVER['HTTP_HOST'];
        } else {
            // does not contain port number.
            $domain = $_SERVER['SERVER_NAME'];
            if( $_SERVER['SERVER_PORT'] != 80 ) {
                $domain .= ':' . $_SERVER['SERVER_PORT'];
            }
        }

        $url .= '://'.$domain;

        $url .= $_SERVER['REQUEST_URI'];

        return $url;
    }

	private function logEvent($event_type, $event) {
		
		sdk::debug('implement logEvent method');
		print_r($event);
		
		$conf = [
			
			'base_uri' => $this->getSetting('instance_url')
		];
		
		$http = $this->getHttpClient( $conf );
		$params = $event->getProperties();
		$params['event_type'] = $event->getEventType();
		
		$params = $this->applyNamespaceToKeys( $params );
		
		$res = $http->request(
			'GET', 'log.php', 
			['query' => $params ] 
		);
		
		print_r($res);
		
	}
	
	private function applyNameSpaceToKeys( $params ) {
		
		$nparams = [];
		$ns = $this->getSetting('ns');
		foreach ( $params as $k => $v ) {
			
			$nparams[ $ns.$k ] = $v;
		}
		
		return $nparams;
	}
	
	private function getRequestParam($name) {

        if (array_key_exists($name, $_GET)) {
	        
            return htmlentities( strip_tags( $_GET[$name] ) );
        }
    }
    
    private function makeEvent() {
	    
	    return new TrackingEvent;
    }
    
    function setSiteId($site_id) {
        
        $this->site_id = $site_id;
    }
    
    function getSiteId() {
        
        return $this->site_id;
    }
}

?>
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
 * @author      Peter Adams <peter@openwebanalytics.com> 
 */


class State {
	
	private $config;
    private $stores;
    private $stores_meta;
    private $is_dirty;
    private $dirty_stores;
    private $default_store_type;
    private $stores_with_cdh;
    private $initial_state;
    private $cookies;

    function __construct( $config = []) {
		
		$this->config = [
			
			'cookie_domain' => ''
		];
		
		// merge incomming config params
		$this->config = array_merge( $this->config, $config );
		
		
        $this->cookies = [];
		$this->stores = [];
	    $this->stores_meta = [];
	    $this->is_dirty;
	    $this->dirty_stores;
	    $this->default_store_type = 'cookie';
	    $this->stores_with_cdh = [];
	    $this->initial_state = [];
	    
	    $this->initializeStores();

    }

	private function getSetting( $name ) {
		
		if ( array_key_exists( $name, $this->config ) ) {
			
			return $this->config[ $name ];
		}

	}

    function registerStore( $name, $expiration, $length = '', $format = 'json', $type = 'cookie', $cdh = null ) {

        $this->stores_meta[$name] = array(
            'expiration'     => $expiration,
            'length'         => $length,
            'format'         => $format,
            'type'             => $type,
            'cdh_required'     => $cdh
        );

        if ( $cdh ) {
            $this->stores_with_cdh[] = $name;
        }
    }



    public function get($store, $name = '') {

        sdk::debug("Getting state - store: ".$store.' key: '.$name, $this->getSetting('debug'));
        //owa_coreAPI::debug("existing stores: ".print_r($this->stores, true));
        if ( ! isset($this->stores[$store] ) ) {
            $this->loadState($store);
        }

        if (array_key_exists($store, $this->stores)) {

            if (!empty($name)) {
                // check to ensure this is an array, could be a string.
                if (is_array($this->stores[$store]) && array_key_exists($name, $this->stores[$store])) {

                    return $this->stores[$store][$name];
                } else {
                    return false;
                }
            } else {

                return $this->stores[$store];
            }
        } else {

            return false;
        }
    }

    function setState($store, $name, $value, $store_type = '', $is_perminent = false) {

        sdk::debug(sprintf('populating state for store: %s, name: %s, value: %s, store type: %s, is_perm: %s', $store, $name, print_r($value, true), $store_type, $is_perminent), $this->getSetting('debug'));

        // set values
        if (empty($name)) {
            $this->stores[$store] = $value;
            //owa_coreAPI::debug('setstate: '.print_r($this->stores, true));
        } else {
            //just in case the store was set first as a string instead of as an array.
            if ( array_key_exists($store, $this->stores)) {

                if ( ! is_array( $this->stores[$store] ) ) {
                    $new_store = array();
                    // check to see if we need ot ad a cdh
                    if ( $this->isCdhRequired($store) ) {
                        $new_store['cdh'] = $this->getCookieDomainHash();
                    }

                    $new_store[$name] = $value;
                    $this->stores[$store] = $new_store;

                } else {
                    $this->stores[$store][$name] = $value;
                }
            // if the store does not exist then    maybe add a cdh and the value
            } else {

                if ( $this->isCdhRequired($store) ) {
                    $this->stores[$store]['cdh'] = $this->getCookieDomainHash();
                }

                $this->stores[$store][$name] = $value;
            }

        }

        $this->dirty_stores[] = $store;
        //owa_coreAPI::debug(print_r($this->stores, true));
    }

    function isCdhRequired($store_name) {

        if ( isset( $this->stores_meta[$store_name] ) ) {
            return $this->stores_meta[$store_name]['cdh_required'];
        }
    }

    function set($store, $name, $value, $store_type = '', $is_perminent = false) {

        if ( ! isset($this->stores[$store] ) ) {
            $this->loadState($store);
        }

        $this->setState($store, $name, $value, $store_type, $is_perminent);

        // persist immeadiately if the store type is cookie
        if ($this->stores_meta[$store]['type'] === 'cookie') {

            $this->persistState($store);
        }
    }

    function persistState( $store ) {

        //check to see that store exists.
        if ( isset( $this->stores[ $store ] ) ) {
            sdk::debug('Persisting state store: '. $store . ' with: '. print_r($this->stores[ $store ], true), $this->getSetting('debug'));
            // transform state array into a string using proper format
            if ( is_array( $this->stores[$store] ) ) {
                switch ( $this->stores_meta[$store]['type'] ) {

                    case 'cookie':

                        // check for old style assoc format
                        // @todo eliminiate assoc style cookie format.
                        if ( $this->stores_meta[$store]['format'] === 'assoc' ) {
                            $cookie_value = $this->implode_assoc('=>', '|||', $this->stores[ $store ] );
                        } else {
                            $cookie_value = json_encode( $this->stores[ $store ] );
                        }

                        break;

                    default:

                }
            } else {
                $cookie_value = $this->stores[ $store ];
            }
            // get expiration time
            $time = $this->stores_meta[$store]['expiration'];
            //set cookie
            $this->createCookie( $store, $cookie_value, $time, "/", $this->getSetting( 'cookie_domain' ) );

        } else {

            sdk::debug("Cannot persist state. No store registered with name $store", $this->getSetting('debug'));
        }
    }

    function setInitialState($store, $value, $store_type = '') {

        if ($value) {
            $this->initial_state[$store] = $value;
        }
    }

    function loadState($store, $name = '', $value = '', $store_type = 'cookie') {

        //get possible values
        if ( ! $value && isset( $this->initial_state[$store] ) ) {
            $possible_values = $this->initial_state[$store];
        } else {
            return;
        }


        //count values
        $count = count($possible_values);
        // loop throught values looking for a domain hash match or just using the last value.
        foreach ($possible_values as $k => $value) {
            // check format of value

            if ( strpos( $value, "|||" ) ) {
                $value = $this->assocFromString($value);
            } elseif ( strpos( $value, ":" ) ) {
                $value = json_decode($value);
                $value = (array) $value;
            } else {
                $value = $value;
            }

            if ( in_array( $store, $this->stores_with_cdh ) ) {

                if ( is_array( $value ) && isset( $value['cdh'] ) ) {

                    $runtime_cdh = $this->getCookieDomainHash();
                    $cdh_from_state = $value['cdh'];

                    // return as the cdh's do not match
                    if ( $cdh_from_state === $runtime_cdh ) {
                        sdk::debug("cdh match:  $cdh_from_state and $runtime_cdh", $this->getSetting('debug'));
                        return $this->setState($store, $name, $value, $store_type);
                    } else {
                        // cookie domains do not match so we need to delete the cookie in the offending domain
                        // which is always likely to be a sub.domain.com and thus HTTP_HOST.
                        // if cookie is not deleted then new cookies set on .domain.com will never be seen by PHP
                        // as only the sub domain cookies are available.
                        sdk::debug("Not loading state store: $store. Domain hashes do not match - runtime: $runtime_cdh, cookie: $cdh_from_state", $this->getSetting('debug'));
                        //owa_coreAPI::debug("deleting cookie: owa_$store");
                        //owa_coreAPI::deleteCookie($store,'/', $_SERVER['HTTP_HOST']);
                        //unset($this->initial_state[$store]);
                        //return;
                    }
                } else {

                    sdk::debug("Not loading state store: $store. No domain hash found.", $this->getSetting('debug'));
                    return;
                }

            } else {
                // just set the state with the last value
                if ( $k === $count - 1 ) {
                    sdk::debug("loading last value in initial state container for store: $store", $this->getSetting('debug'));
                    return $this->setState($store, $name, $value, $store_type);
                }
            }
        }
    }

    function clear($store, $name = '') {

        if ( ! isset($this->stores[$store] ) ) {
            $this->loadState($store);
        }

        if ( array_key_exists( $store, $this->stores ) ) {

            if ( ! $name ) {

                unset( $this->stores[ $store ] );

                if ($this->stores_meta[$store]['type'] === 'cookie') {

                    return $this->deleteCookie($store);
                }

            } else {

                if ( array_key_exists( $name, $this->stores[ $store ] ) ) {
                    unset( $this->stores[ $store ][ $name ] );

                    if ($this->stores_meta[$store]['type'] === 'cookie') {

                        return $this->persistState( $store );
                    }
                }
            }
        }
    }

    function getPermExpiration() {

        $time = time()+3600*24*365*15;
        return $time;
    }

    function addStores($array) {

        $this->stores = array_merge($this->stores, $array);
        return;
    }

    function getCookieDomainHash($domain = '') {

        if ( ! $domain ) {
            $domain = $this->getSetting( 'cookie_domain' );
        }

        return $this->crc32AsHex($domain);
    }
    
    /**
     * Convert Associative Array to String
     *
     * @param string $inner_glue
     * @param string $outer_glue
     * @param array $array
     * @return string
     */
    public static function implode_assoc($inner_glue, $outer_glue, $array) {
       $output = array();
       foreach( $array as $key => $item ) {
              $output[] = $key . $inner_glue . $item;
        }

        return implode($outer_glue, $output);
    }
    
    private function createCookie($cookie_name, $cookie_value, $expires = 0, $path = '/', $domain = '') {

        if (! $domain ) {
            
            $domain = $this->getSetting( 'cookie_domain' );
        }
        
        if (is_array($cookie_value)) {

            $cookie_value = $this->implode_assoc('=>', '|||', $cookie_value);
        }

        // add namespace
        $cookie_name = sprintf('%s%s', $this->getSetting('ns'), $cookie_name);

        // debug
        sdk::debug(sprintf('Setting cookie %s with values: %s under domain: %s', $cookie_name, $cookie_value, $domain), $this->getSetting('debug'));

        // makes cookie to session cookie only
        if ( !$this->getSetting( 'cookie_persistence' ) ) {
            $expires = 0;
        }
		
		//$path .= '; SameSite=lax';
		
        setcookie($cookie_name, $cookie_value, $expires, $path, $domain);
    }

    private function deleteCookie($cookie_name, $path = '/', $domain = '') {

        return $this->createCookie($cookie_name, false, time()-3600*25, $path, $domain);
    }
    
    private function crc32AsHex($string) {
	    
        $crc = crc32($string);
        //$crc += 0x100000000;
        if ($crc < 0) {
            $crc = 0xFFFFFFFF + $crc + 1;
        }
        return dechex($crc);
    }

	private function assocFromString($string_state, $inner = '=>', $outer = '|||') {

        if (!empty($string_state)):

            if (strpos($string_state, $outer) === false):

                return $string_state;

            else:

                $array = explode($outer, $string_state);

                $state = array();

                foreach ($array as $key => $value) {

                    list($realkey, $realvalue) = explode($inner, $value);
                    $state[$realkey] = $realvalue;

                }

            endif;

        endif;

        return $state;


    }
    
    private function initializeStores() {
	    
        // look for access to the raw HTTP cookie string. This is needed becuause OWA can set settings cookies
        // with the same name under different subdomains. Multiple cookies with the same name are not
        // available under $_COOKIE. Therefor OWA's cookie conainter must be an array of arrays.
        if ( isset( $_SERVER['HTTP_COOKIE'] ) && strpos( $_SERVER['HTTP_COOKIE'], ';') ) {

            $raw_cookie_array = explode(';', $_SERVER['HTTP_COOKIE']);

            foreach($raw_cookie_array as $raw_cookie ) {

                $nvp = explode( '=', trim( $raw_cookie ) );
                $this->cookies[ $nvp[0] ][] = isset($nvp[1])?urldecode($nvp[1]):'';
            }

        } else {
            // just use the normal cookie global
            if ( $_COOKIE && is_array($_COOKIE) ) {

                foreach ($_COOKIE as $n => $v) {
                    // hack against other frameworks sanitizing cookie data and blowing away our '>' delimiter
                    // this should be removed once all cookies are using json format.
                    if (strpos($v, '&gt;')) {
                        $v = str_replace("&gt;", ">", $v);
                    }

                    $cookies[ $n ][] = $v;
                }
            }
        }

        // populate owa_cookie container with just the cookies that have the owa namespace.
        $this->cookies = $this->stripParams( $this->cookies, $this->getSetting( 'ns' ) );
		
		// merges cookies
        foreach ( $this->cookies as $k => $owa_cookie ) {

            $this->setInitialState( $k, $owa_cookie );
        }
    }
    
    private function stripParams($params, $ns = '') {

        $striped_params = array();

        if (!empty($ns)) {

            $len = strlen($ns);

            foreach ($params as $n => $v) {

                // if namespace is present in param
                if (strstr($n, $ns)) {
                    // strip the namespace value
                    $striped_n = substr($n, $len);
                    //add to striped array
                    $striped_params[$striped_n] = $v;

                }

            }

            return $striped_params;

        } else {

            return $params;
        }

    }


}

?>

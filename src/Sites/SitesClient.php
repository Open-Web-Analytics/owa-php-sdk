<?php

namespace OwaSdk\Sites;
use OwaSdk\OwaClient;
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

class SitesClient extends OwaClient {
	
	var $config;
	
    public function __construct($config = null) {
		
		// parent will override the default config with passed values.
		parent::__construct( $config  );
		
    }
    
    public function listSites() {
	    
	    $request = [
		    
		    'http_method'	=> 'GET',
		    'uri' 			=> '/base/v1/sites'
	    ];
	    
	    return $this->makeRequest( $request );
    }
    
    private function makeRequest( $params ) {
	    
	    $conf = [
			
			'base_uri' => $this->getSetting('instance_url')
		];
		
		print_r( $conf );
	    
	    $http = $this->getHttpClient( $conf );
	    
	    $uri = $this->getSetting('endpoint') . $params['uri'];
	    
	    $request_options = [];
	    
	    if ( array_key_exists('query', $params) && $params[ 'query' ] )  {
		    
			 $request_options[ 'query' ] = $params[ 'query' ];
	    }
	    
	    $credentials = $this->getCredentials();
	    
	    $request_options[ 'headers' ] = [
		    
		    'X-SIGNATURE' => $this->generateRequestSignature( $params, $credentials ),
		    'X-API-KEY' => $credentials['api_key']
		    
	    ];
	    
	    $res = $http->request( $params['http_method'], $uri, $request_options );
	    
	    return $res;
    }

}

?>
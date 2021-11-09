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
	    
	    $res = $this->makeRequest( $request );
		
		return $res;
		
	    
    }
    
    public function addSite( $params ) {
	    
	    $defaults = [
		    
		    'domain' 		=> '',
		    'name' 			=> '',
		    'description' 	=> '',
		    'site_family'	=> ''
		    
	    ];
	    
	    $params = self::setDefaultParams( $defaults, $params );
	
	    $request = [
		    
		    'http_method'	=> 'POST',
		    'uri' 			=> '/base/v1/sites',
		    'form_params'	=> $params
	    ];

	    $res = $this->makeRequest( $request );
	    
	    return $res;
    }
    
}

?>
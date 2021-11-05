<?php

namespace OwaSdk;
use GuzzleHttp\Client;

class OwaClient {
	
	var $config = [];
	
	public function __construct( $config ) {
		
		$this->config = [
			
			'instance_url'						=> '',
            'ns'								=> 'owa_',
            'endpoint'							=> 'api'
		];
		
		// override default config with config array passed in.
		$this->config = array_merge( $this->config, $config );
		
	}
	
	public function getSetting( $name ) {
		
		if ( array_key_exists( $name, $this->config ) ) {
			
			return $this->config[ $name ];
		}
	}
	
	public function setSetting( $name, $value ) {
		
		$this->config[ $name ] = $value;
	}
	
	public function getCredentials() {
		
		static $credentials;
		
		if ( empty ( $credentials ) ) {
			
			$credentials = [
				
				'api_key' => '',
				'auth_key'	=> ''
			];
			
			
			// check environment variables
			$api_key = getenv('OWA_API_KEY');
			
			// check constant
			if ( $api_key ) {
				
				$credentials['api_key'] = $api_key;
				
			} else {
				
				if ( defined('OWA_API_KEY') ) {
					
					$credentials['api_key'] = OWA_API_KEY;
				}
				
			}
			
			// check environment variables
			$auth_key = getenv('OWA_AUTH_KEY');
			
			// check constant
			if ( $auth_key ) {
				
				$credentials['auth_key'] = $api_key;
				
			} else {
				
				if ( defined('OWA_AUTH_KEY') ) {
					
					$credentials['auth_key'] = OWA_AUTH_KEY;
				}
				
			}
			
			//check credentials file in home dir.
			if (! $credentials['api_key'] && ! $credentials['auth_key'] ) {
				
				$file = $this->getHomeDirectory() . '/.owa/credentials';
				
				if ( file_exists( $file ) ){
					
					$file_contents = include( $file );
					
					if ( is_array($file_contents) && array_key_exists( 'api_key', $file_contents ) && array_key_exists( 'auth_key', $file_contents )) {
						
						$credentials = $file_contents;
					}
				}
			}
						
		} 
		
		return $credentials;
	}
	
	private function getHomeDirectory() {
        // On Unix systems, use the HOME environment variable
        if ($home = getenv( 'HOME' ) ) {
            
            return $home;
        }

        // Get the HOMEDRIVE and HOMEPATH values for Windows hosts
        $home_drive = getenv('HOMEDRIVE');
        $home_path = getenv('HOMEPATH');

        return ($home_drive && $home_path) ? $home_drive . $home_path : null;
    }
	
	public function getHttpClient( $params = [] ) {
		
		return new Client( $params );
		
	}
	
	public function generateRequestSignature( $request, $credentials = [] ) {
		
		if ( ! $credentials ) {
		
			$credentials = $this->getCredentials();
		}
	   
	    $url = $this->getSetting( 'instance_url' ) . $this->getSetting( 'endpoint' ) . $request['uri'];
	    
	    if ( array_key_exists('query', $request ) ) {
		    
		    $url .= '?' . http_build_query( $request['query'] );
	    }
	    
	    $date = date( 'Ymd' );
	    
	    return base64_encode( hash('sha256', 'OWASIGNATURE' . $credentials['api_key'] . $url . $date . $credentials['auth_key'] ) );
	}
}	
	
?>
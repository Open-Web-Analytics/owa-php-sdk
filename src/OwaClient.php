<?php

namespace OwaSdk;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;

class OwaClient {
	
	var $config = [];
	var $last_response = null;
	
	public function __construct( $config ) {
		
		$this->config = [
			
			'instance_url'						=> '',
            'ns'								=> 'owa_',
            'endpoint'							=> 'api',
            'credentials'						=> [],
            'debug'								=> false
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
		
		if ( empty ( $this->config['credentials'] ) ) {
			
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
					
					$file_contents = parse_ini_file( $file );
					$file_contents = $file_contents['defaults'];
					
					if ( is_array($file_contents) && array_key_exists( 'api_key', $file_contents ) && array_key_exists( 'auth_key', $file_contents )) {
						
						$credentials = $file_contents;
					}
				}
			}
			
			$this->config['credentials'] = $credentials;
		} 
		
		return $this->config['credentials'];
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
		
		// get the current time zone
		$tz = date_default_timezone_get();
		
		// switch to UTC for signature check
		date_default_timezone_set('UTC');
		
		// generate date
	    $date = date( 'Ymd', time() );
	    
		// return the time zone to prior state
		date_default_timezone_set($tz);
	   
	    return base64_encode( hash('sha256', 'OWASIGNATURE' . $credentials['api_key'] . $url . $date . $credentials['auth_key'] ) );
	}
	
	public function makeRequest( $params ) {
	    
	    $conf = [
			
			'base_uri' => $this->getSetting('instance_url'),
			
		];
	    
	    $http = $this->getHttpClient( $conf );
	    
	    //$http->setUserAgent('OWA SDK Client', true);
	    
	    $uri = $this->getSetting('endpoint') . $params['uri'];
	    
	    $request_options = [];
	    
	    if ( array_key_exists('query', $params) && $params[ 'query' ] )  {
		    
			 $request_options[ 'query' ] = $params[ 'query' ];
	    }
	    
	    if ( array_key_exists('form_params', $params) && $params[ 'form_params' ] )  {
		    
			 $request_options[ 'form_params' ] = $this->ns( $params[ 'form_params' ] );
	    }
	    
	    $credentials = $this->getCredentials();
	    
	    $request_options[ 'headers' ] = [
		    
		    'X-SIGNATURE' => $this->generateRequestSignature( $params, $credentials ),
		    'X-API-KEY' => $credentials['api_key'],
		    'User-Agent' => 'OWA SDK Client',
			'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9'
		    
	    ];
	    
	    $res = null;
	    
	    try{
		    
		    $res = $http->request( $params['http_method'], $uri, $request_options );
		    
		    
	    } 
	    
	    catch( RequestException | ConnectException | ClientException $e ) {
		     
		    $r = $e->getRequest();
		  	$res = null;
		  	
		  	error_log( print_r( $r, true ) );
		  	
		  	if ( $e->hasResponse() ) {
			  	
			  	$res = $e->getResponse();
			  	
			  	error_log( print_r( $res, true ) );
		  	}
		  	
		    if ( $this->getSetting( 'debug' ) ) {
			 	
			 	print_r($r);
			 	print_r($res);   
			}
	    }
	    
	    if ( $res ) {
		    
		    $this->last_response = $res ;
		
		    $b =  $res->getBody();
		    
		    if ( $b ) {
			    
			    $b = json_decode( $b, true);
			    
			    if ( $b && array_key_exists( 'data', $b ) ) {
				    
				    return $b['data'];
			    }
			}
	    }    
    }
    
    public static function setDefaultParams( $defaults, $params ) {
	    
	    if ( is_array( $defaults ) && is_array( $params ) ) {
	    
	    	return array_merge( $defaults, array_filter( $params ) );
	    }
    }
    
    public function ns( $value ) {
	    
	    $ns = $this->getSetting('ns');
	    
	    if ( is_array( $value ) ) {
		    
		    $new = [];
		    
		    foreach ( $value as $k => $v ) {
			    
			    $new[ $ns . $k ] = $v;
			    
		    }
		    
		    return $new;
	    }
	    
	    if ( is_string( $value ) ) {
		    
		    return $ns . trim( $value );
	    }
    }
    
}	
	
?>
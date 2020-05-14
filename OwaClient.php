<?php

namespace OwaSdk;

class OwaClient {
	
	public function __construct( $config ) {
		
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
}	
	
?>
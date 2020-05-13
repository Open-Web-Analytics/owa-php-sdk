<?php

namespace OwaSdk;

class OwaClient {
	
	public function __construct() {
		
		
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
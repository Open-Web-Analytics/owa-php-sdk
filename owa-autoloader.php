<?php

$mapping = [
	'OwaSdk\sdk'					=> __DIR__ . '/src/sdk.php',
	'OwaSdk\OwaClient'				=> __DIR__ . '/src/OwaClient.php',
	'OwaSdk\Tracker\TrackerClient' 	=> __DIR__ . '/src/Tracker/TrackerClient.php',
	'OwaSdk\Tracker\State' 			=> __DIR__ . '/src/Tracker/State.php',
	'OwaSdk\Tracker\TrackingEvent'	=> __DIR__ . '/src/Tracker/TrackingEvent.php'
	
];


spl_autoload_register(function ($class) use ($mapping) {
	
    if (isset($mapping[$class])) {
	    
        require $mapping[$class];
    }
    
}, true);

require_once( __DIR__ . '/vendor/autoload.php' );

?>
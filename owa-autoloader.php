<?php

$mapping = [
	'OwaSdk\sdk'					=> __DIR__ . '/sdk.php',
	'OwaSdk\OwaClient'				=> __DIR__ . '/OwaClient.php',
	'OwaSdk\Tracker\TrackerClient' 	=> __DIR__ . '/Tracker/TrackerClient.php',
	'OwaSdk\Tracker\State' 			=> __DIR__ . '/Tracker/State.php',
	'OwaSdk\Tracker\TrackingEvent'	=> __DIR__ . '/Tracker/TrackingEvent.php'
	
];


spl_autoload_register(function ($class) use ($mapping) {
	
    if (isset($mapping[$class])) {
	    
        require $mapping[$class];
    }
    
}, true);
	
?>
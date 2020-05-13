<?php

namespace OwaSdk\Tracker;
//
// Open Web Analytics - The Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

/**
 * Tracking Event Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 *
 */

class TrackingEvent {

    /**
     * Event Properties
     *
     * @var array
     */
    var $properties = array();

    /**
     * State
     *
     * @var string
     */
    //var $state;

    var $eventType;

    /**
     * Event guid
     *
     * @var string
     */
    var $guid;

    /**
     * Creation Timestamp in UNIX EPOC UTC
     *
     * @var int
     */
    var $timestamp;

    /**
     * Constructor
     * @access public
     */
    function __construct() {

        // Set GUID for event
        $this->guid = $this->set_guid();
        $this->timestamp = time();
        //needed?
        $this->set('guid', $this->guid);
        $this->set('timestamp', $this->timestamp );
    }

    function getTimestamp() {

        return $this->timestamp;
    }

    function set($name, $value) {

        $this->properties[$name] = $value;
    }

    function get($name) {

        if(array_key_exists($name, $this->properties)) {
            //print_r($this->properties[$name]);
            return $this->properties[$name];
        } else {
            return false;
        }
    }

    /**
     * Adds new properties to the eventt without overwriting values
     * for properties that are already set.
     *
     * @param     array $properties
     */
    function setNewProperties( $properties = array() ) {

        $this->properties = array_merge($properties, $this->properties);

    }

    /**
     * Create guid from process id
     *
     * @return    integer
     * @access     private
     */
    function set_guid() {

        return $this->generateRandomUid();
    }
    
    private function generateRandomUid($seed='') {

        $time = (string) time();
        $random = $this->zeroFill( mt_rand( 0, 999999 ), 6 );
        
        $server = substr( getmypid(), 0, 3);
        

        return $time.$random.$server;
    }

	private function zeroFill( $number, $char_length ) {

        return str_pad( (int) $number, $char_length, "0", STR_PAD_LEFT );
    }

    function getProperties() {

        return $this->properties;
    }

    function getEventType() {

        if (!empty($this->eventType)) {
            return $this->eventType;
        } elseif ($this->get('event_type')) {
            return $this->get('event_type');
        } else {

            return 'unknown_event_type';
        }
    }

    function setEventType($value) {
        $this->eventType = $value;
    }

    function getGuid() {

        return $this->guid;
    }
	
	// move this to the tracker
    function getSiteSpecificGuid($site_id) {

        return $this->generateRandomUid( $site_id );
    }

}

?>
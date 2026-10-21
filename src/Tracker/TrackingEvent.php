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
     * @var array<string, mixed>
     */
    public array $properties = [];

    public string $eventType = '';

    /**
     * Event guid
     */
    public string $guid;

    /**
     * Creation Timestamp in UNIX EPOC UTC
     */
    public int $timestamp;

    public function __construct() {

        $this->guid = self::generateRandomGuid();
        $this->timestamp = time();
        $this->set( 'guid', $this->guid );
        $this->set( 'timestamp', $this->timestamp );
    }

    public function getTimestamp(): int {

        return $this->timestamp;
    }

    public function set( string $name, mixed $value ): void {

        $this->properties[ $name ] = $value;
    }

    /**
     * The property's value, or false when it is not set.
     */
    public function get( string $name ): mixed {

        return array_key_exists( $name, $this->properties ) ? $this->properties[ $name ] : false;
    }

    public function isSet( string $name ): bool {

        return array_key_exists( $name, $this->properties );
    }

    /**
     * Adds new properties to the event without overwriting values
     * for properties that are already set.
     *
     * @param array<string, mixed> $properties
     */
    public function setNewProperties( array $properties = [] ): void {

        $this->properties = array_merge( $properties, $this->properties );
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array {

        return $this->properties;
    }

    public function getEventType(): string {

        if ( $this->eventType !== '' ) {

            return $this->eventType;
        }

        $type = $this->get( 'event_type' );

        return $type ? (string) $type : 'unknown_event_type';
    }

    public function setEventType( string $value ): void {

        $this->eventType = $value;
    }

    public function getGuid(): string {

        return $this->guid;
    }

    /**
     * @deprecated ids take no salt; use generateRandomGuid().
     */
    public function getSiteSpecificGuid( mixed $site_id = '' ): string {

        return self::generateRandomGuid();
    }

    /**
     * A numeric id: 10 digits of unix seconds followed by 9 random digits.
     *
     * The same construction as Util.generateRandomGuid() in the JS tracker.
     * It must fit a signed BIGINT, so it takes no salt.
     */
    public static function generateRandomGuid(): string {

        return time() . str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT ) . str_pad( (string) random_int( 0, 999 ), 3, '0', STR_PAD_LEFT );
    }
}

<?php

namespace OwaSdk\Sites;
use OwaSdk\OwaClient;

/**
 * Open Web Analytics - An Open Source Web Analytics Framework
 * Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
 *
 */

/**
 * Sites REST client
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 */
class SitesClient extends OwaClient {

    /**
     * GET /base/v1/sites. Needs the view_site_list capability.
     *
     * @return array<mixed>|null the sites, or null on failure
     */
    public function listSites(): ?array {

        $request = [

            'http_method'   => 'GET',
            'uri'           => '/base/v1/sites'
        ];

        $res = $this->makeRequest( $request );

        return is_array( $res ) ? $res : null;
    }

    /**
     * POST /base/v1/sites. Needs the edit_sites capability.
     *
     * Accepted params: domain (required for web streams), name (required
     * unless propertyId names an existing property), description,
     * site_family, propertyId, streamType, appId (required for app streams).
     *
     * OWA 1.14 does not reject a duplicate domain, so each call creates a site.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>|null the created site, or null on failure (see getLastErrors())
     */
    public function addSite( array $params ): ?array {

        $defaults = [

            'domain'        => '',
            'name'          => '',
            'description'   => '',
            'site_family'   => ''

        ];

        $params = self::setDefaultParams( $defaults, $params );

        $request = [

            'http_method'   => 'POST',
            'uri'           => '/base/v1/sites',
            'form_params'   => $params
        ];

        $res = $this->makeRequest( $request );

        return is_array( $res ) ? $res : null;
    }
}

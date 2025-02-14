<?php
namespace HollerCacheControl\Cache;

/**
 * Handles Cloudflare API operations
 */
class Cloudflare {
    /**
     * Get Cloudflare API credentials
     * 
     * @return array Array containing email, api_key, and zone_id
     */
    public static function get_credentials() {
        return array(
            'email' => defined('CLOUDFLARE_EMAIL') ? CLOUDFLARE_EMAIL : get_option('cloudflare_email'),
            'api_key' => defined('CLOUDFLARE_API_KEY') ? CLOUDFLARE_API_KEY : get_option('cloudflare_api_key'),
            'zone_id' => defined('CLOUDFLARE_ZONE_ID') ? CLOUDFLARE_ZONE_ID : get_option('cloudflare_zone_id')
        );
    }

    /**
     * Check if Cloudflare credentials are set
     * 
     * @return bool True if credentials are set, false otherwise
     */
    public static function are_credentials_set() {
        $credentials = self::get_credentials();
        return !empty($credentials['email']) && !empty($credentials['api_key']) && !empty($credentials['zone_id']);
    }

    /**
     * Get Cloudflare status
     *
     * @return array
     */
    public static function get_status() {
        if (!self::are_credentials_set()) {
            return array(
                'active' => false,
                'details' => __('Not Configured', 'holler-cache-control')
            );
        }

        $credentials = self::get_credentials();
        error_log('Checking Cloudflare credentials - Zone ID: ' . $credentials['zone_id']);

        // First verify the zone ID by listing all zones
        $page = 1;
        $zone_found = false;
        $correct_zone_id = '';

        while (true) {
            $response = wp_remote_get(
                "https://api.cloudflare.com/client/v4/zones?page={$page}&per_page=50",
                array(
                    'headers' => array(
                        'X-Auth-Email' => $credentials['email'],
                        'X-Auth-Key' => $credentials['api_key'],
                        'Content-Type' => 'application/json'
                    )
                )
            );

            $zones_body = json_decode(wp_remote_retrieve_body($response), true);
            error_log('Zones Response Page ' . $page . ': ' . print_r($zones_body, true));

            if (is_wp_error($response)) {
                error_log('Zones Error: ' . $response->get_error_message());
                return array(
                    'active' => false,
                    'details' => $response->get_error_message()
                );
            }

            if (empty($zones_body) || !isset($zones_body['success']) || !$zones_body['success']) {
                error_log('Zones Error Response: ' . print_r($zones_body, true));
                return array(
                    'active' => false,
                    'details' => isset($zones_body['errors'][0]['message']) ? $zones_body['errors'][0]['message'] : __('API Error', 'holler-cache-control')
                );
            }

            // Find our zone in the list
            if (!empty($zones_body['result'])) {
                foreach ($zones_body['result'] as $zone) {
                    error_log('Checking zone: ' . $zone['name'] . ' with ID: ' . $zone['id']);
                    if ($zone['id'] === $credentials['zone_id']) {
                        $zone_found = true;
                        break 2; // Break out of both loops
                    }
                    // Also store the zone ID if the domain matches
                    if (in_array($zone['name'], array('hollerdigital.com', 'www.hollerdigital.com'))) {
                        $correct_zone_id = $zone['id'];
                        break 2; // Break out of both loops
                    }
                }
            }

            // Check if we need to fetch more pages
            if (empty($zones_body['result']) || 
                !isset($zones_body['result_info']['total_pages']) || 
                $page >= $zones_body['result_info']['total_pages']) {
                break;
            }
            $page++;
        }

        if (!$zone_found && !empty($correct_zone_id)) {
            error_log('Found correct zone ID: ' . $correct_zone_id . ' instead of: ' . $credentials['zone_id']);
            // Update the zone ID in options
            update_option('cloudflare_zone_id', $correct_zone_id);
            return array(
                'active' => false,
                'details' => __('Zone ID updated. Please refresh the page.', 'holler-cache-control')
            );
        }

        // Check zone status
        $response = wp_remote_get(
            "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}",
            array(
                'headers' => array(
                    'X-Auth-Email' => $credentials['email'],
                    'X-Auth-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json'
                )
            )
        );

        if (is_wp_error($response)) {
            return array(
                'active' => false,
                'details' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || !isset($body['success']) || !$body['success']) {
            return array(
                'active' => false,
                'details' => isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : __('API Error', 'holler-cache-control')
            );
        }

        // Get development mode status specifically
        $dev_mode_response = wp_remote_get(
            "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/settings/development_mode",
            array(
                'headers' => array(
                    'X-Auth-Email' => $credentials['email'],
                    'X-Auth-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json'
                )
            )
        );

        $dev_mode = 'Unknown';
        if (!is_wp_error($dev_mode_response)) {
            $dev_mode_body = json_decode(wp_remote_retrieve_body($dev_mode_response), true);
            if (!empty($dev_mode_body) && isset($dev_mode_body['success']) && $dev_mode_body['success']) {
                $dev_mode = $dev_mode_body['result']['value'] === 'off' ? 'Off' : 'On';
            }
        }

        // Get cache level setting
        $cache_response = wp_remote_get(
            "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/settings/cache_level",
            array(
                'headers' => array(
                    'X-Auth-Email' => $credentials['email'],
                    'X-Auth-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json'
                )
            )
        );

        $cache_level = 'Unknown';
        if (!is_wp_error($cache_response)) {
            $cache_body = json_decode(wp_remote_retrieve_body($cache_response), true);
            if (!empty($cache_body) && isset($cache_body['success']) && $cache_body['success']) {
                $cache_level = ucfirst($cache_body['result']['value']);
            }
        }

        $zone_data = $body['result'];
        $status = array(
            'active' => $zone_data['status'] === 'active',
            'details' => ucfirst($zone_data['status']),
            'dev_mode' => $dev_mode,
            'cache_level' => $cache_level
        );

        return $status;
    }

    /**
     * Purge Cloudflare Cache
     *
     * @return array
     */
    public static function purge_cache() {
        if (!self::are_credentials_set()) {
            return array(
                'success' => false,
                'message' => __('Cloudflare is not configured', 'holler-cache-control')
            );
        }

        $credentials = self::get_credentials();

        $response = wp_remote_post(
            "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/purge_cache",
            array(
                'headers' => array(
                    'X-Auth-Email' => $credentials['email'],
                    'X-Auth-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array('purge_everything' => true))
            )
        );

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || !isset($body['success']) || !$body['success']) {
            return array(
                'success' => false,
                'message' => isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : __('API Error', 'holler-cache-control')
            );
        }

        return array(
            'success' => true,
            'message' => __('Cache purged successfully', 'holler-cache-control')
        );
    }
}

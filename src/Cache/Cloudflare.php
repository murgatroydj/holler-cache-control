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
     * Get Cloudflare API email
     * 
     * @return string
     */
    private static function get_email() {
        return defined('CLOUDFLARE_EMAIL') ? CLOUDFLARE_EMAIL : get_option('cloudflare_email');
    }

    /**
     * Get Cloudflare API key
     * 
     * @return string
     */
    private static function get_api_key() {
        return defined('CLOUDFLARE_API_KEY') ? CLOUDFLARE_API_KEY : get_option('cloudflare_api_key');
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

        // Get zone ID for the domain
        $zone_id = self::get_zone_id($credentials['zone_id']);

        if (empty($zone_id)) {
            return array(
                'active' => false,
                'details' => __('Zone not found', 'holler-cache-control')
            );
        }

        // Check zone status
        $response = wp_remote_get(
            "https://api.cloudflare.com/client/v4/zones/{$zone_id}",
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
            "https://api.cloudflare.com/client/v4/zones/{$zone_id}/settings/development_mode",
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
            "https://api.cloudflare.com/client/v4/zones/{$zone_id}/settings/cache_level",
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
     * Get zone data from Cloudflare
     * @return array
     */
    private static function get_zone_data() {
        $api_key = self::get_api_key();
        $email = self::get_email();
        
        if (empty($api_key) || empty($email)) {
            return array();
        }

        $args = array(
            'headers' => array(
                'X-Auth-Email' => $email,
                'X-Auth-Key' => $api_key,
                'Content-Type' => 'application/json',
            ),
        );

        // Get zones one page at a time to prevent memory issues
        $page = 1;
        $per_page = 20;
        $zones = array();
        
        do {
            $url = add_query_arg(
                array(
                    'page' => $page,
                    'per_page' => $per_page,
                    'status' => 'active'
                ),
                'https://api.cloudflare.com/client/v4/zones'
            );
            
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                break;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (empty($data['success']) || empty($data['result'])) {
                break;
            }
            
            // Only store essential zone data
            foreach ($data['result'] as $zone) {
                $zones[] = array(
                    'id' => $zone['id'],
                    'name' => $zone['name'],
                    'status' => $zone['status']
                );
            }
            
            // Free up memory
            unset($data);
            unset($body);
            
            if (count($data['result']) < $per_page) {
                break;
            }
            
            $page++;
            
            // Optional: Add a small delay to prevent rate limiting
            usleep(100000); // 100ms delay
            
        } while (true);
        
        return $zones;
    }

    /**
     * Get zone ID for a domain
     * @param string $domain
     * @return string|null
     */
    public static function get_zone_id($domain) {
        $zones = self::get_zone_data();
        
        foreach ($zones as $zone) {
            if ($zone['name'] === $domain && $zone['status'] === 'active') {
                return $zone['id'];
            }
        }
        
        return null;
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

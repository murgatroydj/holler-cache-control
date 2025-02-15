<?php
/**
 * Handles all Cloudflare-related functionality.
 *
 * @package HollerCacheControl
 */

class Holler_Cache_Control_Cloudflare {

    /**
     * Get Cloudflare credentials with priority order: Constants > Database > Empty
     *
     * @return array Array of credentials
     */
    public static function get_credentials() {
        $credential_keys = array(
            'email' => 'CLOUDFLARE_EMAIL',
            'api_key' => 'CLOUDFLARE_API_KEY',
            'zone_id' => 'CLOUDFLARE_ZONE_ID'
        );

        $credentials = array();

        // First try to get from constants
        foreach ($credential_keys as $key => $constant) {
            if (defined($constant)) {
                $credentials[$key] = constant($constant);
            }
        }

        // If any credentials are missing, try to get from database
        foreach ($credential_keys as $key => $constant) {
            if (empty($credentials[$key])) {
                $credentials[$key] = get_option('holler_cache_cloudflare_' . $key);
            }
        }

        return $credentials;
    }

    /**
     * Check if Cloudflare credentials are properly configured
     *
     * @return bool True if all credentials are set
     */
    public static function are_credentials_set() {
        $credentials = self::get_credentials();
        return !empty($credentials['email']) && !empty($credentials['api_key']) && !empty($credentials['zone_id']);
    }

    /**
     * Purge Cloudflare cache
     *
     * @return array Result of the purge operation
     */
    public static function purge_cache() {
        $credentials = self::get_credentials();
        
        if (!self::are_credentials_set()) {
            return array(
                'success' => false,
                'message' => __('Cloudflare credentials are not configured', 'holler-cache-control')
            );
        }

        $purge_url = "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/purge_cache";
        
        $response = wp_remote_post($purge_url, array(
            'headers' => array(
                'X-Auth-Email' => $credentials['email'],
                'X-Auth-Key' => $credentials['api_key'],
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array('purge_everything' => true))
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return array(
            'success' => isset($body['success']) ? $body['success'] : false,
            'message' => isset($body['errors']) ? implode(', ', array_column($body['errors'], 'message')) : __('Cache purged successfully', 'holler-cache-control')
        );
    }

    /**
     * Check Cloudflare cache status
     *
     * @return array Status information
     */
    public static function check_cache_status() {
        $credentials = self::get_credentials();
        
        if (!self::are_credentials_set()) {
            return array(
                'success' => false,
                'message' => __('Cloudflare credentials not configured', 'holler-cache-control')
            );
        }

        $settings_url = "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/settings/cache_level";
        $development_mode_url = "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/settings/development_mode";
        $browser_cache_url = "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/settings/browser_cache_ttl";

        $headers = array(
            'X-Auth-Email' => $credentials['email'],
            'X-Auth-Key' => $credentials['api_key']
        );

        $cache_level = wp_remote_get($settings_url, array('headers' => $headers));
        $dev_mode = wp_remote_get($development_mode_url, array('headers' => $headers));
        $browser_cache = wp_remote_get($browser_cache_url, array('headers' => $headers));

        $status = array();

        if (!is_wp_error($cache_level)) {
            $cache_level_data = json_decode(wp_remote_retrieve_body($cache_level), true);
            if (isset($cache_level_data['result']['value'])) {
                $status['cache_level'] = $cache_level_data['result']['value'];
            }
        }

        if (!is_wp_error($dev_mode)) {
            $dev_mode_data = json_decode(wp_remote_retrieve_body($dev_mode), true);
            if (isset($dev_mode_data['result']['value'])) {
                $status['development_mode'] = $dev_mode_data['result']['value'];
            }
        }

        if (!is_wp_error($browser_cache)) {
            $browser_cache_data = json_decode(wp_remote_retrieve_body($browser_cache), true);
            if (isset($browser_cache_data['result']['value'])) {
                $status['browser_cache_ttl'] = $browser_cache_data['result']['value'];
            }
        }

        return $status;
    }
}

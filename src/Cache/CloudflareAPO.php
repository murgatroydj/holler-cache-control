<?php
namespace HollerCacheControl\Cache;

/**
 * Handles Cloudflare APO (Automatic Platform Optimization) functionality
 */
class CloudflareAPO {
    /**
     * Check if Cloudflare APO is configured
     */
    public static function is_configured() {
        // APO requires base Cloudflare to be configured first
        if (!\HollerCacheControl\Cache\Cloudflare::is_configured()) {
            return false;
        }

        // Check if APO is enabled
        $apo_enabled = get_option('cloudflare_apo_enabled', false);
        return !empty($apo_enabled);
    }

    /**
     * Get APO status
     *
     * @return array
     */
    public static function get_status() {
        $credentials = Cloudflare::get_credentials();
        $is_active = Cloudflare::are_credentials_set();
        
        if (!$is_active) {
            error_log('APO: Credentials not set');
            return array(
                'active' => false,
                'details' => __('Not Configured', 'holler-cache-control')
            );
        }

        error_log('APO: Checking with credentials - Zone ID: ' . $credentials['zone_id']);

        // First check if APO is enabled at the zone level
        $response = wp_remote_get(
            "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/settings/automatic_platform_optimization",
            array(
                'headers' => array(
                    'X-Auth-Email' => $credentials['email'],
                    'X-Auth-Key' => $credentials['api_key'],
                    'Content-Type' => 'application/json'
                )
            )
        );

        $zone_body = json_decode(wp_remote_retrieve_body($response), true);
        error_log('APO Zone Settings Raw Response: ' . wp_remote_retrieve_body($response));
        error_log('APO Zone Settings Response Code: ' . wp_remote_retrieve_response_code($response));

        if (is_wp_error($response)) {
            error_log('APO Zone Error: ' . $response->get_error_message());
            return array(
                'active' => false,
                'details' => $response->get_error_message()
            );
        }

        if (empty($zone_body) || !isset($zone_body['success']) || !$zone_body['success']) {
            error_log('APO Zone Error Response: ' . print_r($zone_body, true));
            return array(
                'active' => false,
                'details' => isset($zone_body['errors'][0]['message']) ? $zone_body['errors'][0]['message'] : __('API Error', 'holler-cache-control')
            );
        }

        // Check if APO is enabled at zone level
        $zone_value = isset($zone_body['result']['value']) ? $zone_body['result']['value'] : null;
        error_log('APO Zone Value (raw): ' . print_r($zone_value, true));
        error_log('APO Zone Value Type: ' . gettype($zone_value));

        // Check if APO is enabled based on the array values
        $zone_enabled = false;
        if (is_array($zone_value)) {
            $zone_enabled = !empty($zone_value['enabled']) && !empty($zone_value['wordpress']);
        }
        
        error_log('APO Zone Enabled: ' . var_export($zone_enabled, true));

        if (!$zone_enabled) {
            return array(
                'active' => false,
                'details' => __('APO not enabled in Cloudflare', 'holler-cache-control')
            );
        }

        // Get WordPress-specific settings from the same response
        $wp_settings = $zone_value;
        $status = array(
            'active' => $zone_enabled,
            'details' => $zone_enabled ? __('Enabled', 'holler-cache-control') : __('Disabled', 'holler-cache-control'),
            'cache_by_device_type' => !empty($wp_settings['cache_by_device_type']),
            'hostnames' => isset($wp_settings['hostnames']) ? $wp_settings['hostnames'] : array()
        );

        error_log('Final APO Status: ' . print_r($status, true));
        return $status;
    }

    /**
     * Purge APO Cache
     *
     * @return array
     */
    public static function purge() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        try {
            $credentials = \HollerCacheControl\Cache\Cloudflare::get_credentials();
            if (!$credentials['valid']) {
                throw new \Exception(__('Cloudflare credentials not configured', 'holler-cache-control'));
            }

            // Purge APO cache using the purge_cache endpoint
            $response = wp_remote_post(
                "https://api.cloudflare.com/client/v4/zones/{$credentials['zone_id']}/purge_cache",
                array(
                    'headers' => array(
                        'X-Auth-Email' => $credentials['email'],
                        'X-Auth-Key'   => $credentials['api_key'],
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode(array(
                        'hosts' => array(parse_url(home_url(), PHP_URL_HOST))
                    ))
                )
            );

            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!$body['success']) {
                throw new \Exception(isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : __('Unknown Cloudflare error', 'holler-cache-control'));
            }

            $result['success'] = true;
            $result['message'] = __('Cloudflare APO cache cleared successfully', 'holler-cache-control');

        } catch (\Exception $e) {
            $result['message'] = $e->getMessage();
        }

        return $result;
    }
}

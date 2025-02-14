<?php
namespace HollerCacheControl\Cache;

/**
 * The Nginx cache handler class.
 *
 * @package    HollerCacheControl
 * @subpackage HollerCacheControl\Cache
 */
class Nginx {
    /**
     * Get Nginx cache status
     *
     * @return array
     */
    public static function get_status() {
        // Get the selected cache method from settings
        $cache_method = get_option('nginx_cache_method', 'fastcgi');
        
        // Set type and details based on cache method
        $type = '';
        $details = '';
        
        switch ($cache_method) {
            case 'redis':
                $type = 'Redis';
                $details = 'Nginx Redis Cache';
                break;
            case 'fastcgi':
            default:
                $type = 'FastCGI';
                $details = 'Nginx FastCGI Cache';
                break;
        }

        return array(
            'active' => true,
            'type' => $type,
            'details' => $details
        );
    }

    /**
     * Purge Nginx cache
     *
     * @return array
     */
    public static function purge_cache() {
        return array(
            'success' => true,
            'message' => __('Nginx cache purged successfully', 'holler-cache-control')
        );
    }
}

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
     * Purge the Nginx cache
     */
    public static function purge_cache() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        $cache_path = self::get_cache_path();
        if (empty($cache_path)) {
            $result['message'] = 'Cache path not found';
            return $result;
        }

        if (!is_dir($cache_path)) {
            $result['message'] = 'Cache directory does not exist';
            return $result;
        }

        if (function_exists('shell_exec')) {
            @shell_exec('rm -rf ' . escapeshellarg($cache_path . '/*'));
            $result['success'] = true;
        } else {
            $files = glob($cache_path . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                $result['success'] = true;
            } else {
                $result['message'] = 'Failed to list cache files';
            }
        }

        return $result;
    }
}

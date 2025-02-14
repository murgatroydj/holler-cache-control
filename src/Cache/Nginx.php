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
     * Get Nginx cache status for GridPane environment
     *
     * @return array
     */
    public static function get_status() {
        $result = array(
            'active' => false,
            'details' => ''
        );

        // Check for Nginx Helper plugin which GridPane requires for page caching
        if (!is_plugin_active('nginx-helper/nginx-helper.php')) {
            $result['details'] = __('Nginx Helper plugin not active', 'holler-cache-control');
            return $result;
        }

        // Get site customizer options from GridPane
        $gridpane_options = get_option('gridpane_site_settings', array());
        
        // Check if Redis page caching is enabled in GridPane
        if (isset($gridpane_options['redis_page_cache']) && $gridpane_options['redis_page_cache'] == 1) {
            try {
                // Check if Redis is responding
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379);
                
                if ($redis->ping()) {
                    $info = $redis->info();
                    $ttl = isset($gridpane_options['redis_page_cache_ttl']) ? $gridpane_options['redis_page_cache_ttl'] : 2592000;
                    
                    $result['active'] = true;
                    $result['details'] = sprintf(
                        __('GridPane Redis Page Cache | TTL: %s | Memory Used: %s', 'holler-cache-control'),
                        human_time_diff(0, $ttl),
                        size_format($info['used_memory'])
                    );
                    return $result;
                }
            } catch (\Exception $e) {
                error_log('GridPane Redis Page Cache error: ' . $e->getMessage());
            }
        }

        // Check if FastCGI cache is enabled
        $cache_path = '/var/cache/nginx';
        if (is_dir($cache_path)) {
            try {
                $size = self::get_directory_size($cache_path);
                $result['active'] = true;
                $result['details'] = sprintf(
                    __('GridPane FastCGI Page Cache | Cache Size: %s', 'holler-cache-control'),
                    size_format($size)
                );
                return $result;
            } catch (\Exception $e) {
                error_log('GridPane FastCGI cache error: ' . $e->getMessage());
            }
        }

        $result['details'] = __('Page Caching is disabled in GridPane', 'holler-cache-control');
        return $result;
    }

    /**
     * Purge Nginx cache for GridPane environment
     *
     * @return array
     */
    public static function purge_cache() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        // Check for Nginx Helper plugin
        if (!is_plugin_active('nginx-helper/nginx-helper.php')) {
            $result['message'] = __('Nginx Helper plugin not active', 'holler-cache-control');
            return $result;
        }

        try {
            // Get site customizer options from GridPane
            $gridpane_options = get_option('gridpane_site_settings', array());
            
            if (isset($gridpane_options['redis_page_cache']) && $gridpane_options['redis_page_cache'] == 1) {
                // Purge Redis Page Cache
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379);
                
                // Use SCAN instead of KEYS for better performance
                $iterator = null;
                $pattern = 'nginx-cache:*';
                while ($keys = $redis->scan($iterator, $pattern, 100)) {
                    foreach ($keys as $key) {
                        $redis->del($key);
                    }
                }
                
                $result['success'] = true;
                $result['message'] = __('GridPane Redis Page Cache purged successfully', 'holler-cache-control');
            } else {
                // Purge FastCGI Cache
                $cache_path = '/var/cache/nginx';
                if (is_dir($cache_path)) {
                    exec('rm -rf ' . escapeshellarg($cache_path . '/*'), $output, $return_var);
                    
                    if ($return_var !== 0) {
                        throw new \Exception(__('Failed to purge GridPane FastCGI Page Cache', 'holler-cache-control'));
                    }
                    
                    $result['success'] = true;
                    $result['message'] = __('GridPane FastCGI Page Cache purged successfully', 'holler-cache-control');
                } else {
                    throw new \Exception(__('Page Caching is disabled in GridPane', 'holler-cache-control'));
                }
            }
        } catch (\Exception $e) {
            error_log('GridPane page cache purge error: ' . $e->getMessage());
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get directory size recursively
     *
     * @param string $path
     * @return int
     */
    private static function get_directory_size($path) {
        $size = 0;
        $files = scandir($path);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $full_path = $path . '/' . $file;
            if (is_dir($full_path)) {
                $size += self::get_directory_size($full_path);
            } else {
                $size += filesize($full_path);
            }
        }

        return $size;
    }
}

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
        if (!class_exists('Nginx_Helper')) {
            $result['details'] = __('Nginx Helper plugin not installed', 'holler-cache-control');
            return $result;
        }

        // Check if any page caching is enabled in GridPane
        $cache_path = '/var/cache/nginx';
        $redis_conf = '/etc/nginx/conf.d/redis.conf';
        
        if (file_exists($redis_conf)) {
            // Redis Page Cache is enabled
            try {
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379);
                $info = $redis->info();
                
                $result['active'] = true;
                $result['details'] = sprintf(
                    __('GridPane Redis Page Cache | Memory Used: %s', 'holler-cache-control'),
                    size_format($info['used_memory'])
                );
                return $result;
            } catch (\Exception $e) {
                error_log('GridPane Redis Page Cache error: ' . $e->getMessage());
            }
        } elseif (is_dir($cache_path)) {
            // FastCGI Page Cache is enabled
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
        if (!class_exists('Nginx_Helper')) {
            $result['message'] = __('Nginx Helper plugin not installed', 'holler-cache-control');
            return $result;
        }

        try {
            $cache_path = '/var/cache/nginx';
            $redis_conf = '/etc/nginx/conf.d/redis.conf';
            
            if (file_exists($redis_conf)) {
                // Purge Redis Page Cache
                $redis = new \Redis();
                $redis->connect('127.0.0.1', 6379);
                $redis->flushDb(); // Redis page cache typically uses its own DB
                
                $result['success'] = true;
                $result['message'] = __('GridPane Redis Page Cache purged successfully', 'holler-cache-control');
            } elseif (is_dir($cache_path)) {
                // Purge FastCGI Page Cache
                exec('rm -rf ' . escapeshellarg($cache_path . '/*'), $output, $return_var);
                
                if ($return_var !== 0) {
                    throw new \Exception(__('Failed to purge GridPane FastCGI Page Cache', 'holler-cache-control'));
                }
                
                $result['success'] = true;
                $result['message'] = __('GridPane FastCGI Page Cache purged successfully', 'holler-cache-control');
            } else {
                throw new \Exception(__('Page Caching is disabled in GridPane', 'holler-cache-control'));
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

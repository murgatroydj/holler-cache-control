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

        // GridPane's configuration paths
        $nginx_conf = '/etc/nginx/sites-enabled/' . parse_url(home_url(), PHP_URL_HOST);
        $redis_conf = '/etc/nginx/conf.d/redis.conf';
        $cache_path = '/var/cache/nginx';

        // Check if Redis page caching is enabled in GridPane
        if (file_exists($redis_conf)) {
            $redis_config = file_get_contents($redis_conf);
            if (strpos($redis_config, 'srcache_store_pass') !== false) {
                try {
                    $redis = new \Redis();
                    if ($redis->connect('127.0.0.1', 6379)) {
                        $info = $redis->info();
                        
                        // Count GridPane page cache keys
                        $page_keys = 0;
                        $iterator = null;
                        while ($keys = $redis->scan($iterator, 'nginx-cache:*', 100)) {
                            $page_keys += count($keys);
                        }
                        
                        $result['active'] = true;
                        $result['details'] = sprintf(
                            __('GridPane Redis Page Cache | Memory: %s | Cache Keys: %d', 'holler-cache-control'),
                            size_format($info['used_memory']),
                            $page_keys
                        );
                        return $result;
                    }
                } catch (\Exception $e) {
                    error_log('GridPane Redis Page Cache error: ' . $e->getMessage());
                }
            }
        }

        // Check if FastCGI caching is enabled
        if (file_exists($nginx_conf) && is_dir($cache_path)) {
            $nginx_config = file_get_contents($nginx_conf);
            if (strpos($nginx_config, 'fastcgi_cache_use_stale') !== false) {
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

        try {
            // GridPane's configuration paths
            $redis_conf = '/etc/nginx/conf.d/redis.conf';
            $cache_path = '/var/cache/nginx';

            // Check if Redis page caching is enabled
            if (file_exists($redis_conf)) {
                $redis_config = file_get_contents($redis_conf);
                if (strpos($redis_config, 'srcache_store_pass') !== false) {
                    $redis = new \Redis();
                    if (!$redis->connect('127.0.0.1', 6379)) {
                        throw new \Exception(__('Could not connect to Redis server', 'holler-cache-control'));
                    }

                    // Purge GridPane's Redis page cache
                    $iterator = null;
                    $pattern = 'nginx-cache:*';
                    while ($keys = $redis->scan($iterator, $pattern, 100)) {
                        foreach ($keys as $key) {
                            $redis->del($key);
                        }
                    }

                    $result['success'] = true;
                    $result['message'] = __('GridPane Redis Page Cache purged successfully', 'holler-cache-control');
                    return $result;
                }
            }

            // Check if FastCGI cache exists
            if (is_dir($cache_path)) {
                exec('rm -rf ' . escapeshellarg($cache_path . '/*'), $output, $return_var);
                
                if ($return_var !== 0) {
                    throw new \Exception(__('Failed to purge GridPane FastCGI Page Cache', 'holler-cache-control'));
                }
                
                $result['success'] = true;
                $result['message'] = __('GridPane FastCGI Page Cache purged successfully', 'holler-cache-control');
                return $result;
            }

            throw new \Exception(__('Page Caching is disabled in GridPane', 'holler-cache-control'));
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

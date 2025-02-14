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

        error_log("Checking Redis page cache status");
        
        // Make a test request to check headers
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        $url = home_url('/');
        error_log("Making test request to: " . $url);
        
        $args = array(
            'method' => 'GET',
            'headers' => array(
                'Host' => $site_host,
                'X-Test' => '1'
            ),
            'timeout' => 5,
            'sslverify' => false
        );
        
        $response = wp_remote_request($url, $args);
        if (!is_wp_error($response)) {
            $headers = wp_remote_retrieve_headers($response);
            error_log("Response headers: " . print_r($headers, true));
            
            // Check for GridPane Redis cache headers
            if (isset($headers['x-grid-cache']) || isset($headers['x-grid-srcache-ttl'])) {
                error_log("Found GridPane Redis cache headers");
                
                try {
                    // Try to connect to Redis
                    $redis = new \Redis();
                    if ($redis->connect('127.0.0.1', 6379)) {
                        error_log("Connected to Redis");
                        
                        // Get Redis info
                        $info = $redis->info();
                        $used_memory = isset($info['used_memory']) ? $info['used_memory'] : 0;
                        error_log("Redis memory usage: " . $used_memory . " bytes");

                        // Redis is writable and Nginx is configured for page caching
                        $result['active'] = true;
                        $result['details'] = sprintf(
                            __('GridPane Redis Page Cache | Memory: %s', 'holler-cache-control'),
                            size_format($used_memory)
                        );

                        // Add note about being logged in
                        if (is_user_logged_in()) {
                            $result['details'] .= ' ' . __('(Cache bypassed while logged in)', 'holler-cache-control');
                        }
                        
                        error_log("Cache status: active");
                        return $result;
                    } else {
                        error_log("Could not connect to Redis");
                    }
                } catch (\Exception $e) {
                    error_log('GridPane Redis Page Cache error: ' . $e->getMessage());
                }
            } else {
                error_log("GridPane Redis cache headers not found");
            }
        } else {
            error_log("WP HTTP request error: " . $response->get_error_message());
        }

        // Check if FastCGI caching is enabled
        $cache_path = '/var/cache/nginx';
        if (is_dir($cache_path)) {
            try {
                $size = self::get_directory_size($cache_path);
                $result['active'] = true;
                $result['details'] = sprintf(
                    __('GridPane FastCGI Page Cache | Cache Size: %s', 'holler-cache-control'),
                    size_format($size)
                );
                error_log("FastCGI cache found");
                return $result;
            } catch (\Exception $e) {
                error_log('GridPane FastCGI cache error: ' . $e->getMessage());
            }
        }

        error_log("No caching detected");
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
            $site_host = parse_url(home_url(), PHP_URL_HOST);
            $nginx_conf = "/etc/nginx/common/{$site_host}-wp-redis.conf";

            // Check if Redis page caching is enabled
            if (file_exists($nginx_conf)) {
                $redis_config = file_get_contents($nginx_conf);
                if (strpos($redis_config, 'srcache_fetch') !== false && strpos($redis_config, 'srcache_store') !== false) {
                    $redis = new \Redis();
                    if (!$redis->connect('127.0.0.1', 6379)) {
                        throw new \Exception(__('Could not connect to Redis server', 'holler-cache-control'));
                    }

                    // Purge GridPane's Redis page cache for this site
                    $iterator = null;
                    $pattern = 'nginx-cache:*' . $site_host . '*';
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
            $cache_path = '/var/cache/nginx';
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

<?php
namespace HollerCacheControl\Cache;

/**
 * Handles Redis Cache operations
 *
 * @package    HollerCacheControl
 * @subpackage HollerCacheControl\Cache
 */
class Redis {
    /**
     * Get Redis connection
     *
     * @return \Redis|null
     */
    private static function get_redis_connection() {
        try {
            $redis = new \Redis();
            $socket = '/var/run/redis/redis-server.sock';
            
            if ($redis->connect($socket)) {
                // Try to select the correct database if configured
                if (defined('WP_REDIS_DATABASE')) {
                    $redis->select(WP_REDIS_DATABASE);
                }
                return $redis;
            }
        } catch (\Exception $e) {
            error_log('Redis Connection Error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Get Redis status for GridPane environment
     *
     * @return array
     */
    public static function get_status() {
        $result = array(
            'active' => false,
            'details' => ''
        );

        try {
            $redis = new \Redis();
            if (!$redis->connect('127.0.0.1', 6379)) {
                $result['details'] = __('Could not connect to Redis server', 'holler-cache-control');
                return $result;
            }

            $info = $redis->info();
            
            // Count WordPress object cache keys
            $wp_keys = 0;
            $iterator = null;
            while ($keys = $redis->scan($iterator, 'wp_*', 100)) {
                $wp_keys += count($keys);
            }

            // Count GridPane page cache keys
            $page_keys = 0;
            $iterator = null;
            while ($keys = $redis->scan($iterator, 'nginx-cache:*', 100)) {
                $page_keys += count($keys);
            }

            if ($wp_keys > 0 || $page_keys > 0) {
                $result['active'] = true;
                $result['details'] = sprintf(
                    __('Redis Cache | Memory: %s | Object Keys: %d | Page Keys: %d', 'holler-cache-control'),
                    size_format($info['used_memory']),
                    $wp_keys,
                    $page_keys
                );
            } else {
                $result['details'] = __('Redis is running but no cache keys found', 'holler-cache-control');
            }
        } catch (\Exception $e) {
            error_log('Redis status error: ' . $e->getMessage());
            $result['details'] = 'redis: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Purge Redis cache for GridPane environment
     *
     * @return array
     */
    public static function purge_cache() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        try {
            $redis = new \Redis();
            if (!$redis->connect('127.0.0.1', 6379)) {
                throw new \Exception(__('Could not connect to Redis server', 'holler-cache-control'));
            }

            // First try to purge object cache if Redis Object Cache plugin is active
            if (is_plugin_active('redis-cache/redis-cache.php')) {
                // Use the plugin's purge method if available
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
            }

            // Now purge GridPane's Redis object cache directly
            $iterator = null;
            $pattern = 'wp_*'; // WordPress object cache keys
            while ($keys = $redis->scan($iterator, $pattern, 100)) {
                foreach ($keys as $key) {
                    $redis->del($key);
                }
            }

            // Also purge GridPane's Redis page cache
            $iterator = null;
            $pattern = 'nginx-cache:*'; // GridPane's Redis page cache keys
            while ($keys = $redis->scan($iterator, $pattern, 100)) {
                foreach ($keys as $key) {
                    $redis->del($key);
                }
            }

            $result['success'] = true;
            $result['message'] = __('Redis cache purged successfully', 'holler-cache-control');
        } catch (\Exception $e) {
            error_log('Redis purge error: ' . $e->getMessage());
            $result['message'] = 'redis: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Purge only the WordPress object cache in Redis
     *
     * @return array
     */
    public static function purge_object_cache() {
        // Check if redis object cache plugin is active
        if (!is_plugin_active('redis-cache/redis-cache.php')) {
            return array(
                'success' => false,
                'message' => __('Redis Object Cache plugin is not active', 'holler-cache-control')
            );
        }

        // Get the redis object cache instance
        $redis = \WP_Redis::instance();
        if (!$redis) {
            return array(
                'success' => false,
                'message' => __('Redis connection not available', 'holler-cache-control')
            );
        }

        // Flush only WordPress object cache
        wp_cache_flush();

        return array(
            'success' => true,
            'message' => __('Redis object cache purged successfully', 'holler-cache-control')
        );
    }
}

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

        // Check if Redis Object Cache plugin is active and enabled
        if (!class_exists('Redis_Object_Cache') || !defined('WP_REDIS_DISABLED') || WP_REDIS_DISABLED) {
            $result['details'] = __('Redis Object Cache is disabled in GridPane', 'holler-cache-control');
            return $result;
        }

        // Additional check for Redis plugin status
        if (function_exists('redis_object_cache')) {
            $diagnostics = redis_object_cache()->diagnostics();
            if (isset($diagnostics['status']) && $diagnostics['status'] !== 'connected') {
                $result['details'] = __('Redis Object Cache is disabled in GridPane', 'holler-cache-control');
                return $result;
            }
        }

        // Now check if Redis server is running and accessible
        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $ping = $redis->ping();
            
            if ($ping === true || $ping === '+PONG') {
                $info = $redis->info();
                if ($info) {
                    // Double check the object cache is actually using Redis
                    global $wp_object_cache;
                    if (!$wp_object_cache || !method_exists($wp_object_cache, 'redis_status') || !$wp_object_cache->redis_status()) {
                        $result['details'] = __('Redis Object Cache is disabled in GridPane', 'holler-cache-control');
                        return $result;
                    }

                    $result['active'] = true;
                    $result['details'] = sprintf(
                        __('GridPane Redis Object Cache | Memory Used: %s | Uptime: %s', 'holler-cache-control'),
                        size_format($info['used_memory']),
                        human_time_diff(time() - $info['uptime_in_seconds'])
                    );
                    return $result;
                }
            }
        } catch (\Exception $e) {
            error_log('GridPane Redis error: ' . $e->getMessage());
            $result['details'] = __('Redis not running on GridPane server', 'holler-cache-control');
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

        // Check if Redis Object Cache plugin is active and enabled
        if (!class_exists('Redis_Object_Cache') || !defined('WP_REDIS_DISABLED') || WP_REDIS_DISABLED) {
            $result['message'] = __('Redis Object Cache is disabled in GridPane', 'holler-cache-control');
            return $result;
        }

        // Additional check for Redis plugin status
        if (function_exists('redis_object_cache')) {
            $diagnostics = redis_object_cache()->diagnostics();
            if (isset($diagnostics['status']) && $diagnostics['status'] !== 'connected') {
                $result['message'] = __('Redis Object Cache is disabled in GridPane', 'holler-cache-control');
                return $result;
            }
        }

        try {
            global $wp_object_cache;
            if (!$wp_object_cache || !method_exists($wp_object_cache, 'redis_status') || !$wp_object_cache->redis_status()) {
                throw new \Exception(__('Redis Object Cache is disabled in GridPane', 'holler-cache-control'));
            }

            // Connect to GridPane Redis
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            
            // Flush Redis database
            $redis->select(0); // Object cache typically uses DB 0
            $redis->flushDb();
            
            // Also clear WordPress object cache
            if (method_exists($wp_object_cache, 'flush')) {
                $wp_object_cache->flush();
            }
            
            $result['success'] = true;
            $result['message'] = __('GridPane Redis Object Cache purged successfully', 'holler-cache-control');

        } catch (\Exception $e) {
            error_log('GridPane Redis purge error: ' . $e->getMessage());
            $result['message'] = __('Failed to purge GridPane Redis Object Cache', 'holler-cache-control');
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

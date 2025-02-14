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

        // GridPane always uses Redis on localhost:6379
        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $ping = $redis->ping();
            
            if ($ping === true || $ping === '+PONG') {
                $info = $redis->info();
                if ($info) {
                    $result['active'] = true;
                    $result['details'] = sprintf(
                        __('GridPane Redis Cache | Memory Used: %s | Uptime: %s', 'holler-cache-control'),
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

        try {
            // Connect to GridPane Redis
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            
            // Flush both object cache and page cache DBs
            $redis->flushAll(); // Clear all databases
            
            // Also clear WordPress object cache
            global $wp_object_cache;
            if ($wp_object_cache && method_exists($wp_object_cache, 'flush')) {
                $wp_object_cache->flush();
            }
            
            $result['success'] = true;
            $result['message'] = __('GridPane Redis cache purged successfully', 'holler-cache-control');

        } catch (\Exception $e) {
            error_log('GridPane Redis purge error: ' . $e->getMessage());
            $result['message'] = __('Failed to purge GridPane Redis cache', 'holler-cache-control');
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

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
     * Purge Redis cache
     *
     * @return array
     */
    public static function purge_cache() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        try {
            global $wp_object_cache;
            
            if (!$wp_object_cache) {
                throw new \Exception(__('Object cache not available', 'holler-cache-control'));
            }

            if (!method_exists($wp_object_cache, 'redis_status') || !$wp_object_cache->redis_status()) {
                throw new \Exception(__('Redis not connected', 'holler-cache-control'));
            }

            // Try to flush Redis directly first
            if (method_exists($wp_object_cache, 'redis_instance')) {
                $redis = $wp_object_cache->redis_instance();
                if ($redis) {
                    $redis->flushdb();
                }
            }

            // Always flush the object cache
            $wp_object_cache->flush();
            
            $result['success'] = true;
            $result['message'] = __('Redis cache purged successfully', 'holler-cache-control');

        } catch (\Exception $e) {
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get Redis status
     *
     * @return array
     */
    public static function get_status() {
        $result = array(
            'active' => false,
            'details' => ''
        );

        // Check if Redis is available on the system
        if (!class_exists('Redis')) {
            $result['details'] = __('Redis PHP extension not installed', 'holler-cache-control');
            return $result;
        }

        // Check if Redis Object Cache plugin is active and configured
        if (!class_exists('Redis_Object_Cache') || !defined('WP_REDIS_HOST')) {
            $result['details'] = __('Redis Object Cache plugin not configured', 'holler-cache-control');
            return $result;
        }

        // Check if Redis is disabled
        if (defined('WP_REDIS_DISABLED') && WP_REDIS_DISABLED) {
            $result['details'] = __('Redis is disabled in wp-config.php', 'holler-cache-control');
            return $result;
        }

        // Check if object cache is working
        global $wp_object_cache;

        if (!$wp_object_cache || !method_exists($wp_object_cache, 'redis_status')) {
            $result['details'] = __('Redis Object Cache not initialized', 'holler-cache-control');
            return $result;
        }

        try {
            // Use the built-in status check
            if (!$wp_object_cache->redis_status()) {
                $result['details'] = __('Redis connection not established', 'holler-cache-control');
                return $result;
            }

            // Get Redis instance for more details
            if (method_exists($wp_object_cache, 'redis_instance')) {
                $redis = $wp_object_cache->redis_instance();
                
                if ($redis) {
                    $info = $redis->info();
                    if ($info) {
                        $result['active'] = true;
                        $result['details'] = sprintf(
                            __('Version: %s, Memory Used: %s, Uptime: %s', 'holler-cache-control'),
                            $info['redis_version'],
                            size_format($info['used_memory']),
                            human_time_diff(time() - $info['uptime_in_seconds'])
                        );
                        return $result;
                    }
                }
            }

            // Fallback if we can't get detailed info
            $result['active'] = true;
            $result['details'] = __('Redis is connected and running', 'holler-cache-control');

        } catch (\Exception $e) {
            $result['details'] = sprintf(__('Redis error: %s', 'holler-cache-control'), $e->getMessage());
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

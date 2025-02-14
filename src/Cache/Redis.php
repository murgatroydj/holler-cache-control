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
        try {
            // Try direct Redis connection first
            $redis = self::get_redis_connection();
            if ($redis) {
                error_log('Redis Purge: Using direct Redis connection');
                $redis->flushDb();
                return array(
                    'success' => true,
                    'message' => __('Redis cache purged successfully', 'holler-cache-control')
                );
            }

            // Fallback to object cache
            global $wp_object_cache;
            if ($wp_object_cache && method_exists($wp_object_cache, 'redis_status') && $wp_object_cache->redis_status()) {
                if (method_exists($wp_object_cache, 'flush')) {
                    error_log('Redis Purge: Using object cache flush');
                    $wp_object_cache->flush();
                    return array(
                        'success' => true,
                        'message' => __('Redis cache purged via object cache', 'holler-cache-control')
                    );
                }
            }

            error_log('Redis Purge: No flush method available');
            return array(
                'success' => false,
                'message' => __('No Redis flush method available', 'holler-cache-control')
            );

        } catch (\Exception $e) {
            error_log('Redis Purge Error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => sprintf(__('Redis error: %s', 'holler-cache-control'), $e->getMessage())
            );
        }
    }

    /**
     * Get Redis status
     *
     * @return array
     */
    public static function get_status() {
        try {
            // Try direct Redis connection first
            $redis = self::get_redis_connection();
            if ($redis) {
                $info = $redis->info();
                if ($info) {
                    $details = array();
                    $details[] = sprintf(__('Version: %s', 'holler-cache-control'), $info['redis_version']);
                    $details[] = sprintf(__('Memory Used: %s MB', 'holler-cache-control'), round($info['used_memory'] / 1024 / 1024, 2));
                    $details[] = sprintf(__('Uptime: %s days', 'holler-cache-control'), round($info['uptime_in_days'], 2));
                    
                    error_log('Redis Info: ' . print_r($info, true));
                    return array(
                        'active' => true,
                        'details' => implode(', ', $details)
                    );
                }
            }

            // Fallback to object cache status
            global $wp_object_cache;
            if ($wp_object_cache && method_exists($wp_object_cache, 'redis_status')) {
                $redis_status = $wp_object_cache->redis_status();
                error_log('Redis Status Check: ' . ($redis_status ? 'Connected' : 'Not Connected'));

                if ($redis_status) {
                    return array(
                        'active' => true,
                        'details' => __('Redis is connected', 'holler-cache-control')
                    );
                }
            }

            return array(
                'active' => false,
                'details' => __('Redis is not connected', 'holler-cache-control')
            );

        } catch (\Exception $e) {
            error_log('Redis Status Error: ' . $e->getMessage());
            return array(
                'active' => false,
                'details' => sprintf(__('Redis error: %s', 'holler-cache-control'), $e->getMessage())
            );
        }
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

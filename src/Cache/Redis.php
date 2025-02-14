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

        $errors = array();

        // Try direct Redis connection first
        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $redis->flushDb();
            $result['success'] = true;
        } catch (\Exception $e) {
            error_log('Direct Redis flush error: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }

        // Also try through WordPress object cache
        try {
            global $wp_object_cache;
            if ($wp_object_cache) {
                if (method_exists($wp_object_cache, 'redis_instance')) {
                    $redis = $wp_object_cache->redis_instance();
                    if ($redis) {
                        $redis->flushdb();
                    }
                }
                
                if (method_exists($wp_object_cache, 'flush')) {
                    $wp_object_cache->flush();
                }
                
                $result['success'] = true;
            }
        } catch (\Exception $e) {
            error_log('WordPress object cache flush error: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }

        if (!empty($errors)) {
            $result['message'] = implode(', ', $errors);
        } else {
            $result['message'] = __('Redis cache purged successfully', 'holler-cache-control');
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

        // First check if Redis is running on the system
        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $ping = $redis->ping();
            
            if ($ping === true || $ping === '+PONG') {
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
        } catch (\Exception $e) {
            error_log('Redis connection error: ' . $e->getMessage());
            $result['details'] = __('Redis server not accessible', 'holler-cache-control');
            return $result;
        }

        // If we can't connect directly, try through WordPress object cache
        global $wp_object_cache;
        
        if ($wp_object_cache && method_exists($wp_object_cache, 'redis_status')) {
            try {
                if ($wp_object_cache->redis_status()) {
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
                    return $result;
                }
            } catch (\Exception $e) {
                error_log('Redis object cache error: ' . $e->getMessage());
            }
        }

        // If we get here, Redis is not properly configured
        $result['details'] = __('Redis not properly configured', 'holler-cache-control');
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

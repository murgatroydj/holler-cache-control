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
            'message' => '',
            'details' => '',
            'type' => 'redis'
        );

        try {
            // First check if Redis object cache is enabled in WordPress
            global $wp_object_cache;
            
            if (!is_object($wp_object_cache) || 
                !method_exists($wp_object_cache, 'redis_instance') ||
                !method_exists($wp_object_cache, 'redis_status')) {
                $result['message'] = __('Redis Object Cache not enabled', 'holler-cache-control');
                return $result;
            }

            // Get Redis status from wp-redis plugin
            $redis_status = $wp_object_cache->redis_status();
            if (!$redis_status) {
                $result['message'] = __('Redis Object Cache not connected', 'holler-cache-control');
                return $result;
            }

            // Try to get Redis instance
            $redis = $wp_object_cache->redis_instance();
            if (!$redis) {
                $result['message'] = __('Redis Object Cache not connected', 'holler-cache-control');
                return $result;
            }

            // Check if Redis is responding
            if ($redis->ping() !== true && $redis->ping() !== '+PONG') {
                $result['message'] = __('Redis server not responding', 'holler-cache-control');
                return $result;
            }

            // Get the key prefix/salt
            $prefix = defined('WP_REDIS_KEY_SALT') ? WP_REDIS_KEY_SALT : '';
            if (empty($prefix) && defined('WP_REDIS_PREFIX')) {
                $prefix = WP_REDIS_PREFIX;
            }

            if (empty($prefix)) {
                $result['message'] = __('Redis Object Cache plugin not configured properly', 'holler-cache-control');
                return $result;
            }

            // Count WordPress Redis keys
            $cursor = 0;
            $keyCount = 0;
            
            do {
                // Use SCAN with string pattern for compatibility
                $scanResult = $redis->scan($cursor, $prefix . '*', 100);
                if (!$scanResult) {
                    break;
                }
                
                $cursor = $scanResult[0];
                $keys = $scanResult[1];
                
                if (is_array($keys)) {
                    $keyCount += count($keys);
                }
            } while ($cursor != 0);

            // Set success status
            $result['active'] = true;
            $result['message'] = __('Running', 'holler-cache-control');
            $result['details'] = sprintf(
                __('Redis Object Cache active with %d keys', 'holler-cache-control'),
                $keyCount
            );

        } catch (\Exception $e) {
            $result['message'] = sprintf(
                __('Redis Error: %s', 'holler-cache-control'),
                $e->getMessage()
            );
        }

        return $result;
    }

    /**
     * Purge Redis cache for GridPane environment
     *
     * @return array
     */
    public static function purge() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        try {
            // First try using WP Redis plugin's flush method if available
            global $wp_object_cache;
            
            if (
                is_object($wp_object_cache) && 
                method_exists($wp_object_cache, 'redis_instance') &&
                method_exists($wp_object_cache, 'flush')
            ) {
                $wp_object_cache->flush();
                $result['success'] = true;
                $result['message'] = __('Successfully cleared Redis object cache', 'holler-cache-control');
                return $result;
            }

            // Fallback to manual Redis connection
            $redis = self::get_redis_connection();
            if (!$redis) {
                $result['message'] = __('Could not connect to Redis server', 'holler-cache-control');
                return $result;
            }

            // Check if Redis is responding
            if ($redis->ping() !== true && $redis->ping() !== '+PONG') {
                $result['message'] = __('Redis server not responding', 'holler-cache-control');
                return $result;
            }

            // Get the key prefix/salt
            $prefix = defined('WP_REDIS_KEY_SALT') ? WP_REDIS_KEY_SALT : '';
            if (empty($prefix) && defined('WP_REDIS_PREFIX')) {
                $prefix = WP_REDIS_PREFIX;
            }

            if (empty($prefix)) {
                $result['message'] = __('Redis Object Cache plugin not configured properly', 'holler-cache-control');
                return $result;
            }

            // Use SCAN instead of KEYS for better performance
            $cursor = null;
            $deleted = 0;
            
            do {
                // Scan for keys matching our prefix
                $scanResult = $redis->scan($cursor, array(
                    'match' => $prefix . '*',
                    'count' => 100
                ));
                
                // Update cursor for next iteration
                $cursor = $scanResult[0];
                $keys = $scanResult[1];
                
                // Delete found keys
                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        if ($redis->del($key)) {
                            $deleted++;
                        }
                    }
                }
            } while ($cursor != 0);

            if ($deleted > 0) {
                $result['success'] = true;
                $result['message'] = sprintf(
                    __('Successfully cleared %d Redis keys', 'holler-cache-control'),
                    $deleted
                );
            } else {
                $result['message'] = __('No Redis keys found to clear', 'holler-cache-control');
            }

        } catch (\Exception $e) {
            $result['message'] = sprintf(
                __('Redis Error: %s', 'holler-cache-control'),
                $e->getMessage()
            );
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

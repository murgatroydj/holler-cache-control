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
            'details' => '',
            'type' => 'none'
        );
        
        // Make a test request to check headers
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => home_url(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5
        ));
        
        $response = curl_exec($ch);
        if ($response === false) {
            $result['details'] = __('Could not check cache status', 'holler-cache-control');
            curl_close($ch);
            return $result;
        }
        
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        curl_close($ch);
        
        // Parse headers
        $headers_array = array();
        foreach (explode("\n", $headers) as $line) {
            $parts = explode(':', $line, 2);
            if (isset($parts[1])) {
                $headers_array[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        
        // Check for standard Nginx cache headers
        $nginx_headers = array(
            'x-nginx-cache',
            'x-fastcgi-cache',
            'x-proxy-cache'
        );
        
        $has_nginx_headers = false;
        foreach ($nginx_headers as $header) {
            if (isset($headers_array[$header])) {
                $has_nginx_headers = true;
                $result['active'] = true;
                $result['type'] = 'nginx';
                $result['details'] = sprintf(
                    __('Nginx Page Caching (Status: %s)', 'holler-cache-control'),
                    $headers_array[$header]
                );
                return $result;
            }
        }
        
        // Check for Redis cache headers
        $redis_headers = array(
            'x-grid-srcache-ttl',
            'x-grid-srcache-fetch',
            'x-grid-srcache-store',
            'x-grid-srcache-skip'
        );
        
        $has_redis_headers = false;
        foreach ($redis_headers as $header) {
            if (isset($headers_array[$header])) {
                $has_redis_headers = true;
                break;
            }
        }
        
        if ($has_redis_headers) {
            $result['active'] = true;
            $result['type'] = 'redis';
            
            // Get cache status if available
            if (isset($headers_array['x-grid-srcache-skip']) && !empty($headers_array['x-grid-srcache-skip'])) {
                $result['details'] = sprintf(
                    __('Redis Page Caching (Skipped: %s)', 'holler-cache-control'),
                    $headers_array['x-grid-srcache-skip']
                );
            } else {
                $cache_status = isset($headers_array['x-grid-srcache-fetch']) ? $headers_array['x-grid-srcache-fetch'] : 'MISS';
                $result['details'] = sprintf(
                    __('Redis Page Caching (Status: %s)', 'holler-cache-control'),
                    $cache_status
                );
            }
            
            return $result;
        }

        // If no cache headers found, check if cache directory exists and is writable
        $cache_dir = '/var/cache/nginx';
        if (is_dir($cache_dir) && is_writable($cache_dir)) {
            $result['active'] = true;
            $result['type'] = 'nginx';
            $result['details'] = __('Nginx Page Caching Enabled', 'holler-cache-control');
            return $result;
        }
        
        $result['details'] = __('Page Caching Disabled', 'holler-cache-control');
        return $result;
    }

    /**
     * Purge Nginx cache for GridPane environment
     *
     * @return array
     */
    public static function purge() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        try {
            $status = self::get_status();
            if (!$status['active']) {
                throw new \Exception(__('Page Caching is not active', 'holler-cache-control'));
            }

            // Get all public post types
            $post_types = get_post_types(array('public' => true));
            
            // Get URLs to purge
            $urls_to_purge = array();
            
            // Add home URL and site URL
            $urls_to_purge[] = home_url('/');
            $urls_to_purge[] = site_url('/');
            
            // Add post type archive URLs
            foreach ($post_types as $post_type) {
                $archive_url = get_post_type_archive_link($post_type);
                if ($archive_url) {
                    $urls_to_purge[] = $archive_url;
                }
            }
            
            // Add recent posts URLs
            $recent_posts = get_posts(array(
                'posts_per_page' => 10,
                'post_type' => $post_types,
                'post_status' => 'publish'
            ));
            
            foreach ($recent_posts as $post) {
                $urls_to_purge[] = get_permalink($post->ID);
            }
            
            // Add taxonomy archive URLs
            $taxonomies = get_taxonomies(array('public' => true));
            foreach ($taxonomies as $taxonomy) {
                $terms = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => true,
                    'number' => 10
                ));
                
                foreach ($terms as $term) {
                    $urls_to_purge[] = get_term_link($term);
                }
            }
            
            // Add author archive URLs
            $authors = get_users(array(
                'has_published_posts' => true,
                'number' => 10
            ));
            
            foreach ($authors as $author) {
                $urls_to_purge[] = get_author_posts_url($author->ID);
            }
            
            // Make URLs unique
            $urls_to_purge = array_unique($urls_to_purge);
            
            // Initialize counters
            $success_count = 0;
            $error_count = 0;
            
            // Send purge requests
            foreach ($urls_to_purge as $url) {
                $purge_url = add_query_arg('purge_cache', '1', $url);
                
                $response = wp_remote_get($purge_url, array(
                    'timeout' => 0.01,
                    'blocking' => false,
                    'sslverify' => false,
                    'headers' => array(
                        'X-Purge-Method' => 'get',
                        'X-Purge-Host' => parse_url($url, PHP_URL_HOST)
                    )
                ));
                
                if (!is_wp_error($response)) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }

            // Also try to clear the object cache
            wp_cache_flush();
            
            // Try to clear any transients
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
            
            // Set success message
            if ($success_count > 0) {
                $result['success'] = true;
                $result['message'] = sprintf(
                    __('Successfully purged %d URLs', 'holler-cache-control'),
                    $success_count
                );
                
                if ($error_count > 0) {
                    $result['message'] .= sprintf(
                        __('. Failed to purge %d URLs', 'holler-cache-control'),
                        $error_count
                    );
                }
            } else {
                throw new \Exception(__('Failed to purge any URLs', 'holler-cache-control'));
            }
            
        } catch (\Exception $e) {
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

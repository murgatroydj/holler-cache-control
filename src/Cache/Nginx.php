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
     * Purge nginx cache
     *
     * @return array
     */
    public static function purge() {
        try {
            if (!current_user_can('manage_options')) {
                return array(
                    'success' => false,
                    'message' => __('You do not have permission to purge cache', 'holler-cache-control')
                );
            }

            // Get domain
            $domain = parse_url(home_url(), PHP_URL_HOST);
            error_log('Attempting to purge Nginx cache for domain: ' . $domain);

            // Check if cache directory exists
            $cache_dirs = array(
                // GridPane specific paths
                "/var/www/cache/{$domain}",
                "/var/cache/nginx/{$domain}",
                
                // Common server-specific paths with domain
                "/var/run/nginx-cache/{$domain}",
                "/var/run/nginx-proxy-cache/{$domain}",
                "/var/lib/nginx/cache/{$domain}",
                "/var/lib/nginx/proxy_cache/{$domain}",
                "/var/lib/nginx/fastcgi_cache/{$domain}",
                "/tmp/nginx/cache/{$domain}",
                "/usr/share/nginx/cache/{$domain}",
                
                // Common server-specific paths without domain
                "/var/run/nginx-cache",
                "/var/run/nginx-proxy-cache",
                "/var/lib/nginx/cache",
                "/var/lib/nginx/proxy_cache",
                "/var/lib/nginx/fastcgi_cache",
                "/tmp/nginx/cache",
                "/usr/share/nginx/cache",
                
                // EasyEngine specific paths
                "/var/www/cache/nginx",
                "/var/www/cache/nginx/proxy",
                "/var/www/cache/nginx/fastcgi",
                
                // Plesk specific paths
                "/var/cache/plesk_nginx",
                "/var/cache/plesk_nginx/proxy",
                "/var/cache/plesk_nginx/fastcgi",
                
                // cPanel specific paths
                "/var/cache/ea-nginx",
                "/var/cache/ea-nginx/proxy",
                "/var/cache/ea-nginx/fastcgi"
            );

            $cache_dir = null;
            foreach ($cache_dirs as $dir) {
                if (file_exists($dir)) {
                    $cache_dir = $dir;
                    error_log('Found cache directory: ' . $cache_dir);
                    break;
                }
                error_log('Cache directory not found: ' . $dir);
            }

            if (!$cache_dir) {
                error_log('No cache directory found in any of the expected locations');
                return array(
                    'success' => false,
                    'message' => __('Cache directory not found', 'holler-cache-control')
                );
            }

            // Try to purge using wp-cli first
            $output = array();
            $return_var = -1;
            
            \exec('which wp 2>&1', $wp_output, $wp_return);
            if ($wp_return === 0) {
                error_log('Found wp-cli, attempting to purge cache using wp-cli');
                \exec('wp gp fix cached ' . \escapeshellarg($domain) . ' 2>&1', $output, $return_var);
                error_log('WP-CLI command output: ' . print_r($output, true));
                error_log('WP-CLI command return code: ' . $return_var);
            }

            // If wp-cli fails or not available, try direct gp command
            if ($return_var !== 0) {
                error_log('WP-CLI failed or not available, trying direct gp command');
                \exec('which gp 2>&1', $gp_output, $gp_return);
                
                if ($gp_return === 0) {
                    error_log('Found gp command, attempting to purge cache');
                    \exec('gp fix cached ' . \escapeshellarg($domain) . ' 2>&1', $output, $return_var);
                    error_log('GP command output: ' . print_r($output, true));
                    error_log('GP command return code: ' . $return_var);
                } else {
                    error_log('GP command not found');
                    return array(
                        'success' => false,
                        'message' => __('Cache purge command not available', 'holler-cache-control')
                    );
                }
            }

            // If both commands fail, try manual cache clearing
            if ($return_var !== 0) {
                error_log('Both wp-cli and gp commands failed, attempting manual cache clear');
                
                // Try to clear cache directory
                if (is_dir($cache_dir)) {
                    $files = glob($cache_dir . '/*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            \unlink($file);
                        }
                    }
                    error_log('Manually cleared cache directory: ' . $cache_dir);
                    
                    // Send PURGE request to Nginx
                    $purge_url = home_url('/');
                    $response = wp_remote_request($purge_url, array(
                        'method' => 'PURGE',
                        'timeout' => 5,
                        'redirection' => 0,
                        'headers' => array(
                            'Host' => $domain
                        )
                    ));
                    
                    if (is_wp_error($response)) {
                        error_log('PURGE request failed: ' . $response->get_error_message());
                    } else {
                        error_log('PURGE request response: ' . wp_remote_retrieve_response_code($response));
                    }
                }
            }

            // Check if any method succeeded
            if ($return_var === 0 || is_dir($cache_dir)) {
                return array(
                    'success' => true,
                    'message' => __('Cache cleared successfully', 'holler-cache-control')
                );
            }

            throw new \Exception(
                sprintf(
                    __('Failed to clear cache. Error code: %d, Output: %s', 'holler-cache-control'),
                    $return_var,
                    implode("\n", $output)
                )
            );

        } catch (\Exception $e) {
            error_log('Cache purge error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
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

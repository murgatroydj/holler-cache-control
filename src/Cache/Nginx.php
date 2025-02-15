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

            // Use GridPane's cache clearing command
            $site_url = parse_url(home_url(), PHP_URL_HOST);
            $command = 'gp fix cached ' . escapeshellarg($site_url) . ' 2>&1';
            
            // Execute with sudo since GridPane commands typically need elevated privileges
            $output = shell_exec('sudo ' . $command);
            
            if ($output === null) {
                throw new \Exception(__('Failed to execute GridPane cache clear command', 'holler-cache-control'));
            }

            // Check for error messages in output
            if (stripos($output, 'error') !== false || stripos($output, 'failed') !== false) {
                throw new \Exception(sprintf(__('GridPane cache clear failed: %s', 'holler-cache-control'), $output));
            }

            $result['success'] = true;
            $result['message'] = __('Cache cleared successfully via GridPane', 'holler-cache-control');
            
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

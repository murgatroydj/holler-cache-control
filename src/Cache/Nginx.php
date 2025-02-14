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
            'details' => ''
        );

        // Check if Nginx page cache is enabled
        $cache_path = '/var/cache/nginx';
        if (!is_dir($cache_path)) {
            $result['details'] = __('Nginx Page Cache is disabled in GridPane', 'holler-cache-control');
            return $result;
        }

        // Get cache size if it's enabled
        try {
            $size = self::get_directory_size($cache_path);
            $result['active'] = true;
            $result['details'] = sprintf(
                __('GridPane Nginx Page Cache | Cache Size: %s', 'holler-cache-control'),
                size_format($size)
            );
        } catch (\Exception $e) {
            error_log('GridPane Nginx cache error: ' . $e->getMessage());
            $result['details'] = __('Error reading GridPane Nginx cache', 'holler-cache-control');
        }

        return $result;
    }

    /**
     * Purge Nginx cache for GridPane environment
     *
     * @return array
     */
    public static function purge_cache() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        try {
            // Check if Nginx page cache is enabled
            $cache_path = '/var/cache/nginx';
            if (!is_dir($cache_path)) {
                throw new \Exception(__('Nginx Page Cache is disabled in GridPane', 'holler-cache-control'));
            }

            // Use GridPane's cache purge method
            exec('rm -rf ' . escapeshellarg($cache_path . '/*'), $output, $return_var);
            
            if ($return_var !== 0) {
                throw new \Exception(__('Failed to purge GridPane Nginx Page Cache', 'holler-cache-control'));
            }

            $result['success'] = true;
            $result['message'] = __('GridPane Nginx Page Cache purged successfully', 'holler-cache-control');

        } catch (\Exception $e) {
            error_log('GridPane Nginx purge error: ' . $e->getMessage());
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

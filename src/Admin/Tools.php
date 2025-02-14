<?php
namespace HollerCacheControl\Admin;

use HollerCacheControl\Cache\Nginx;
use HollerCacheControl\Cache\Redis;
use HollerCacheControl\Cache\Cloudflare;
use HollerCacheControl\Cache\CloudflareAPO;

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    HollerCacheControl
 * @subpackage HollerCacheControl/Admin
 */
class Tools {
    private $plugin_name;
    private $version;

    public function __construct($plugin_name = 'holler-cache-control', $version = '1.0.0') {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // Add admin bar menu with high priority to ensure it runs after other plugins
        add_action('wp_before_admin_bar_render', array($this, 'remove_original_buttons'), 999);
        add_action('admin_bar_menu', array($this, 'admin_bar_menu'), 100);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Register AJAX handlers
        add_action('wp_ajax_holler_cache_control_purge', array($this, 'handle_purge_cache'));
        add_action('wp_ajax_holler_cache_control_status', array($this, 'handle_cache_status'));
        add_action('wp_ajax_holler_purge_nginx_cache', array($this, 'purge_nginx_cache'));

        // Enqueue scripts for both admin and front-end when user is logged in
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Add cache purging hooks
        $this->add_cache_purging_hooks();

        // Add async cache purge hook
        add_action('holler_cache_control_async_purge', array($this, 'purge_all_caches'));
    }

    /**
     * Add hooks for automatic cache purging on various WordPress actions
     */
    private function add_cache_purging_hooks() {
        // Content updates
        add_action('save_post', array($this, 'purge_all_caches_on_update'), 10, 0);
        add_action('edit_post', array($this, 'purge_all_caches_on_update'), 10, 0);
        add_action('deleted_post', array($this, 'purge_all_caches_on_update'), 10, 0);
        add_action('wp_insert_post', array($this, 'purge_all_caches_on_update'), 10, 0);
        
        // Theme changes
        add_action('switch_theme', array($this, 'purge_all_caches_on_update'), 10, 0);
        
        // Plugin changes
        add_action('activated_plugin', array($this, 'purge_all_caches_on_update'), 10, 0);
        add_action('deactivated_plugin', array($this, 'purge_all_caches_on_update'), 10, 0);
        add_action('upgrader_process_complete', array($this, 'purge_all_caches_on_update'), 10, 0);

        // Elementor specific hooks
        add_action('elementor/core/files/clear_cache', array($this, 'purge_elementor_cache'), 10, 0);
        add_action('elementor/maintenance_mode/enable', array($this, 'purge_all_caches_on_update'), 10, 0);
        add_action('elementor/maintenance_mode/disable', array($this, 'purge_all_caches_on_update'), 10, 0);
        add_action('elementor/settings/save', array($this, 'purge_all_caches_on_update'), 10, 0);
        add_action('elementor/editor/after_save', array($this, 'purge_elementor_cache'), 10, 0);
        
        // Astra theme specific hooks
        add_action('astra_addon_update_after', array($this, 'purge_astra_cache'), 10, 0);
        add_action('astra_background_obj_created', array($this, 'purge_astra_cache'), 10, 0);
        add_action('astra_cache_clear', array($this, 'purge_astra_cache'), 10, 0);
        add_action('customize_save_after', array($this, 'purge_all_caches_on_update'), 10, 0);
        
        // Add filter to optimize Elementor's external file loading
        add_filter('elementor/frontend/print_google_fonts', array($this, 'optimize_elementor_google_fonts'), 10, 1);
        add_filter('elementor/frontend/print_google_fonts_preconnect', '__return_false');

        // Add Astra optimization filters
        add_filter('astra_addon_asset_js_enable', array($this, 'optimize_astra_assets'), 10, 1);
        add_filter('astra_addon_asset_css_enable', array($this, 'optimize_astra_assets'), 10, 1);
        add_filter('astra_dynamic_css_preload', '__return_true');
    }

    /**
     * Purge all caches on plugin update/activation/deactivation
     */
    public function purge_all_caches_on_update() {
        // Skip cache purging during plugin deactivation if it's not our plugin
        if (isset($_GET['action']) && $_GET['action'] === 'deactivate') {
            if (isset($_GET['plugin']) && strpos($_GET['plugin'], 'holler-cache-control') === false) {
                return;
            }
            
            // For deactivation, schedule an async task to clear caches
            wp_schedule_single_event(time(), 'holler_cache_control_async_purge');
            return;
        }

        $this->purge_all_caches();
    }

    /**
     * Actually purge all caches
     */
    public function purge_all_caches() {
        $result = array(
            'success' => false,
            'message' => ''
        );

        try {
            // Purge Nginx cache
            $nginx_result = \HollerCacheControl\Cache\Nginx::purge();
            if (!$nginx_result['success']) {
                $result['message'] .= $nginx_result['message'] . "\n";
            }

            // Purge Redis cache
            $redis_result = \HollerCacheControl\Cache\Redis::purge();
            if (!$redis_result['success']) {
                $result['message'] .= $redis_result['message'] . "\n";
            }

            // Purge Cloudflare cache
            $cloudflare_result = \HollerCacheControl\Cache\Cloudflare::purge();
            if (!$cloudflare_result['success']) {
                $result['message'] .= $cloudflare_result['message'] . "\n";
            }

            // Purge Cloudflare APO cache
            $cloudflare_apo_result = \HollerCacheControl\Cache\CloudflareAPO::purge();
            if (!$cloudflare_apo_result['success']) {
                $result['message'] .= $cloudflare_apo_result['message'] . "\n";
            }

            $result['success'] = true;
            if (empty($result['message'])) {
                $result['message'] = __('All caches cleared successfully', 'holler-cache-control');
            }

        } catch (\Exception $e) {
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Clear Elementor cache
     */
    public function purge_elementor_cache() {
        if (!class_exists('\Elementor\Plugin')) {
            return;
        }

        // Clear Elementor's CSS cache
        $uploads_dir = wp_upload_dir();
        $elementor_css_dir = $uploads_dir['basedir'] . '/elementor/css';
        
        if (is_dir($elementor_css_dir)) {
            // Use shell commands for efficient directory cleanup
            if (function_exists('shell_exec')) {
                // Remove min directory completely
                $min_dir = $elementor_css_dir . '/min';
                if (is_dir($min_dir)) {
                    @shell_exec('rm -rf ' . escapeshellarg($min_dir));
                }
                
                // Remove all files in css directory but keep the directory itself
                @shell_exec('find ' . escapeshellarg($elementor_css_dir) . ' -type f -delete');
            } else {
                // Fallback to PHP methods if shell_exec is not available
                $this->delete_directory_contents($elementor_css_dir);
            }
        }

        // Clear Elementor's data cache
        if (method_exists('\Elementor\Plugin', 'instance')) {
            // Prevent Elementor from handling files directly
            remove_action('elementor/core/files/clear_cache', array(\Elementor\Plugin::instance()->files_manager, 'clear_cache'));
            // Clear other Elementor caches
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
    }

    /**
     * Delete contents of a directory
     * @param string $dir Directory path
     */
    private function delete_directory_contents($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Purge Astra-specific caches
     */
    public function purge_astra_cache() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Clear Astra's dynamic CSS cache
        delete_option('astra-addon-css-dynamic-css');
        delete_site_option('astra-addon-css-dynamic-css');
        
        // Clear customizer CSS cache
        delete_option('astra-customizer-css');
        delete_site_option('astra-customizer-css');

        // Clear cached Google Fonts
        delete_option('astra-google-fonts-cache');
        delete_site_option('astra-google-fonts-cache');

        // Clear theme CSS cache files
        $upload_dir = wp_upload_dir();
        $astra_css_dir = $upload_dir['basedir'] . '/astra-addon';
        if (is_dir($astra_css_dir)) {
            array_map('unlink', glob("$astra_css_dir/*.*"));
        }

        // Clear child theme cache if using holler-agnt
        if (get_stylesheet() === 'holler-agnt') {
            delete_option('holler_agnt_dynamic_css');
            delete_site_option('holler_agnt_dynamic_css');
            
            // Clear any custom asset cache
            $child_css_dir = $upload_dir['basedir'] . '/holler-agnt';
            if (is_dir($child_css_dir)) {
                array_map('unlink', glob("$child_css_dir/*.*"));
            }
        }

        // Refresh Astra's asset versions
        update_option('astra-addon-auto-version', time());
        if (function_exists('astra_addon_filesystem')) {
            astra_addon_filesystem()->reset_assets_cache();
        }
    }

    /**
     * Optimize Elementor's Google Fonts loading
     * 
     * @param array $google_fonts Array of Google Fonts to be loaded
     * @return array Modified array of Google Fonts
     */
    public function optimize_elementor_google_fonts($google_fonts) {
        // If we're in the admin, return original fonts
        if (is_admin()) {
            return $google_fonts;
        }

        // Get stored font preferences
        $stored_fonts = get_option('holler_elementor_font_optimization', array());
        
        if (empty($stored_fonts)) {
            // Store the fonts for future reference
            update_option('holler_elementor_font_optimization', $google_fonts);
            return $google_fonts;
        }

        // Return stored fonts to maintain consistency
        return $stored_fonts;
    }

    /**
     * Optimize Astra's asset loading
     * 
     * @param bool $enable Whether to enable the asset
     * @return bool Modified enable status
     */
    public function optimize_astra_assets($enable) {
        // If we're in the admin, return original setting
        if (is_admin()) {
            return $enable;
        }

        // Get stored optimization preferences
        $stored_prefs = get_option('holler_astra_optimization', null);
        
        if (is_null($stored_prefs)) {
            // Store the current preference for future reference
            update_option('holler_astra_optimization', $enable);
            return $enable;
        }

        // Return stored preference to maintain consistency
        return $stored_prefs;
    }

    /**
     * Delete all transients from the WordPress database
     */
    private function delete_transient_cache() {
        global $wpdb;
        
        // Delete all transient related entries from options table
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'");
        
        // If this is a multisite, also clear network transients
        if (is_multisite()) {
            $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%_site_transient_%'");
        }
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register Nginx settings
        register_setting(
            'holler_cache_control_settings',
            'nginx_cache_method',
            array(
                'type' => 'string',
                'default' => 'redis',
                'sanitize_callback' => function($value) {
                    return in_array($value, array('fastcgi', 'redis')) ? $value : 'redis';
                }
            )
        );

        // Register admin bar settings
        register_setting(
            'holler_cache_control_settings',
            'hide_nginx_purge_button',
            array(
                'type' => 'boolean',
                'default' => false
            )
        );

        register_setting(
            'holler_cache_control_settings',
            'hide_redis_purge_button',
            array(
                'type' => 'boolean',
                'default' => false
            )
        );

        // Register Cloudflare settings
        if (!defined('CLOUDFLARE_EMAIL')) {
            register_setting(
                'holler_cache_control_settings',
                'cloudflare_email',
                array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_email'
                )
            );
        }

        if (!defined('CLOUDFLARE_API_KEY')) {
            register_setting(
                'holler_cache_control_settings',
                'cloudflare_api_key',
                array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            );
        }

        if (!defined('CLOUDFLARE_ZONE_ID')) {
            register_setting(
                'holler_cache_control_settings',
                'cloudflare_zone_id',
                array(
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            );
        }
    }

    /**
     * Register the stylesheets for the admin area.
     */
    public function enqueue_styles() {
        $css_file = plugin_dir_path(dirname(__DIR__)) . 'assets/css/admin.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'holler-cache-control-admin',
                plugin_dir_url(dirname(__DIR__)) . 'assets/css/admin.css',
                array(),
                filemtime($css_file)
            );
        }
    }

    /**
     * Register the JavaScript for the admin area.
     */
    public function enqueue_scripts($hook) {
        if (!$this->is_plugin_admin_page($hook) && !is_admin_bar_showing()) {
            return;
        }

        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/js/admin.js',
            array('jquery'),
            filemtime(plugin_dir_path(dirname(dirname(__FILE__))) . 'assets/js/admin.js'),
            true
        );

        wp_localize_script($this->plugin_name, 'hollerCacheControl', array(
            'i18n' => array(
                'purging' => __('Purging...', 'holler-cache-control'),
                'updating' => __('Updating...', 'holler-cache-control')
            ),
            'nonces' => array(
                'status' => wp_create_nonce('holler_cache_status'),
                'all' => wp_create_nonce('holler_purge_all'),
                'nginx' => wp_create_nonce('holler_purge_nginx'),
                'redis' => wp_create_nonce('holler_purge_redis'),
                'cloudflare' => wp_create_nonce('holler_purge_cloudflare'),
                'cloudflare_apo' => wp_create_nonce('holler_purge_cloudflare_apo')
            )
        ));
    }

    /**
     * Enqueue assets for both admin and front-end when user is logged in
     */
    public function enqueue_assets() {
        $this->enqueue_styles();
        $this->enqueue_scripts('admin.php');
    }

    public function add_plugin_admin_menu() {
        add_options_page(
            __('Cache Control', 'holler-cache-control'),
            __('Cache Control', 'holler-cache-control'),
            'manage_options',
            'holler-cache-control',
            array($this, 'display_plugin_admin_page')
        );
    }

    public static function get_cache_systems_status() {
        return array(
            'nginx' => Nginx::get_status(),
            'redis' => Redis::get_status(),
            'cloudflare' => Cloudflare::get_status(),
            'cloudflare-apo' => CloudflareAPO::get_status()
        );
    }

    public function display_plugin_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get cache statuses
        $nginx_status = \HollerCacheControl\Cache\Nginx::get_status();
        $redis_status = \HollerCacheControl\Cache\Redis::get_status();
        $cloudflare_status = \HollerCacheControl\Cache\Cloudflare::get_status();
        $cloudflare_apo_status = \HollerCacheControl\Cache\CloudflareAPO::get_status();

        // Include the admin display template
        include_once plugin_dir_path(dirname(__FILE__)) . 'Admin/views/admin-display.php';
    }

    /**
     * Handle AJAX request to purge cache
     */
    public function handle_purge_cache() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'holler-cache-control'));
            return;
        }

        if (!isset($_POST['type'])) {
            wp_send_json_error(__('No cache type specified.', 'holler-cache-control'));
            return;
        }

        $type = sanitize_text_field($_POST['type']);
        
        // Verify nonce
        if (!check_ajax_referer('holler_purge_' . $type, '_ajax_nonce', false)) {
            wp_send_json_error(__('Invalid security token.', 'holler-cache-control'));
            return;
        }

        $results = array();
        $success = true;
        $messages = array();

        // If type is 'all', purge all caches
        if ($type === 'all') {
            $caches = array('nginx', 'redis', 'cloudflare', 'cloudflare-apo');
            foreach ($caches as $cache_type) {
                $result = $this->purge_single_cache($cache_type);
                if (!empty($result['message'])) {
                    $messages[] = $result['message'];
                }
                if (!$result['success']) {
                    $success = false;
                }
            }
            
            if ($success) {
                wp_send_json_success(__('All caches cleared successfully', 'holler-cache-control'));
            } else {
                wp_send_json_error(implode("\n", $messages));
            }
            return;
        }

        // Handle single cache type
        $result = $this->purge_single_cache($type);
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    private function purge_single_cache($type) {
        $result = array(
            'success' => false,
            'message' => ''
        );

        switch ($type) {
            case 'nginx':
                $result = \HollerCacheControl\Cache\Nginx::purge();
                break;
            case 'redis':
                $result = \HollerCacheControl\Cache\Redis::purge();
                break;
            case 'cloudflare':
                $result = \HollerCacheControl\Cache\Cloudflare::purge();
                break;
            case 'cloudflare-apo':
                $result = \HollerCacheControl\Cache\CloudflareAPO::purge();
                break;
            default:
                $result['message'] = sprintf(
                    __('Invalid cache type: %s', 'holler-cache-control'),
                    $type
                );
                break;
        }

        return $result;
    }

    /**
     * Handle AJAX request for cache status
     */
    public function handle_cache_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'holler-cache-control'));
            return;
        }

        if (!check_ajax_referer('holler_cache_status', '_ajax_nonce', false)) {
            wp_send_json_error(__('Invalid security token.', 'holler-cache-control'));
            return;
        }

        $statuses = array(
            'nginx' => \HollerCacheControl\Cache\Nginx::get_status(),
            'redis' => \HollerCacheControl\Cache\Redis::get_status(),
            'cloudflare' => \HollerCacheControl\Cache\Cloudflare::get_status(),
            'cloudflare_apo' => \HollerCacheControl\Cache\CloudflareAPO::get_status()
        );

        wp_send_json_success($statuses);
    }

    public function purge_nginx_cache() {
        check_ajax_referer('holler_purge_nginx_cache');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'holler-cache-control'));
            return;
        }
        
        $result = \HollerCacheControl\Cache\Nginx::purge();
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function remove_original_buttons() {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        global $wp_admin_bar;
        
        // Remove Nginx Helper button if setting enabled
        if (get_option('hide_nginx_purge_button', '0') === '1') {
            $wp_admin_bar->remove_node('nginx-helper-purge-all');
        }
        
        // Remove Redis Object Cache button if setting enabled
        if (get_option('hide_redis_purge_button', '0') === '1') {
            $wp_admin_bar->remove_node('redis-cache');
        }
    }

    public function admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get cache statuses
        $nginx_status = \HollerCacheControl\Cache\Nginx::get_status();
        $redis_status = \HollerCacheControl\Cache\Redis::get_status();
        $cloudflare_status = \HollerCacheControl\Cache\Cloudflare::get_status();
        $cloudflare_apo_status = \HollerCacheControl\Cache\CloudflareAPO::get_status();

        // Add main menu item
        $wp_admin_bar->add_menu(array(
            'id' => 'holler-cache-control',
            'title' => __('Cache Control', 'holler-cache-control'),
            'href' => admin_url('admin.php?page=holler-cache-control')
        ));

        // Add cache status submenu
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'holler-cache-status',
            'title' => __('Cache Status', 'holler-cache-control')
        ));

        // Add individual cache status items
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-status',
            'id' => 'holler-nginx-status',
            'title' => sprintf(
                __('Nginx Page Cache: %s', 'holler-cache-control'),
                $nginx_status['active'] ? '✅' : '❌'
            )
        ));

        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-status',
            'id' => 'holler-redis-status',
            'title' => sprintf(
                __('Redis Object Cache: %s', 'holler-cache-control'),
                $redis_status['active'] ? '✅' : '❌'
            )
        ));

        if ($cloudflare_status['active']) {
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-cache-status',
                'id' => 'holler-cloudflare-status',
                'title' => sprintf(
                    __('Cloudflare Cache: %s', 'holler-cache-control'),
                    $cloudflare_status['active'] ? '✅' : '❌'
                )
            ));
        }

        if ($cloudflare_apo_status['active']) {
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-cache-status',
                'id' => 'holler-cloudflare-apo-status',
                'title' => sprintf(
                    __('Cloudflare APO: %s', 'holler-cache-control'),
                    $cloudflare_apo_status['active'] ? '✅' : '❌'
                )
            ));
        }

        // Add separator
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'holler-cache-separator',
            'title' => ''
        ));

        // Add purge cache submenu
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'holler-purge-cache',
            'title' => __('Purge Cache', 'holler-cache-control')
        ));

        // Add purge all caches option first
        $nonce = wp_create_nonce('holler_purge_all');
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-purge-cache',
            'id' => 'holler-purge-all',
            'title' => '<a href="#" class="holler-purge-cache" data-cache-type="all">' . 
                      __('Purge All Caches', 'holler-cache-control') . '</a>' .
                      '<input type="hidden" id="holler-all-nonce" value="' . esc_attr($nonce) . '">'
        ));

        // Add separator after purge all
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-purge-cache',
            'id' => 'holler-purge-separator',
            'title' => ''
        ));

        // Only show individual purge buttons for active caching services
        if ($nginx_status['active']) {
            $nonce = wp_create_nonce('holler_purge_nginx');
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-purge-cache',
                'id' => 'holler-purge-nginx',
                'title' => '<a href="#" class="holler-purge-cache" data-cache-type="nginx">' . 
                          __('Purge Nginx Page Cache', 'holler-cache-control') . '</a>' .
                          '<input type="hidden" id="holler-nginx-nonce" value="' . esc_attr($nonce) . '">'
            ));
        }

        if ($redis_status['active']) {
            $nonce = wp_create_nonce('holler_purge_redis');
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-purge-cache',
                'id' => 'holler-purge-redis',
                'title' => '<a href="#" class="holler-purge-cache" data-cache-type="redis">' . 
                          __('Purge Redis Object Cache', 'holler-cache-control') . '</a>' .
                          '<input type="hidden" id="holler-redis-nonce" value="' . esc_attr($nonce) . '">'
            ));
        }

        if ($cloudflare_status['active']) {
            $nonce = wp_create_nonce('holler_purge_cloudflare');
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-purge-cache',
                'id' => 'holler-purge-cloudflare',
                'title' => '<a href="#" class="holler-purge-cache" data-cache-type="cloudflare">' . 
                          __('Purge Cloudflare Cache', 'holler-cache-control') . '</a>' .
                          '<input type="hidden" id="holler-cloudflare-nonce" value="' . esc_attr($nonce) . '">'
            ));
        }

        if ($cloudflare_apo_status['active']) {
            $nonce = wp_create_nonce('holler_purge_cloudflare_apo');
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-purge-cache',
                'id' => 'holler-purge-cloudflare-apo',
                'title' => '<a href="#" class="holler-purge-cache" data-cache-type="cloudflare-apo">' . 
                          __('Purge Cloudflare APO', 'holler-cache-control') . '</a>' .
                          '<input type="hidden" id="holler-cloudflare-apo-nonce" value="' . esc_attr($nonce) . '">'
            ));
        }

        // Add another separator
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'holler-cache-separator-2',
            'title' => ''
        ));

        // Add settings link
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'holler-cache-settings',
            'title' => __('Settings', 'holler-cache-control'),
            'href' => admin_url('admin.php?page=holler-cache-control')
        ));
    }

    private function is_plugin_admin_page($hook) {
        return strpos($hook, 'holler-cache-control') !== false;
    }
}

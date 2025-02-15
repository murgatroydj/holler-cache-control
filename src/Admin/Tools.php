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

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // Initialize admin hooks
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Initialize front-end hooks if admin bar is showing
        add_action('init', array($this, 'init_front_end'));

        // Add admin bar menu
        add_action('admin_bar_menu', array($this, 'admin_bar_menu'), 100);

        // Register AJAX handlers
        add_action('wp_ajax_holler_cache_control_status', array($this, 'handle_cache_status'));
        add_action('wp_ajax_holler_purge_all', array($this, 'handle_purge_cache'));
        add_action('wp_ajax_holler_purge_nginx', array($this, 'handle_purge_cache'));
        add_action('wp_ajax_holler_purge_redis', array($this, 'handle_purge_cache'));
        add_action('wp_ajax_holler_purge_cloudflare', array($this, 'handle_purge_cache'));
        add_action('wp_ajax_holler_purge_cloudflare_apo', array($this, 'handle_purge_cache'));

        // Add async cache purge hook
        add_action('holler_cache_control_async_purge', array($this, 'purge_all_caches'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Add cache purging hooks
        $this->add_cache_purging_hooks();
    }

    /**
     * Initialize front-end functionality if admin bar is showing
     */
    public function init_front_end() {
        if (!is_admin() && is_admin_bar_showing()) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('wp_footer', array($this, 'add_notice_container'));
        }
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
                'type' => 'string',
                'default' => '0',
                'sanitize_callback' => function($value) {
                    return $value === '1' ? '1' : '0';
                }
            )
        );

        register_setting(
            'holler_cache_control_settings',
            'hide_redis_purge_button',
            array(
                'type' => 'string',
                'default' => '0',
                'sanitize_callback' => function($value) {
                    return $value === '1' ? '1' : '0';
                }
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
        // Only load on our admin page or if admin bar is showing
        if (!$this->is_plugin_admin_page($hook) && !is_admin_bar_showing()) {
            return;
        }

        // Add ajaxurl for front-end
        if (!is_admin()) {
            wp_enqueue_script('jquery');
            wp_add_inline_script('jquery', 'var ajaxurl = "' . admin_url('admin-ajax.php') . '";', 'before');
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
                'status' => wp_create_nonce('holler_cache_control_status'),
                'all' => wp_create_nonce('holler_purge_all'),
                'nginx' => wp_create_nonce('holler_purge_nginx'),
                'redis' => wp_create_nonce('holler_purge_redis'),
                'cloudflare' => wp_create_nonce('holler_purge_cloudflare'),
                'cloudflare_apo' => wp_create_nonce('holler_purge_cloudflare_apo')
            ),
            'isAdmin' => is_admin()
        ));

        // Add inline styles for front-end notices
        if (!is_admin()) {
            wp_enqueue_style('dashicons');
            wp_add_inline_style('dashicons', '
                #holler-cache-notice-container {
                    position: fixed;
                    top: 32px;
                    left: 0;
                    right: 0;
                    z-index: 99999;
                }
                #holler-cache-notice-container .notice {
                    margin: 5px auto;
                    max-width: 800px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                    position: relative;
                    padding: 1px 40px 1px 12px;
                    display: flex;
                    align-items: center;
                    background: #fff;
                    border-left: 4px solid #72aee6;
                }
                #holler-cache-notice-container .notice p {
                    margin: 0.5em 0;
                    padding: 2px;
                    display: inline-block;
                }
                #holler-cache-notice-container .notice-success {
                    border-left-color: #00a32a;
                }
                #holler-cache-notice-container .notice-error {
                    border-left-color: #d63638;
                }
                #holler-cache-notice-container .notice-dismiss {
                    position: absolute;
                    top: 0;
                    right: 1px;
                    border: none;
                    margin: 0;
                    padding: 9px;
                    background: none;
                    color: #787c82;
                    cursor: pointer;
                }
                #holler-cache-notice-container .notice-dismiss:before {
                    background: none;
                    color: #787c82;
                    content: "\\f153";
                    display: block;
                    font: normal 16px/20px dashicons;
                    speak: never;
                    height: 20px;
                    text-align: center;
                    width: 20px;
                    -webkit-font-smoothing: antialiased;
                    -moz-osx-font-smoothing: grayscale;
                }
                #holler-cache-notice-container .notice-dismiss:hover:before {
                    color: #d63638;
                }
                @media screen and (max-width: 782px) {
                    #holler-cache-notice-container {
                        top: 46px;
                    }
                }
            ');
        }
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
     * Handle AJAX request to get cache status
     */
    public function handle_cache_status() {
        // Check nonce without specifying the field name
        check_ajax_referer('holler_cache_control_status');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'holler-cache-control'));
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

    public function handle_purge_cache() {
        check_ajax_referer('holler_purge_' . $_POST['type']);

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'holler-cache-control'));
            return;
        }

        if (!isset($_POST['type'])) {
            wp_send_json_error(__('No cache type specified.', 'holler-cache-control'));
            return;
        }

        $type = sanitize_text_field($_POST['type']);
        $result = array('success' => false, 'message' => '');

        // If type is 'all', purge all active caches in specific order
        if ($type === 'all') {
            // Get status of all caches
            $statuses = array(
                'redis' => \HollerCacheControl\Cache\Redis::get_status(),
                'nginx' => \HollerCacheControl\Cache\Nginx::get_status(),
                'cloudflare' => \HollerCacheControl\Cache\Cloudflare::get_status(),
                'cloudflare-apo' => \HollerCacheControl\Cache\CloudflareAPO::get_status()
            );
            
            // Define cache types in specific order, but only include active ones
            $caches = array();
            if ($statuses['redis']['active']) {
                $caches[] = 'redis';           // 1. Clear Redis object cache first
            }
            if ($statuses['nginx']['active']) {
                $caches[] = 'nginx';           // 2. Clear Nginx page cache second
            }
            if ($statuses['cloudflare']['active']) {
                $caches[] = 'cloudflare';      // 3. Clear Cloudflare cache last
            }
            if ($statuses['cloudflare-apo']['active']) {
                $caches[] = 'cloudflare-apo';  // 4. Clear Cloudflare APO last
            }
            
            if (empty($caches)) {
                wp_send_json_error(__('No active caches found to purge.', 'holler-cache-control'));
                return;
            }
            
            $success = true;
            $messages = array();

            foreach ($caches as $cache_type) {
                $cache_result = $this->purge_single_cache($cache_type);
                if (!empty($cache_result['message'])) {
                    $messages[] = $cache_result['message'];
                }
                if (!$cache_result['success']) {
                    $success = false;
                }
            }
            
            if ($success) {
                wp_send_json_success(implode("\n", $messages));
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

    /**
     * Add the admin bar menu
     */
    public function admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get settings
        $hide_nginx = get_option('hide_nginx_purge_button', '0') === '1';
        $hide_redis = get_option('hide_redis_purge_button', '0') === '1';

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

        // Add purge cache menu
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'holler-purge-cache',
            'title' => __('Purge Cache', 'holler-cache-control')
        ));

        // Add purge all option first
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-purge-cache',
            'id' => 'holler-purge-all',
            'title' => '<a href="#" class="holler-purge-cache" data-cache-type="all">' . 
                      __('Purge All Caches', 'holler-cache-control') . '</a>'
        ));

        // Add separator after purge all
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-purge-cache',
            'id' => 'holler-purge-separator',
            'title' => ''
        ));

        // Only show individual purge buttons for active caching services
        if ($nginx_status['active'] && !$hide_nginx) {
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-purge-cache',
                'id' => 'holler-purge-nginx',
                'title' => '<a href="#" class="holler-purge-cache" data-cache-type="nginx">' . 
                          __('Purge Nginx Page Cache', 'holler-cache-control') . '</a>'
            ));
        }

        if ($redis_status['active'] && !$hide_redis) {
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-purge-cache',
                'id' => 'holler-purge-redis',
                'title' => '<a href="#" class="holler-purge-cache" data-cache-type="redis">' . 
                          __('Purge Redis Object Cache', 'holler-cache-control') . '</a>'
            ));
        }

        if ($cloudflare_status['active']) {
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-purge-cache',
                'id' => 'holler-purge-cloudflare',
                'title' => '<a href="#" class="holler-purge-cache" data-cache-type="cloudflare">' . 
                          __('Purge Cloudflare Cache', 'holler-cache-control') . '</a>'
            ));
        }

        if ($cloudflare_apo_status['active']) {
            $wp_admin_bar->add_menu(array(
                'parent' => 'holler-purge-cache',
                'id' => 'holler-purge-cloudflare-apo',
                'title' => '<a href="#" class="holler-purge-cache" data-cache-type="cloudflare-apo">' . 
                          __('Purge Cloudflare APO', 'holler-cache-control') . '</a>'
            ));
        }

        // Add separator
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

    public function add_notice_container() {
        echo '<div id="holler-cache-notice-container"></div>';
    }
}

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

        // Add admin bar menu with high priority to ensure it runs after other plugins
        add_action('wp_before_admin_bar_render', array($this, 'remove_original_buttons'), 999);
        add_action('admin_bar_menu', array($this, 'modify_admin_bar'), 100);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Register AJAX handlers
        add_action('wp_ajax_holler_cache_control_purge', array($this, 'handle_purge_cache'));
        add_action('wp_ajax_holler_cache_control_status', array($this, 'handle_cache_status'));

        // Enqueue scripts for both admin and front-end when user is logged in
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Add cache purging hooks
        $this->add_cache_purging_hooks();
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
     * Purge all caches when triggered by WordPress actions
     */
    public function purge_all_caches_on_update() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Clear WordPress core caches
        wp_cache_flush(); // Clear object cache
        clean_post_cache(get_the_ID()); // Clear post cache if we're in a post context
        clean_term_cache(array(), '', false); // Clear term cache
        clean_user_cache(get_current_user_id()); // Clear current user cache
        $this->delete_transient_cache(); // Clear transients

        // Clear Elementor caches
        $this->purge_elementor_cache();

        // Clear Astra caches
        $this->purge_astra_cache();

        // Purge Nginx cache
        $nginx_result = Nginx::purge_cache();
        if (!$nginx_result['success']) {
            error_log('Nginx cache purge failed: ' . $nginx_result['message']);
        }

        // Purge Redis cache
        $redis_result = Redis::purge_cache();
        if (!$redis_result['success']) {
            error_log('Redis cache purge failed: ' . $redis_result['message']);
        }

        // Purge Cloudflare cache
        $cloudflare_result = Cloudflare::purge_cache();
        if (!$cloudflare_result['success']) {
            error_log('Cloudflare cache purge failed: ' . $cloudflare_result['message']);
        }

        // Purge Cloudflare APO cache
        $cloudflare_apo_result = CloudflareAPO::purge_cache();
        if (!$cloudflare_apo_result['success']) {
            error_log('Cloudflare APO cache purge failed: ' . $cloudflare_apo_result['message']);
        }
    }

    /**
     * Purge Elementor-specific caches
     */
    public function purge_elementor_cache() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Clear Elementor's CSS cache
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        // Clear Elementor's "generated_css" folder
        $upload_dir = wp_upload_dir();
        $elementor_css_dir = $upload_dir['basedir'] . '/elementor/css';
        if (is_dir($elementor_css_dir)) {
            array_map('unlink', glob("$elementor_css_dir/*.*"));
        }

        // Clear Elementor data cache
        delete_post_meta_by_key('_elementor_css');
        delete_post_meta_by_key('_elementor_data');
        delete_option('_elementor_global_css');
        delete_option('elementor-custom-breakpoints');
        wp_cache_delete('elementor_selected_kit');
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
                'default' => 'fastcgi',
                'sanitize_callback' => function($value) {
                    return in_array($value, array('fastcgi', 'redis')) ? $value : 'fastcgi';
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

    public function enqueue_assets() {
        // Only enqueue if user can manage options and admin bar is showing
        if (!current_user_can('manage_options') || !is_admin_bar_showing()) {
            return;
        }

        // Enqueue CSS
        if (is_admin()) {
            wp_enqueue_style(
                $this->plugin_name,
                plugin_dir_url(dirname(__DIR__)) . 'assets/css/admin.css',
                array(),
                $this->version,
                'all'
            );
        } else {
            // Add admin CSS for notices on front-end
            wp_enqueue_style(
                'wp-admin',
                includes_url('css/admin-bar.min.css')
            );
        }

        // Enqueue JS
        $js_file = plugin_dir_path(dirname(__DIR__)) . 'assets/js/admin.js';
        $version = file_exists($js_file) ? filemtime($js_file) : $this->version;

        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(dirname(__DIR__)) . 'assets/js/admin.js',
            array('jquery'),
            $version,
            true
        );

        wp_localize_script(
            $this->plugin_name,
            'hollerCacheControl',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('holler_cache_control_nonce'),
                'i18n' => array(
                    'purging' => __('Purging...', 'holler-cache-control'),
                    'purged' => __('Purged!', 'holler-cache-control'),
                    'error' => __('Error: ', 'holler-cache-control'),
                    'confirm_purge_nginx' => __('Are you sure you want to purge the Nginx cache?', 'holler-cache-control'),
                    'confirm_purge_redis' => __('Are you sure you want to purge the Redis cache?', 'holler-cache-control'),
                    'confirm_purge_cloudflare' => __('Are you sure you want to purge the Cloudflare cache?', 'holler-cache-control'),
                    'confirm_purge_cloudflare_apo' => __('Are you sure you want to purge the Cloudflare APO cache?', 'holler-cache-control'),
                    'confirm_purge_all' => __('Are you sure you want to purge all caches?', 'holler-cache-control')
                )
            )
        );
    }

    public function add_tools_page() {
        add_management_page(
            __('Cache Control', 'holler-cache-control'),
            __('Cache Control', 'holler-cache-control'),
            'manage_options',
            $this->plugin_name,
            array($this, 'render_tools_page')
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

    public function render_tools_page() {
        $cache_status = self::get_cache_systems_status();
        extract($cache_status);
        require_once plugin_dir_path(__FILE__) . 'views/admin-display.php';
    }

    /**
     * Handle AJAX request for cache purging
     */
    public function handle_purge_cache() {
        check_ajax_referer('holler_cache_control_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $cache_type = isset($_POST['cache_type']) ? sanitize_text_field($_POST['cache_type']) : '';
        $success_messages = array();
        $error_messages = array();

        // Get current cache systems status
        $cache_status = self::get_cache_systems_status();

        // Handle purge all caches
        if ($cache_type === 'all') {
            // Purge Nginx cache if active
            if ($cache_status['nginx']['active']) {
                $result = Nginx::purge_cache();
                if ($result['success']) {
                    $success_messages[] = __('Nginx Cache cleared', 'holler-cache-control');
                } else {
                    $error_messages[] = $result['message'];
                }
            }

            // Purge Redis cache if active
            if ($cache_status['redis']['active']) {
                $result = Redis::purge_cache();
                if ($result['success']) {
                    $success_messages[] = __('Redis Object Cache cleared', 'holler-cache-control');
                } else {
                    $error_messages[] = $result['message'];
                }
            }

            // Purge Cloudflare cache if active
            if ($cache_status['cloudflare']['active']) {
                $result = Cloudflare::purge_cache();
                if ($result['success']) {
                    $success_messages[] = __('Cloudflare Cache purged successfully', 'holler-cache-control');
                } else {
                    $error_messages[] = $result['message'];
                }
            }

            // Purge Cloudflare APO cache if active
            if ($cache_status['cloudflare-apo']['active']) {
                $result = CloudflareAPO::purge_cache();
                if ($result['success']) {
                    $success_messages[] = $result['message'];
                } else {
                    $error_messages[] = $result['message'];
                }
            }

            if (!empty($error_messages)) {
                wp_send_json_error(implode(', ', $error_messages));
            } else {
                wp_send_json_success(implode(', ', $success_messages));
            }
            
        } else {
            // Handle individual cache purge
            $result = array('success' => false, 'message' => __('Invalid cache type', 'holler-cache-control'));
            
            // Check if the requested cache type is active before purging
            switch ($cache_type) {
                case 'nginx':
                    if ($cache_status['nginx']['active']) {
                        $result = Nginx::purge_cache();
                    } else {
                        $result = array('success' => false, 'message' => __('Nginx cache is not active', 'holler-cache-control'));
                    }
                    break;

                case 'redis':
                    if ($cache_status['redis']['active']) {
                        $result = Redis::purge_cache();
                    } else {
                        $result = array('success' => false, 'message' => __('Redis cache is not active', 'holler-cache-control'));
                    }
                    break;

                case 'cloudflare':
                    if ($cache_status['cloudflare']['active']) {
                        $result = Cloudflare::purge_cache();
                    } else {
                        $result = array('success' => false, 'message' => __('Cloudflare cache is not active', 'holler-cache-control'));
                    }
                    break;

                case 'cloudflare-apo':
                    if ($cache_status['cloudflare-apo']['active']) {
                        $result = CloudflareAPO::purge_cache();
                    } else {
                        $result = array('success' => false, 'message' => __('Cloudflare APO is not active', 'holler-cache-control'));
                    }
                    break;
            }

            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }
        }
    }

    /**
     * Handle AJAX request for cache status
     */
    public function handle_cache_status() {
        check_ajax_referer('holler_cache_control_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $status = self::get_cache_systems_status();
        wp_send_json_success($status);
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

    public function modify_admin_bar($wp_admin_bar) {
        // Check if user has permission
        if (!current_user_can('manage_options')) {
            return;
        }

        // Add the parent menu item
        $wp_admin_bar->add_menu(array(
            'id' => 'holler-cache-control',
            'title' => __('Cache Control', 'holler-cache-control'),
            'href' => admin_url('admin.php?page=holler-cache-control'),
        ));

        // Add Purge All Caches button
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'wp-admin-bar-purge-all',
            'title' => sprintf(
                '<a href="#" class="ab-item purge-cache" data-cache-type="all">%s</a>',
                __('Purge All Caches', 'holler-cache-control')
            ),
            'meta' => array('class' => 'purge-button')
        ));

        // Add separator after Purge All
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'cache-control-separator-top',
            'title' => '<hr style="margin: 5px 0; border: 0; border-top: 1px solid rgba(255,255,255,0.2);">',
            'meta' => array('class' => 'separator')
        ));

        // Add Nginx cache controls
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'wp-admin-bar-purge-nginx',
            'title' => sprintf(
                '<a href="#" class="ab-item purge-cache" data-cache-type="nginx">%s</a>',
                __('Purge Nginx Cache', 'holler-cache-control')
            ),
            'meta' => array('class' => 'purge-button')
        ));

        // Add Redis cache controls
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'wp-admin-bar-purge-redis',
            'title' => sprintf(
                '<a href="#" class="ab-item purge-cache" data-cache-type="redis">%s</a>',
                __('Purge Redis Cache', 'holler-cache-control')
            ),
            'meta' => array('class' => 'purge-button')
        ));

        // Add Cloudflare cache controls
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'wp-admin-bar-purge-cloudflare',
            'title' => sprintf(
                '<a href="#" class="ab-item purge-cache" data-cache-type="cloudflare">%s</a>',
                __('Purge Cloudflare Cache', 'holler-cache-control')
            ),
            'meta' => array('class' => 'purge-button')
        ));

        // Add Cloudflare APO cache controls
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'wp-admin-bar-purge-cloudflare-apo',
            'title' => sprintf(
                '<a href="#" class="ab-item purge-cache" data-cache-type="cloudflare-apo">%s</a>',
                __('Purge Cloudflare APO Cache', 'holler-cache-control')
            ),
            'meta' => array('class' => 'purge-button')
        ));

        // Add separator before settings link
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'cache-control-separator',
            'title' => '<hr style="margin: 5px 0; border: 0; border-top: 1px solid rgba(255,255,255,0.2);">',
            'meta' => array('class' => 'separator')
        ));

        // Add settings link
        $wp_admin_bar->add_menu(array(
            'parent' => 'holler-cache-control',
            'id' => 'cache-control-settings',
            'title' => __('Settings', 'holler-cache-control'),
            'href' => admin_url('admin.php?page=holler-cache-control')
        ));
    }
}

<?php
/**
 * The core plugin class.
 *
 * @package HollerCacheControl
 */

class Holler_Cache_Control {

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @var Holler_Cache_Control_Loader
     */
    protected $loader;

    /**
     * Initialize the class and set its properties.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     */
    private function load_dependencies() {
        require_once HOLLER_CACHE_CONTROL_DIR . 'includes/class-holler-cache-control-loader.php';
        require_once HOLLER_CACHE_CONTROL_DIR . 'includes/class-holler-cache-control-admin.php';
        require_once HOLLER_CACHE_CONTROL_DIR . 'includes/class-holler-cache-control-cloudflare.php';

        $this->loader = new Holler_Cache_Control_Loader();
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     */
    private function define_admin_hooks() {
        $plugin_admin = new Holler_Cache_Control_Admin();

        // Admin menu and settings
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');
        $this->loader->add_action('admin_init', $plugin_admin, 'register_settings');

        // Admin bar
        $this->loader->add_action('admin_bar_menu', $plugin_admin, 'add_admin_bar_menu', 100);

        // AJAX handlers
        $this->loader->add_action('wp_ajax_purge_all_caches', $plugin_admin, 'handle_purge_all_caches');
        
        // Admin notices
        $this->loader->add_action('admin_notices', $plugin_admin, 'display_admin_notices');
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     */
    public function run() {
        $this->loader->run();
    }
}

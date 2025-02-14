<?php
namespace HollerCacheControl\Core;

use HollerCacheControl\Admin\Tools;
use HollerCacheControl\Core\Loader;

/**
 * The core plugin class.
 *
 * @since      1.0.0
 * @package    HollerCacheControl
 * @subpackage HollerCacheControl\Core
 */
class Plugin {
    /**
     * The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $plugin_name    The string used to uniquely identify this plugin.
     */
    protected $plugin_name;

    /**
     * The current version of the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $version    The current version of the plugin.
     */
    protected $version;

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * Initialize the core functionality of the plugin.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->plugin_name = 'holler-cache-control';
        $this->version = HOLLER_CACHE_CONTROL_VERSION;

        $this->load_dependencies();
        $this->define_admin_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies() {
        $this->loader = new Loader();
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks() {
        $admin = new \HollerCacheControl\Admin\Tools($this->get_plugin_name(), $this->get_version());

        // Add menu items
        $this->loader->add_action('admin_menu', $admin, 'add_plugin_admin_menu');
        $this->loader->add_action('admin_bar_menu', $admin, 'admin_bar_menu', 100);

        // Register settings
        $this->loader->add_action('admin_init', $admin, 'register_settings');

        // Register AJAX handlers for cache status and purging
        $this->loader->add_action('wp_ajax_holler_cache_status', $admin, 'handle_cache_status');
        $this->loader->add_action('wp_ajax_holler_purge_all', $admin, 'handle_purge_cache');
        $this->loader->add_action('wp_ajax_holler_purge_nginx', $admin, 'handle_purge_cache');
        $this->loader->add_action('wp_ajax_holler_purge_redis', $admin, 'handle_purge_cache');
        $this->loader->add_action('wp_ajax_holler_purge_cloudflare', $admin, 'handle_purge_cache');
        $this->loader->add_action('wp_ajax_holler_purge_cloudflare_apo', $admin, 'handle_purge_cache');

        // Enqueue admin scripts and styles
        $this->loader->add_action('admin_enqueue_scripts', $admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $admin, 'enqueue_scripts');
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @since     1.0.0
     * @return    string    The name of the plugin.
     */
    public function get_plugin_name() {
        return $this->plugin_name;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @since     1.0.0
     * @return    string    The version number of the plugin.
     */
    public function get_version() {
        return $this->version;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     * @since     1.0.0
     * @return    Loader    Orchestrates the hooks of the plugin.
     */
    public function get_loader() {
        return $this->loader;
    }
}

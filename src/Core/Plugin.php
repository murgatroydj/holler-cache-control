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
        $tools = new Tools($this->get_plugin_name(), $this->get_version());
        
        // Register settings page
        $this->loader->add_action('admin_menu', $tools, 'add_tools_page');
        
        // Register AJAX handlers
        $this->loader->add_action('wp_ajax_holler_cache_control_purge', $tools, 'handle_purge_cache');
        $this->loader->add_action('wp_ajax_holler_cache_control_status', $tools, 'handle_cache_status');
        
        // Register admin bar modifications
        $this->loader->add_action('wp_before_admin_bar_render', $tools, 'remove_original_buttons', 999);
        $this->loader->add_action('admin_bar_menu', $tools, 'modify_admin_bar', 100);
        
        // Register asset enqueuing
        $this->loader->add_action('admin_enqueue_scripts', $tools, 'enqueue_assets');
        $this->loader->add_action('wp_enqueue_scripts', $tools, 'enqueue_assets');
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

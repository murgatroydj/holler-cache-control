<?php
/**
 * The plugin bootstrap file
 *
 * @link              https://hollerdigital.com
 * @since             1.0.0
 * @package           HollerCacheControl
 *
 * @wordpress-plugin
 * Plugin Name:       Holler Cache Control
 * Plugin URI:        https://github.com/hollerdigital/holler-cache-control
 * Description:       Advanced cache control for WordPress sites running on GridPane with Redis, Nginx, and Cloudflare.
 * Version:           1.2.0
 * Author:            Holler Digital
 * Author URI:        https://hollerdigital.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       holler-cache-control
 * Domain Path:       /languages
 * GitHub Plugin URI: hollerdigital/holler-cache-control
 * GitHub Branch:     master
 * Requires PHP:      7.4
 * Requires at least: 5.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include the plugin update checker
require_once plugin_dir_path(__FILE__) . 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Setup the update checker
$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/murgatroydj/holler-cache-control/',
    __FILE__,
    'holler-cache-control'
);

// Set the branch that contains the stable release
$updateChecker->setBranch('master');

// Optional: Enable Releases instead of just tags
$updateChecker->getVcsApi()->enableReleaseAssets();

// Define plugin version
define('HOLLER_CACHE_CONTROL_VERSION', '1.2.0');

// Load required files - these need to be loaded before the autoloader
require_once plugin_dir_path(__FILE__) . 'src/Core/Loader.php';
require_once plugin_dir_path(__FILE__) . 'src/Core/Plugin.php';
require_once plugin_dir_path(__FILE__) . 'src/Admin/Tools.php';
require_once plugin_dir_path(__FILE__) . 'src/Cache/Nginx.php';
require_once plugin_dir_path(__FILE__) . 'src/Cache/Redis.php';
require_once plugin_dir_path(__FILE__) . 'src/Cache/Cloudflare.php';
require_once plugin_dir_path(__FILE__) . 'src/Cache/CloudflareAPO.php';

// Register autoloader for any additional plugin classes
spl_autoload_register(function ($class) {
    // Plugin namespace prefix
    $prefix = 'HollerCacheControl\\';
    $base_dir = plugin_dir_path(__FILE__) . 'src/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace namespace separators with directory separators
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists and hasn't been loaded yet, require it
    if (file_exists($file) && !class_exists($class)) {
        require $file;
    }
});

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
function run_holler_cache_control() {
    // Register activation hook
    register_activation_hook(__FILE__, function() {
        // Register our async action if not already registered
        if (!wp_next_scheduled('holler_cache_control_async_purge')) {
            wp_schedule_single_event(time(), 'holler_cache_control_async_purge');
        }
    });

    // Initialize plugin
    $plugin = new HollerCacheControl\Core\Plugin();
    $plugin->run();
}

run_holler_cache_control();

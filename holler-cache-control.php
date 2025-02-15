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

// Define plugin version
define('HOLLER_CACHE_CONTROL_VERSION', '1.2.0');

// Try to include the plugin update checker if it exists
$plugin_update_checker_file = plugin_dir_path(__FILE__) . 'vendor/plugin-update-checker/plugin-update-checker.php';
if (file_exists($plugin_update_checker_file)) {
    require_once $plugin_update_checker_file;

    // Setup the update checker for public repository
    $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/HollerDigital/holler-cache-control',
        __FILE__,
        'holler-cache-control'
    );

    // Set the branch that contains the stable release
    $updateChecker->setBranch('master');

    // Enable Releases instead of just tags
    $updateChecker->getVcsApi()->enableReleaseAssets();
}

// Load required files - these need to be loaded before the autoloader
$required_files = array(
    'src/Core/Loader.php',
    'src/Core/Plugin.php',
    'src/Admin/Tools.php',
    'src/Cache/Nginx.php',
    'src/Cache/Redis.php',
    'src/Cache/Cloudflare.php',
    'src/Cache/CloudflareAPO.php'
);

// Check if all required files exist
$missing_files = array();
foreach ($required_files as $file) {
    $file_path = plugin_dir_path(__FILE__) . $file;
    if (!file_exists($file_path)) {
        $missing_files[] = $file;
    }
}

// If any required files are missing, show admin notice and return
if (!empty($missing_files)) {
    add_action('admin_notices', function() use ($missing_files) {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Holler Cache Control plugin is missing required files:', 'holler-cache-control'); ?></p>
            <ul>
                <?php foreach ($missing_files as $file): ?>
                    <li><?php echo esc_html($file); ?></li>
                <?php endforeach; ?>
            </ul>
            <p><?php _e('Please reinstall the plugin or contact support.', 'holler-cache-control'); ?></p>
        </div>
        <?php
    });
    return;
}

// Load all required files
foreach ($required_files as $file) {
    require_once plugin_dir_path(__FILE__) . $file;
}

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

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
function run_holler_cache_control() {
    $plugin = new HollerCacheControl\Core\Plugin();
    $plugin->run();
}

run_holler_cache_control();

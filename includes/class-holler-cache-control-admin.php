<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package HollerCacheControl
 */

class Holler_Cache_Control_Admin {

    /**
     * Add plugin admin menu
     */
    public function add_plugin_admin_menu() {
        add_submenu_page(
            'options-general.php',
            __('Cache Control', 'holler-cache-control'),
            __('Cache Control', 'holler-cache-control'),
            'manage_options',
            'holler-cache-control',
            array($this, 'display_plugin_admin_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('holler_cache_settings', 'holler_cache_cloudflare_email');
        register_setting('holler_cache_settings', 'holler_cache_cloudflare_api_key');
        register_setting('holler_cache_settings', 'holler_cache_cloudflare_zone_id');
    }

    /**
     * Display the admin page
     */
    public function display_plugin_admin_page() {
        $credentials = array(
            'email' => defined('CLOUDFLARE_EMAIL'),
            'api_key' => defined('CLOUDFLARE_API_KEY'),
            'zone_id' => defined('CLOUDFLARE_ZONE_ID')
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if (array_filter($credentials)): ?>
            <div class="notice notice-info">
                <p><strong><?php _e('Note:', 'holler-cache-control'); ?></strong> <?php _e('Some settings are defined in wp-config.php or user-configs.php and will override any values set here:', 'holler-cache-control'); ?></p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <?php foreach ($credentials as $key => $is_constant): ?>
                        <?php if ($is_constant): ?>
                            <li><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?> <?php _e('is defined in configuration file', 'holler-cache-control'); ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('holler_cache_settings');
                do_settings_sections('holler_cache_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Cloudflare Email', 'holler-cache-control'); ?></th>
                        <td>
                            <?php if ($credentials['email']): ?>
                                <input type="text" disabled value="<?php _e('Defined in configuration file', 'holler-cache-control'); ?>" class="regular-text">
                            <?php else: ?>
                                <input type="email" name="holler_cache_cloudflare_email" value="<?php echo esc_attr(get_option('holler_cache_cloudflare_email')); ?>" class="regular-text">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Cloudflare API Key', 'holler-cache-control'); ?></th>
                        <td>
                            <?php if ($credentials['api_key']): ?>
                                <input type="text" disabled value="<?php _e('Defined in configuration file', 'holler-cache-control'); ?>" class="regular-text">
                            <?php else: ?>
                                <input type="password" name="holler_cache_cloudflare_api_key" value="<?php echo esc_attr(get_option('holler_cache_cloudflare_api_key')); ?>" class="regular-text">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Cloudflare Zone ID', 'holler-cache-control'); ?></th>
                        <td>
                            <?php if ($credentials['zone_id']): ?>
                                <input type="text" disabled value="<?php _e('Defined in configuration file', 'holler-cache-control'); ?>" class="regular-text">
                            <?php else: ?>
                                <input type="text" name="holler_cache_cloudflare_zone_id" value="<?php echo esc_attr(get_option('holler_cache_cloudflare_zone_id')); ?>" class="regular-text">
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php 
                // Only show submit button if at least one field isn't defined in constants
                if (!empty(array_filter($credentials, function($v) { return !$v; }))): 
                    submit_button();
                endif;
                ?>
            </form>

            <h2><?php _e('Configuration File Method', 'holler-cache-control'); ?></h2>
            <p><?php _e('You can also define these credentials in your wp-config.php or user-configs.php file using the following constants:', 'holler-cache-control'); ?></p>
            <pre style="background: #f0f0f1; padding: 15px; border: 1px solid #ccd0d4;">
define('CLOUDFLARE_EMAIL', 'your-email@example.com');
define('CLOUDFLARE_API_KEY', 'your-api-key');
define('CLOUDFLARE_ZONE_ID', 'your-zone-id');</pre>
            <p><?php _e('Constants defined in configuration files will take precedence over values set in the form above.', 'holler-cache-control'); ?></p>
        </div>
        <?php
    }

    /**
     * Add cache control menu to admin bar
     *
     * @param WP_Admin_Bar $wp_admin_bar WordPress Admin Bar object.
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'holler-cache-control',
            'title' => __('Cache Control', 'holler-cache-control'),
            'href'  => '#'
        ));

        $wp_admin_bar->add_node(array(
            'id'     => 'purge-all-caches',
            'title'  => __('Purge All Caches', 'holler-cache-control'),
            'parent' => 'holler-cache-control',
            'href'   => wp_nonce_url(admin_url('admin-ajax.php?action=purge_all_caches'), 'purge_all_caches')
        ));
    }

    /**
     * Handle the purge all caches AJAX request
     */
    public function handle_purge_all_caches() {
        if (!current_user_can('manage_options')) {
            wp_die(-1);
        }

        check_ajax_referer('purge_all_caches');

        $status_messages = array();
        $has_errors = false;

        // Purge Redis Object Cache if active
        if (function_exists('wp_cache_flush')) {
            if (wp_cache_flush()) {
                $status_messages[] = __('Redis Object Cache purged successfully', 'holler-cache-control');
            } else {
                $status_messages[] = __('Failed to purge Redis Object Cache', 'holler-cache-control');
                $has_errors = true;
            }
        }

        // Purge Cloudflare Cache
        $cloudflare_result = Holler_Cache_Control_Cloudflare::purge_cache();
        if ($cloudflare_result['success']) {
            $status_messages[] = __('Cloudflare cache purged successfully', 'holler-cache-control');
        } else {
            $status_messages[] = sprintf(__('Cloudflare cache purge failed: %s', 'holler-cache-control'), $cloudflare_result['message']);
            $has_errors = true;
        }

        // Store messages in transient
        set_transient('holler_cache_messages', array(
            'messages' => $status_messages,
            'has_errors' => $has_errors
        ), 30);

        wp_send_json_success(array(
            'messages' => $status_messages,
            'has_errors' => $has_errors
        ));
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        $notices = get_transient('holler_cache_messages');
        if (!$notices) {
            return;
        }

        $class = $notices['has_errors'] ? 'notice-error' : 'notice-success';
        
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible">';
        foreach ($notices['messages'] as $message) {
            echo '<p>' . esc_html($message) . '</p>';
        }
        echo '</div>';

        delete_transient('holler_cache_messages');
    }
}

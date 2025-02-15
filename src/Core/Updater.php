<?php
/**
 * Plugin updater class.
 *
 * @package HollerCacheControl
 */

class Holler_Cache_Control_Updater {
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $github_response;
    private $github_url = 'https://github.com/hollerdigital/holler-cache-control';
    private $github_api_url = 'https://api.github.com/repos/hollerdigital/holler-cache-control';
    private $access_token;

    public function __construct($file) {
        $this->file = $file;
        
        // Add update check filters
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Optional: Add authentication token for private repos
        $this->access_token = defined('GITHUB_ACCESS_TOKEN') ? GITHUB_ACCESS_TOKEN : '';
        
        // Set plugin properties
        add_action('admin_init', array($this, 'set_plugin_properties'));
    }

    public function set_plugin_properties() {
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $this->plugin = get_plugin_data($this->file);
        $this->basename = plugin_basename($this->file);
        $this->active = is_plugin_active($this->basename);
    }

    private function get_repository_info() {
        if (is_null($this->github_response)) {
            $request_uri = $this->github_api_url . '/releases/latest';
            $args = array();

            if ($this->access_token) {
                $args['headers'] = array(
                    'Authorization' => 'token ' . $this->access_token
                );
            }

            $response = wp_remote_get($request_uri, $args);

            if (is_wp_error($response)) {
                return false;
            }

            $this->github_response = json_decode(wp_remote_retrieve_body($response));
        }
    }

    public function modify_transient($transient) {
        if (property_exists($transient, 'checked')) {
            if ($checked = $transient->checked) {
                $this->get_repository_info();

                if (!is_object($this->github_response)) {
                    return $transient;
                }

                $out_of_date = version_compare(
                    $this->github_response->tag_name,
                    $checked[$this->basename],
                    'gt'
                );

                if ($out_of_date) {
                    $plugin = array(
                        'url' => $this->plugin["PluginURI"],
                        'slug' => current(explode('/', $this->basename)),
                        'package' => $this->github_response->zipball_url,
                        'new_version' => $this->github_response->tag_name
                    );

                    if ($this->access_token) {
                        $plugin['package'] = add_query_arg(array('access_token' => $this->access_token), $plugin['package']);
                    }

                    $transient->response[$this->basename] = (object) $plugin;
                }
            }
        }

        return $transient;
    }

    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!empty($args->slug)) {
            if ($args->slug == current(explode('/', $this->basename))) {
                $this->get_repository_info();

                $plugin = array(
                    'name'              => $this->plugin["Name"],
                    'slug'              => $this->basename,
                    'version'           => $this->github_response->tag_name,
                    'author'            => $this->plugin["AuthorName"],
                    'author_profile'    => $this->plugin["AuthorURI"],
                    'last_updated'      => $this->github_response->published_at,
                    'homepage'          => $this->plugin["PluginURI"],
                    'short_description' => $this->plugin["Description"],
                    'sections'          => array(
                        'Description'   => $this->plugin["Description"],
                        'Updates'       => $this->github_response->body,
                    ),
                    'download_link'     => $this->github_response->zipball_url
                );

                if ($this->access_token) {
                    $plugin['download_link'] = add_query_arg(
                        array('access_token' => $this->access_token),
                        $plugin['download_link']
                    );
                }

                return (object) $plugin;
            }
        }

        return $result;
    }

    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        if ($this->active) {
            activate_plugin($this->basename);
        }

        return $result;
    }
}

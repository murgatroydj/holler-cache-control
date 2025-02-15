# Holler Cache Control

A comprehensive cache management solution for WordPress, integrating Redis Object Cache and Cloudflare with an easy-to-use interface.

## Features

- Redis Object Cache integration
- Cloudflare cache management
- Admin bar quick actions
- Flexible credential management (config file or UI-based)
- Cache status overview
- One-click cache purging

## Installation

1. Upload the plugin files to the `/wp-content/plugins/holler-cache-control` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your Cloudflare credentials either through the settings page or via wp-config.php

## Configuration

You can configure your Cloudflare credentials in two ways:

### Method 1: WordPress Admin Interface

1. Go to Settings > Cache Control
2. Enter your Cloudflare credentials:
   - Email
   - API Key
   - Zone ID
3. Save Changes

### Method 2: Configuration File

Add the following constants to your wp-config.php or user-configs.php file:

```php
define('CLOUDFLARE_EMAIL', 'your-email@example.com');
define('CLOUDFLARE_API_KEY', 'your-api-key');
define('CLOUDFLARE_ZONE_ID', 'your-zone-id');
```

Note: Constants defined in configuration files will take precedence over values set in the admin interface.

## Usage

### Admin Bar

The plugin adds a Cache Control menu to your admin bar with quick actions:
- Purge All Caches: Purges both Redis and Cloudflare caches

### Settings Page

Access the settings page via Settings > Cache Control to:
- Configure Cloudflare credentials
- View cache status
- Manage cache settings

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Redis Object Cache plugin (optional but recommended)
- Cloudflare account with API access

## Support

For support, please visit [https://hollerdigital.com](https://hollerdigital.com) or create an issue in our GitHub repository.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [Holler Digital](https://hollerdigital.com)

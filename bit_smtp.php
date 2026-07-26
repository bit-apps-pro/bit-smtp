<?php

if (! \defined('ABSPATH')) {
    exit;
}
/**
 * Plugin Name: Bit SMTP
 * Plugin URI:  https://www.bitapps.pro/bit-smtp
 * Description: Send email via SMTP using BIT SMTP plugin by Bit Apps
 * Version:     1.2.4
 * Author:      Bit Apps
 * Author URI:  https://bitapps.pro
 * Text Domain: bit-smtp
 * Requires PHP: 8.1
 * Requires WP: 5.0
 * Domain Path: /languages
 * License: GPLv2 or later
 */

// Hard floor: the plugin's classes use PHP 8.1 syntax (enums) that parse-fatals on older PHP.
// Guard BEFORE loading any autoloaded class so an already-active install on <8.1 degrades to an
// admin notice instead of a white screen. Keep this block 8.0-parseable.
if (\PHP_VERSION_ID < 80100) {
    add_action(is_multisite() ? 'network_admin_notices' : 'admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Bit SMTP requires PHP 8.1 or newer. The plugin is inactive until PHP is upgraded.', 'bit-smtp')
        );
    });

    return;
}

require_once plugin_dir_path(__FILE__) . 'backend/bootstrap.php';

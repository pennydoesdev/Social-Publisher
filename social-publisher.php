<?php
/**
 * Plugin Name:       Social Publisher
 * Description:       Auto-generates platform-specific Publer drafts for every published post. Auto-discovers post types and connected Publer accounts, generates per-platform copy with OpenAI, and creates one draft per provider.
 * Version:           1.00
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Penny Constellation
 * Author URI:        https://github.com/pennydoesdev
 * License:           GPL-2.0-or-later
 * Copyright:         (c) 2026 Penny Constellation
 * Text Domain:       social-publisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOCIAL_PUBLISHER_VERSION', '1.00' );
define( 'SOCIAL_PUBLISHER_FILE', __FILE__ );
define( 'SOCIAL_PUBLISHER_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOCIAL_PUBLISHER_URL', plugin_dir_url( __FILE__ ) );
define( 'SOCIAL_PUBLISHER_BASENAME', plugin_basename( __FILE__ ) );

require_once SOCIAL_PUBLISHER_DIR . 'includes/class-logger.php';
require_once SOCIAL_PUBLISHER_DIR . 'includes/class-settings.php';
require_once SOCIAL_PUBLISHER_DIR . 'includes/class-publer-client.php';
require_once SOCIAL_PUBLISHER_DIR . 'includes/class-openai-client.php';
require_once SOCIAL_PUBLISHER_DIR . 'includes/class-shortio-client.php';
require_once SOCIAL_PUBLISHER_DIR . 'includes/class-publisher.php';
require_once SOCIAL_PUBLISHER_DIR . 'includes/class-meta-box.php';
require_once SOCIAL_PUBLISHER_DIR . 'includes/class-rest.php';
require_once SOCIAL_PUBLISHER_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, [ 'Social_Publisher\\Plugin', 'on_activate' ] );
register_deactivation_hook( __FILE__, [ 'Social_Publisher\\Plugin', 'on_deactivate' ] );

add_action( 'plugins_loaded', static function () {
	Social_Publisher\Plugin::instance()->boot();
} );

<?php
/**
 * Plugin Name: WP Smart Search
 * Description: AJAX-поиск + REST API (Application Password) с индексацией ссылок для дедупликации.
 * Version:     2.2.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 * Text Domain: wp-smart-search
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSS_VERSION', '2.2.0' );
define( 'WSS_PLUGIN_FILE', __FILE__ );
define( 'WSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WSS_PLUGIN_DIR . 'includes/class-wss-config.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-cache.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-rate-limiter.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-refang.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-links-indexer.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-search-engine.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-ajax-handler.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-rest-controller.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-admin-page.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-plugin.php';

register_activation_hook( __FILE__, [ 'WSS_Links_Indexer', 'install_table' ] );

WSS_Plugin::instance()->init();
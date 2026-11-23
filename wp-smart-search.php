<?php
/**
 * Plugin Name: WP Smart Search
 * Description: AJAX-поиск + REST API (Application Password) с индексацией ссылок для дедупликации.
 * Version:     2.7.1
 * Author:      Gnostis
 * License:     GPL-2.0-or-later
 * Text Domain: wp-smart-search
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Update URI:  https://github.com/Gnoztis/WP-Smart-Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSS_VERSION', '2.7.1' );
define( 'WSS_PLUGIN_FILE', __FILE__ );
define( 'WSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WSS_PLUGIN_DIR . 'includes/class-wss-config.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-morphology.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-cache.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-rate-limiter.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-refang.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-links-indexer.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-index-backfill.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-search-engine.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-ajax-handler.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-rest-controller.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-admin-page.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-github-updater.php';
require_once WSS_PLUGIN_DIR . 'includes/class-wss-plugin.php';

register_activation_hook( __FILE__, [ 'WSS_Links_Indexer', 'install_table' ] );

// A fresh install (or a reinstall after the plugin folder was replaced)
// has an empty index: queue the rebuild right away.
register_activation_hook(
	__FILE__,
	static function (): void {
		WSS_Index_Backfill::start();
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WSS_PLUGIN_DIR . 'includes/class-wss-cli.php';
	WP_CLI::add_command( 'wss index', new WSS_Cli() );
}

WSS_Plugin::instance()->init();
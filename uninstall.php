<?php
/**
 * Removes plugin data on uninstall.
 *
 * WordPress calls this for every installed copy; on multisite we clean
 * each site separately.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'wss_uninstall_site' ) ) {
	/**
	 * Drops the table and removes options and cache for the current site.
	 */
	function wss_uninstall_site(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wss_links';

		// A table name cannot be passed to prepare() — only the
		// $wpdb->prefix.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore -- a table name cannot be a prepare() placeholder.

		delete_option( 'wss_links_table_ver' );
		delete_option( 'wss_links_table_check' );
		delete_option( 'wss_cache_gen' );
		delete_option( 'wss_redis_down_until' );
		delete_option( 'wss_index_backfill_state' );
		delete_option( 'wss_index_runner' );
		delete_option( 'wss_index_autoload_fixed' );
		delete_option( 'wss_tags_gen' );
		delete_option( 'wss_index_queue' );
		delete_transient( 'wss_index_queue_lock' );
		wp_clear_scheduled_hook( 'wss_index_queue_worker' );
		delete_transient( 'wss_index_backfill_lock' );

		// The background rebuild is a single scheduled event; without this
		// the site keeps an orphaned cron entry that no code handles.
		wp_clear_scheduled_hook( 'wss_index_backfill' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_wss_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wss_' ) . '%'
			)
		);

		if ( ! is_array( $names ) ) {
			return;
		}

		foreach ( $names as $name ) {
			$name = (string) $name;
			foreach ( [ '_transient_timeout_', '_transient_' ] as $prefix ) {
				$len = strlen( $prefix );
				if ( strpos( $name, $prefix ) === 0 ) {
					$transient = substr( $name, $len );
					if ( $transient !== '' ) {
						delete_transient( $transient );
					}
					break;
				}
			}
		}
	}
}

if ( is_multisite() ) {
	$wss_sites = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( (array) $wss_sites as $wss_site_id ) {
		switch_to_blog( (int) $wss_site_id );
		wss_uninstall_site();
		restore_current_blog();
	}
} else {
	wss_uninstall_site();
}

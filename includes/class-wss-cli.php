<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands: wp wss index <status|rebuild|clear>.
 *
 * The class is loaded only when WP_CLI is defined, so the admin-ajax and
 * REST paths never touch it.
 */
final class WSS_Cli {

	private WSS_Index_Backfill $backfill;

	public function __construct( ?WSS_Index_Backfill $backfill = null ) {
		$this->backfill = $backfill ?? new WSS_Index_Backfill();
	}

	/**
	 * Shows the state of the links index.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss index status
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function status( $args = [], $assoc_args = [] ): void {
		// WP-CLI has no user, so the administrator-only counters are asked
		// for explicitly.
		$report = WSS_Index_Backfill::report();

		\WP_CLI\Utils\format_items(
			'table',
			[
				[ 'field' => 'state', 'value' => $report['state'] ],
				[ 'field' => 'table', 'value' => $report['table'] ? 'ok' : 'MISSING' ],
				[ 'field' => 'links', 'value' => $report['links_total'] ],
				[ 'field' => 'posts_processed', 'value' => $report['posts_processed'] . ' / ' . $report['posts_total'] . ' (' . $report['percent'] . '%)' ],
				[ 'field' => 'posts_with_links', 'value' => $report['posts_with_links'] ],
				[ 'field' => 'posts_without_links', 'value' => $report['posts_without_links'] ],
				[ 'field' => 'index_version', 'value' => $report['index_version'] !== '' ? $report['index_version'] : '-' ],
				[ 'field' => 'backfill_done', 'value' => $report['done'] ? 'yes' : 'no' ],
				[ 'field' => 'cursor', 'value' => $report['cursor'] ],
				[ 'field' => 'processed', 'value' => $report['processed'] ],
				[ 'field' => 'errors', 'value' => $report['errors'] ],
				[ 'field' => 'cron_next', 'value' => $report['cron']['next'] > 0 ? $report['cron']['next'] : '-' ],
				[ 'field' => 'cron_last', 'value' => $report['cron']['last'] > 0 ? $report['cron']['last'] : '-' ],
				[ 'field' => 'cron_disabled', 'value' => $report['cron']['disabled'] ? 'yes (DISABLE_WP_CRON)' : 'no' ],
			],
			[ 'field', 'value' ]
		);

		if ( 'ready' !== $report['state'] ) {
			\WP_CLI::warning(
				sprintf(
					'Index state is "%s": a link search answers "nothing found" for every query until a rebuild completes. Run: wp wss index rebuild',
					$report['state']
				)
			);
		}

		if ( ! $report['done'] ) {
			\WP_CLI::warning( 'A background rebuild is in progress; this command restarts it synchronously from the beginning (indexing is idempotent).' );
		}
	}

	/**
	 * Rebuilds the links index for every indexed post status.
	 *
	 * This is the reliable path on a large site: no browser, no WP-Cron and no
	 * per-request time limit. Use --continue to pick up where the browser or a
	 * previous run stopped.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<int>]
	 * : Posts per batch. Default 200.
	 *
	 * [--continue]
	 * : Continue from the stored cursor instead of starting from zero.
	 *
	 * [--fresh]
	 * : Start from zero (the default). Cannot be combined with --continue.
	 *
	 * [--dry-run]
	 * : Only report how much work there is.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss index rebuild
	 *     wp wss index rebuild --continue
	 *     wp wss index rebuild --fresh --batch-size=500
	 *     wp wss index rebuild --dry-run
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function rebuild( $args = [], $assoc_args = [] ): void {
		$batch    = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 200 );
		$dry_run  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$continue = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'continue', false );
		$fresh    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'fresh', false );

		if ( $continue && $fresh ) {
			\WP_CLI::error( 'Use either --continue or --fresh, not both.' );
		}

		$report = WSS_Index_Backfill::report();

		if ( $dry_run ) {
			\WP_CLI::log(
				sprintf(
					'posts: %d, processed: %d (%d%%), links in index: %d, state: %s, cursor: %d, batch: %d.',
					$report['posts_total'],
					$report['posts_processed'],
					$report['percent'],
					$report['links_total'],
					$report['state'],
					$report['cursor'],
					max( 1, $batch )
				)
			);
			return;
		}

		if ( $report['posts_total'] === 0 ) {
			\WP_CLI::success( 'No posts to index.' );
			return;
		}

		$ticker = function ( $processed, $errors, $cursor ) use ( $report ) {
			$percent = $report['posts_total'] > 0
				? min( 100, (int) round( $processed * 100 / $report['posts_total'] ) )
				: 100;

			\WP_CLI::log( sprintf( 'processed: %d (%d%%), errors: %d, cursor: %d', $processed, $percent, $errors, $cursor ) );
		};

		$started = microtime( true );
		$result  = $this->backfill->rebuild_sync( $batch, $ticker, $continue );
		$report  = WSS_Index_Backfill::report();

		\WP_CLI::log(
			sprintf(
				'Indexed %d posts in %.1fs, errors: %d, missing: %d, links: %d, state: %s.',
				$result['processed'],
				microtime( true ) - $started,
				$result['errors'],
				$result['missing'],
				$report['links_total'],
				$report['state']
			)
		);

		// Scripts must be able to see a failed run: a non-zero exit code is
		// the only signal they reliably check.
		if ( $result['errors'] > 0 ) {
			\WP_CLI::error(
				sprintf(
					'Rebuild finished with %d error(s); see the log above. State: %s.',
					$result['errors'],
					$report['state']
				),
				false
			);
			\WP_CLI::halt( 1 );
		}

		\WP_CLI::success( sprintf( 'Links index rebuilt, state: %s.', $report['state'] ) );
	}

	/**
	 * Prints the schema state of the links index.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss index schema
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function schema( $args = [], $assoc_args = [] ): void {
		$info = WSS_Links_Indexer::schema_info();

		\WP_CLI\Utils\format_items(
			'table',
			[
				[ 'field' => 'schema_state', 'value' => $info['state'] ],
				[ 'field' => 'table_exists', 'value' => $info['exists'] ? 'yes' : 'no' ],
				[ 'field' => 'table_rows', 'value' => $info['rows'] ],
				[ 'field' => 'stored_version', 'value' => $info['stored_version'] !== '' ? $info['stored_version'] : '—' ],
				[ 'field' => 'expected_version', 'value' => $info['expected_version'] ],
				[ 'field' => 'columns', 'value' => implode( ', ', $info['columns'] ) ],
				[ 'field' => 'missing_columns', 'value' => ! empty( $info['missing_columns'] ) ? implode( ', ', $info['missing_columns'] ) : '—' ],
			],
			[ 'field', 'value' ]
		);

		if ( 'ok' !== $info['state'] ) {
			\WP_CLI::warning( 'Schema is not ok: use "wp wss index repair" (keeps rows) or "wp wss index recreate" (drops them).' );
		}
	}

	/**
	 * Drops the links index and creates it again with the current schema.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss index recreate --yes
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function recreate( $args = [], $assoc_args = [] ): void {
		\WP_CLI::confirm( 'Drop the links index and create it again? Every indexed row is lost.', $assoc_args );

		$result = WSS_Links_Indexer::recreate();
		WSS_Index_Backfill::reset_after_recreate();

		$report = WSS_Index_Backfill::report();

		if ( ! $result['ok'] ) {
			\WP_CLI::error( sprintf( 'Recreate failed, schema state: %s.', $result['state'] ) );
		}

		\WP_CLI::success(
			sprintf(
				'Index recreated: schema %s -> %s, rows dropped: %d, state: %s. Run "wp wss index rebuild" next.',
				$result['old_version'] !== '' ? $result['old_version'] : '(none)',
				$result['new_version'],
				$result['rows_dropped'],
				$report['state']
			)
		);
	}

	/**
	 * Repairs the table schema with dbDelta, keeping the rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss index repair
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function repair( $args = [], $assoc_args = [] ): void {
		$result = WSS_Links_Indexer::repair();
		WSS_Index_Backfill::flush_status();

		\WP_CLI::log( sprintf( 'Schema: %s -> %s.', $result['before'], $result['after'] ) );

		if ( ! $result['ok'] ) {
			\WP_CLI::error(
				sprintf(
					'dbDelta could not repair the schema: %s. Use "wp wss index recreate" (drops rows).',
					! empty( $result['missing_after'] ) ? 'missing columns: ' . implode( ', ', $result['missing_after'] ) : 'state ' . $result['after']
				)
			);
		}

		\WP_CLI::success( 'Index schema repaired without data loss.' );
	}

	/**
	 * Empties the links index.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss index clear
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function clear( $args = [], $assoc_args = [] ): void {
		\WP_CLI::confirm( 'Drop every row of the links index?', $assoc_args );

		$this->backfill->clear();

		\WP_CLI::success( 'Links index cleared.' );
	}
}

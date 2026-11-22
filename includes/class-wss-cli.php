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
		$status = WSS_Index_Backfill::status();

		\WP_CLI\Utils\format_items(
			'table',
			[
				[ 'field' => 'table', 'value' => $status['table'] ? 'ok' : 'MISSING' ],
				[ 'field' => 'links', 'value' => $status['links'] ],
				[ 'field' => 'posts', 'value' => $status['posts'] ],
				[ 'field' => 'backfill_done', 'value' => $status['done'] ? 'yes' : 'no' ],
				[ 'field' => 'cursor', 'value' => $status['cursor'] ],
				[ 'field' => 'processed', 'value' => $status['processed'] ],
				[ 'field' => 'errors', 'value' => $status['errors'] ],
			],
			[ 'field', 'value' ]
		);

		if ( ! $status['done'] ) {
			\WP_CLI::warning( 'A background rebuild is in progress; this command restarts it synchronously from the beginning (indexing is idempotent).' );
		}
	}

	/**
	 * Rebuilds the links index for every indexed post status.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<int>]
	 * : Posts per batch. Default 200.
	 *
	 * [--dry-run]
	 * : Only report how much work there is.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss index rebuild
	 *     wp wss index rebuild --batch-size=500
	 *     wp wss index rebuild --dry-run
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function rebuild( $args = [], $assoc_args = [] ): void {
		$batch   = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 200 );
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$posts = WSS_Links_Indexer::count_posts();
		$links = WSS_Links_Indexer::count_rows();

		if ( $dry_run ) {
			\WP_CLI::log( sprintf( 'posts: %d, links in index: %d, batch: %d', $posts, $links, $batch ) );
			return;
		}

		if ( $posts === 0 ) {
			\WP_CLI::success( 'No posts to index.' );
			return;
		}

		$ticker = function ( $processed, $errors, $cursor ) {
			\WP_CLI::log( sprintf( 'processed: %d, errors: %d, cursor: %d', $processed, $errors, $cursor ) );
		};

		$started = microtime( true );
		$result  = $this->backfill->rebuild_sync( $batch, $ticker );

		\WP_CLI::success(
			sprintf(
				'Indexed %d posts in %.1fs, errors: %d, links: %d.',
				$result['processed'],
				microtime( true ) - $started,
				$result['errors'],
				WSS_Links_Indexer::count_rows()
			)
		);
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

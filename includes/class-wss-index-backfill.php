<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background rebuild of the links index.
 *
 * The index is derived data: it is filled by the save_post hook, so any
 * post that was never saved while the plugin was active is missing from
 * it. Dropping or re-creating the table (uninstall, deploy by deleting the
 * plugin folder, database restore) leaves an empty index that no amount of
 * searching will fill.
 *
 * The rebuild therefore works like a cursor-based job:
 *
 *  - posts are processed in small batches, ordered by ID, with the cursor
 *    stored in one autoloaded option;
 *  - each run has a time budget and takes a lock, so overlapping cron runs
 *    or a slow request cannot process the same post twice;
 *  - the next run is scheduled by a single event until the cursor reaches
 *    the end, then the job marks itself done;
 *  - an empty index with existing posts is detected once and queued
 *    automatically, so the site repairs itself after a data loss.
 *
 * WP-CLI (wp wss index rebuild) and the admin button drive the same state,
 * so the job can be finished from any of the three entry points.
 */
final class WSS_Index_Backfill {

	public const HOOK = 'wss_index_backfill';

	private const OPTION            = 'wss_index_backfill_state';
	private const LOCK              = 'wss_index_backfill_lock';
	private const LOCK_TTL          = 300;
	private const DEFAULT_BATCH     = 50;
	private const TIME_BUDGET       = 20.0;
	private const MAX_ERRORS        = 100;
	private const SCHEDULE_DELAY    = 10;

	private WSS_Links_Indexer $indexer;

	public function __construct( ?WSS_Links_Indexer $indexer = null ) {
		$this->indexer = $indexer ?? new WSS_Links_Indexer();
	}

	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'maybe_activate' ], 20 );
	}

	/* ============================================================
	 *  State
	 * ============================================================ */

	/**
	 * @return array{done:bool,cursor:int,processed:int,errors:int,started:int,finished:int}
	 */
	public static function state(): array {
		$defaults = [
			'done'      => true,
			'cursor'    => 0,
			'processed' => 0,
			'errors'    => 0,
			'started'   => 0,
			'finished'  => 0,
		];

		$state = get_option( self::OPTION, [] );

		return is_array( $state ) ? array_merge( $defaults, $state ) : $defaults;
	}

	private static function save_state( array $state ): void {
		update_option( self::OPTION, $state, true );
	}

	/**
	 * Queues a full rebuild. Safe to call repeatedly: an already running
	 * job is not restarted from zero.
	 */
	public static function start(): void {
		$option = get_option( self::OPTION, [] );
		$state  = is_array( $option ) ? $option : [];

		// A job that is still running keeps its cursor; only a finished or
		// absent one is reset.
		if ( ! empty( $state['done'] ) || ! isset( $state['cursor'] ) || empty( $state['started'] ) ) {
			$state = [
				'done'      => false,
				'cursor'    => 0,
				'processed' => 0,
				'errors'    => 0,
				'started'   => time(),
				'finished'  => 0,
			];
			update_option( self::OPTION, $state, true );
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::SCHEDULE_DELAY, self::HOOK );
		}
	}

	/**
	 * One-time inspection after install or upgrade: an empty index with
	 * existing posts means the data was lost, so queue a rebuild. Runs only
	 * when the state option does not exist yet, which is once per site.
	 */
	public function maybe_activate(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$state = get_option( self::OPTION, null );

		if ( is_array( $state ) ) {
			if ( empty( $state['done'] ) ) {
				wp_schedule_single_event( time() + self::SCHEDULE_DELAY, self::HOOK );
			}
			return;
		}

		if ( $this->indexer->count_rows() > 0 || $this->indexer->count_posts() === 0 ) {
			self::save_state( array_merge( $this->state(), [ 'done' => true, 'finished' => time() ] ) );
			return;
		}

		self::start();
	}

	/* ============================================================
	 *  Worker
	 * ============================================================ */

	/**
	 * Processes one batch. Scheduled with wp_schedule_single_event() and
	 * safe to call manually.
	 *
	 * @param bool $reschedule Queue the next run when this one is not the
	 *                        last (CLI drives the loop itself).
	 */
	public function run( bool $reschedule = true ): void {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, time(), self::LOCK_TTL );

		$state = self::state();

		if ( ! empty( $state['done'] ) ) {
			delete_transient( self::LOCK );
			return;
		}

		$batch     = max( 1, (int) apply_filters( 'wss_index_backfill_batch', self::DEFAULT_BATCH ) );
		$deadline  = microtime( true ) + self::TIME_BUDGET;
		$processed = 0;

		$ids = $this->post_ids_after( (int) $state['cursor'], $batch );

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post ) {
				if ( ! $this->indexer->reindex( $post ) ) {
					$state['errors']++;
				}
				$state['processed']++;
				$processed++;
			}

			$state['cursor'] = (int) $post_id;

			if ( microtime( true ) > $deadline || $state['errors'] > self::MAX_ERRORS ) {
				break;
			}
		}

		$fetched = count( $ids );

		if ( $fetched < $batch || $state['errors'] > self::MAX_ERRORS ) {
			$state['done']     = true;
			$state['finished'] = time();
		}

		self::save_state( $state );

		delete_transient( self::LOCK );

		if ( $reschedule && empty( $state['done'] ) ) {
			wp_schedule_single_event( time() + self::SCHEDULE_DELAY, self::HOOK );
		}
	}

	/**
	 * Synchronous full rebuild for WP-CLI: resets the cursor and processes
	 * batches until the posts run out.
	 *
	 * @param int           $batch Posts per batch.
	 * @param callable|null $tick  Receives (processed, errors, cursor).
	 * @return array{processed:int,errors:int}
	 */
	public function rebuild_sync( int $batch = 200, ?callable $tick = null ): array {
		$batch = max( 1, $batch );

		self::save_state( [
			'done'      => false,
			'cursor'    => 0,
			'processed' => 0,
			'errors'    => 0,
			'started'   => time(),
			'finished'  => 0,
		] );

		$cursor    = 0;
		$processed = 0;
		$errors    = 0;

		do {
			$ids = $this->post_ids_after( $cursor, $batch );

			foreach ( $ids as $post_id ) {
				$post = get_post( $post_id );
				if ( $post instanceof WP_Post ) {
					if ( ! $this->indexer->reindex( $post ) ) {
						$errors++;
					}
					$processed++;
				}
				$cursor = (int) $post_id;
			}

			if ( $tick !== null ) {
				$tick( $processed, $errors, $cursor );
			}
		} while ( count( $ids ) === $batch );

		self::save_state( [
			'done'      => true,
			'cursor'    => $cursor,
			'processed' => $processed,
			'errors'    => $errors,
			'started'   => time(),
			'finished'  => time(),
		] );

		return [ 'processed' => $processed, 'errors' => $errors ];
	}

	/**
	 * Next post IDs after the cursor, in insertion order.
	 *
	 * @return int[]
	 */
	private function post_ids_after( int $cursor, int $limit ): array {
		global $wpdb;

		$statuses = WSS_Config::ALLOWED_STATUSES;
		$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'post'
			  AND post_status IN ({$ph})
			  AND ID > %d
			ORDER BY ID ASC
			LIMIT %d";

		$params = array_merge( array_values( $statuses ), [ $cursor, $limit ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/* ============================================================
	 *  Diagnostics
	 * ============================================================ */

	/**
	 * Compact status for /wss/v1/me, the admin screen and WP-CLI.
	 *
	 * @return array{table:bool,links:int,posts:int,done:bool,cursor:int,processed:int,errors:int}
	 */
	public static function status(): array {
		$state = self::state();

		return [
			'table'     => WSS_Links_Indexer::table_exists(),
			'links'     => WSS_Links_Indexer::count_rows(),
			'posts'     => WSS_Links_Indexer::count_posts(),
			'done'      => (bool) $state['done'],
			'cursor'    => (int) $state['cursor'],
			'processed' => (int) $state['processed'],
			'errors'    => (int) $state['errors'],
		];
	}

	/**
	 * Empties the index so the next rebuild starts from scratch. Used by
	 * the CLI and by the admin button; the cursor is reset as well.
	 */
	public function clear(): void {
		global $wpdb;

		$table = WSS_Links_Indexer::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore -- a table name cannot be a prepare() placeholder.

		// Some managed hosts deny TRUNCATE (no DROP privilege); fall back to
		// a plain delete so the command still works there.
		if ( $wpdb->last_error !== '' ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore -- a table name cannot be a prepare() placeholder.
		}

		self::save_state( [
			'done'      => true,
			'cursor'    => 0,
			'processed' => 0,
			'errors'    => 0,
			'started'   => 0,
			'finished'  => time(),
		] );

		delete_transient( self::LOCK );
	}
}

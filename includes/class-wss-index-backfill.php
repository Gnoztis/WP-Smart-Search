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
	/**
	 * Seconds a lock is considered alive. A batch never runs longer than
	 * TIME_BUDGET, so a lock older than this belongs to a dead worker.
	 */
	private const LOCK_TTL          = 60;
	private const DEFAULT_BATCH     = 50;
	private const TIME_BUDGET       = 20.0;
	private const MAX_ERRORS        = 100;
	private const SCHEDULE_DELAY    = 10;

	/** Cached snapshot of the counters (diagnostics, not the job state). */
	private const STATUS_TRANSIENT = 'wss_index_status';
	private const STATUS_TTL       = 600;

	/** Share of indexed posts below which the index reports "partial". */
	private const COVERAGE_MIN     = 0.9;

	/** Option holding the browser-runner marker. */
	public const RUNNER_OPTION = 'wss_index_runner';

	/** How long the browser marker stays fresh, seconds. */
	private const RUNNER_TTL = 300;

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
			'done'          => true,
			'cursor'        => 0,
			'processed'     => 0,
			'errors'        => 0,
			'started'       => 0,
			'finished'      => 0,
			'index_version' => '',
		];

		$state = get_option( self::OPTION, [] );

		return is_array( $state ) ? array_merge( $defaults, $state ) : $defaults;
	}

	/**
	 * Stores the job state.
	 *
	 * @param array $state Job state.
	 * @param bool  $flush Drop the cached diagnostics snapshot. Intermediate
	 *                     batches skip it: the counters are stale for a few
	 *                     seconds anyway, and the browser-driven rebuild
	 *                     reads them on every step.
	 */
	private static function save_state( array $state, bool $flush = true ): void {
		update_option( self::OPTION, $state, true );

		if ( $flush ) {
			self::flush_status();
		}
	}

	/**
	 * Queues a full rebuild. Safe to call repeatedly: an already running
	 * job is not restarted from zero.
	 *
	 * @param bool $schedule Queue a WP-Cron run. False for a rebuild driven
	 *                       step by step from the browser: the job must not
	 *                       also run in the background.
	 */
	public static function start( bool $schedule = true ): void {
		$option = get_option( self::OPTION, [] );
		$state  = is_array( $option ) ? $option : [];

		// A job that is still running keeps its cursor; only a finished or
		// absent one is reset.
		if ( ! empty( $state['done'] ) || ! isset( $state['cursor'] ) || empty( $state['started'] ) ) {
			self::save_state( [
				'done'          => false,
				'cursor'        => 0,
				'processed'     => 0,
				'errors'        => 0,
				'started'       => time(),
				'finished'      => 0,
				'index_version' => '',
			] );
		}

		if ( $schedule && ! self::browser_active() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::SCHEDULE_DELAY, self::HOOK );
		}
	}

	/**
	 * Restarts the job from zero.
	 *
	 * Used by the "start over" button: the cursor and the counters are
	 * dropped, everything else is left to the runner.
	 */
	public static function reset(): void {
		self::save_state( [
			'done'          => false,
			'cursor'        => 0,
			'processed'     => 0,
			'errors'        => 0,
			'started'       => time(),
			'finished'      => 0,
			'index_version' => '',
		] );
	}

	/* ============================================================
	 *  Browser runner marker
	 * ============================================================ */

	/**
	 * Marks the browser as the driver of the rebuild.
	 *
	 * While the marker is fresh the cron worker skips both scheduling and
	 * its own cycle: two workers on one cursor duplicate work and fight over
	 * the lock. The browser refreshes the marker on every step, so it
	 * expires only when the tab is gone (then cron takes over again).
	 */
	public static function browser_begin(): void {
		update_option(
			self::RUNNER_OPTION,
			[
				'value'   => 'browser',
				'expires' => time() + self::RUNNER_TTL,
			],
			false
		);
	}

	/**
	 * Drops the marker: the browser stopped, cron may run again.
	 */
	public static function browser_release(): void {
		delete_option( self::RUNNER_OPTION );
	}

	/**
	 * Whether a browser is driving the rebuild right now.
	 */
	public static function browser_active(): bool {
		$marker = get_option( self::RUNNER_OPTION, [] );

		if ( ! is_array( $marker ) || empty( $marker['expires'] ) ) {
			return false;
		}

		if ( (int) $marker['expires'] < time() ) {
			delete_option( self::RUNNER_OPTION );
			return false;
		}

		return 'browser' === (string) ( $marker['value'] ?? '' );
	}

	/**
	 * One-time inspection after install or upgrade: an empty index with
	 * existing posts means the data was lost, so queue a rebuild. Runs only
	 * when the state option does not exist yet, which is once per site.
	 */
	public function maybe_activate(): void {
		if ( self::browser_active() ) {
			return;
		}
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
	 * Whether another worker is running right now.
	 *
	 * The lock value is the timestamp of the run that took it. A lock older
	 * than LOCK_TTL belongs to a worker that died (fatal error, killed
	 * request, deploy) — it is removed so a crash cannot block the rebuild
	 * for minutes. A fresh lock always means a live worker: one batch runs
	 * at most TIME_BUDGET seconds, well under LOCK_TTL.
	 */
	public function is_busy(): bool {
		$lock = get_transient( self::LOCK );

		if ( ! $lock ) {
			return false;
		}

		$taken = (int) $lock;
		if ( $taken > 0 && ( time() - $taken ) > self::LOCK_TTL ) {
			delete_transient( self::LOCK );
			return false;
		}

		return true;
	}

	/**
	 * Processes one batch. Scheduled with wp_schedule_single_event() and safe
	 * to call manually.
	 *
	 * @param bool $reschedule Queue the next run when this one is not the
	 *                        last (CLI drives the loop itself).
	 * @return string 'browser' while the browser drives the run, 'busy' when
	 *                another worker holds the lock, 'done' when the job is
	 *                finished after this call, 'ran' otherwise.
	 */
	public function run( bool $reschedule = true ): string {
		// The browser is the only driver while its marker is fresh; cron
		// must not touch the cursor or compete for the lock.
		if ( self::browser_active() ) {
			return 'browser';
		}

		if ( $this->is_busy() ) {
			return 'busy';
		}
		set_transient( self::LOCK, time(), self::LOCK_TTL );

		$state = self::state();

		if ( ! empty( $state['done'] ) ) {
			delete_transient( self::LOCK );
			return 'done';
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
			$state['done']          = true;
			$state['finished']      = time();
			$state['index_version'] = WSS_Config::LINKS_INDEX_VERSION;
		}

		// Flush the diagnostics snapshot only when the job is over: an
		// intermediate batch must not turn every browser step into a
		// COUNT(*) of the whole index.
		self::save_state( $state, ! empty( $state['done'] ) );

		delete_transient( self::LOCK );

		if ( $reschedule && empty( $state['done'] ) ) {
			wp_schedule_single_event( time() + self::SCHEDULE_DELAY, self::HOOK );
		}

		return empty( $state['done'] ) ? 'ran' : 'done';
	}

	/* ============================================================
	 *  Browser runner
	 * ============================================================ */

	/**
	 * One browser-driven batch.
	 *
	 * Port of the model used by wp-smart-search-frontend: the browser is the
	 * only driver while its marker is fresh, so there is no lock and no
	 * `busy` state to fight over. Two tabs cannot duplicate work because the
	 * cursor is advanced with a compare-and-set — the tab whose old cursor
	 * still matches wins, the other gets `skipped` and no work.
	 *
	 * @return array{processed:int,cursor:int,done:bool,total:int,indexed:int,skipped:bool,errors:int}
	 */
	public function run_browser_step(): array {
		$limit = max( 1, (int) apply_filters( 'wss_index_backfill_batch', self::DEFAULT_BATCH ) );

		$state      = self::state();
		$old_cursor = (int) $state['cursor'];
		$total      = (int) ( self::snapshot()['posts'] ?? 0 );

		$ids = $this->post_ids_after( $old_cursor, $limit );

		$processed  = 0;
		$indexed    = 0;
		$errors     = 0;
		$new_cursor = $old_cursor;

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post ) {
				if ( $this->indexer->reindex( $post ) ) {
					$indexed++;
				} else {
					$errors++;
				}
				$processed++;
			}

			$new_cursor = (int) $post_id;
		}

		$finished = count( $ids ) < $limit;

		$next = [
			'done'          => (bool) ( $state['done'] ?? true ),
			'cursor'        => $new_cursor,
			'processed'     => (int) $state['processed'] + $processed,
			'errors'        => (int) $state['errors'] + $errors,
			'started'       => (int) ( $state['started'] ?? 0 ) ? (int) $state['started'] : time(),
			'finished'      => (int) ( $state['finished'] ?? 0 ),
			'index_version' => (string) ( $state['index_version'] ?? '' ),
		];

		if ( $finished ) {
			$next['done']          = true;
			$next['finished']      = time();
			$next['index_version'] = WSS_Config::LINKS_INDEX_VERSION;
		}

		$won = $this->advance_cursor( $old_cursor, $next );

		if ( ! $won ) {
			// Another tab advanced the cursor between our read and write.
			$fresh = self::state();

			return [
				'processed' => 0,
				'cursor'    => (int) $fresh['cursor'],
				'done'      => (bool) $fresh['done'],
				'total'     => $total,
				'indexed'   => 0,
				'skipped'   => true,
				'errors'    => (int) $fresh['errors'],
			];
		}

		if ( $finished ) {
			self::flush_status();
		}

		return [
			'processed' => $processed,
			'cursor'    => $new_cursor,
			'done'      => (bool) $next['done'],
			'total'     => $total,
			'indexed'   => $indexed,
			'skipped'   => false,
			'errors'    => (int) $next['errors'],
		];
	}

	/**
	 * Writes the new job state only if the stored cursor is still the old
	 * one.
	 *
	 * A plain update_option() would let two tabs overwrite each other's
	 * progress; the UPDATE ... WHERE option_value = <old> makes the advance
	 * atomic without a lock.
	 *
	 * @param int   $old_cursor Cursor this step read.
	 * @param array $state      State to store.
	 */
	private function advance_cursor( int $old_cursor, array $state ): bool {
		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::OPTION
			)
		);

		if ( null === $raw ) {
			return false;
		}

		$current = maybe_unserialize( $raw );
		if ( ! is_array( $current ) || (int) ( $current['cursor'] ?? 0 ) !== $old_cursor ) {
			return false;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $state ),
				self::OPTION,
				$raw
			)
		);

		wp_cache_delete( self::OPTION, 'options' );

		return 1 === (int) $result;
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
			'done'          => false,
			'cursor'        => 0,
			'processed'     => 0,
			'errors'        => 0,
			'started'       => time(),
			'finished'      => 0,
			'index_version' => '',
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
			'done'          => true,
			'cursor'        => $cursor,
			'processed'     => $processed,
			'errors'        => $errors,
			'started'       => time(),
			'finished'      => time(),
			'index_version' => WSS_Config::LINKS_INDEX_VERSION,
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
	 * Index health: counters, state and whether a rebuild is needed.
	 *
	 * state:
	 *  - empty   — the site has posts, the index has none: a link search
	 *              answers "nothing found" for every query;
	 *  - partial — a rebuild never finished, or less than COVERAGE_MIN of the
	 *              posts are indexed;
	 *  - stale   — the index was built with an older row format (for example
	 *              before anchor_kind existed);
	 *  - ready   — nothing to do.
	 *
	 * /me and GET /wss/v1/index both use this method, so they cannot
	 * disagree.
	 *
	 * @return array{state:string,posts_total:int,posts_indexed:int,links_total:int,generation:int,index_version:string,needs_rebuild:bool,table:bool,done:bool,cursor:int,processed:int,errors:int,last_run:int}
	 */
	public static function report(): array {
		$state = self::state();
		$snap  = self::snapshot();

		$posts_total   = (int) $snap['posts'];
		$posts_indexed = (int) $snap['posts_indexed'];
		$links_total   = (int) $snap['links'];

		$generation    = (int) get_option( WSS_Config::CACHE_GEN_OPTION, 0 );
		$index_version = (string) ( $state['index_version'] ?? '' );

		$coverage = $posts_total > 0 ? ( $posts_indexed / $posts_total ) : 1.0;

		if ( $posts_total > 0 && $posts_indexed === 0 ) {
			$health = 'empty';
		} elseif ( empty( $state['done'] ) || $coverage < self::COVERAGE_MIN ) {
			$health = 'partial';
		} elseif ( $index_version !== WSS_Config::LINKS_INDEX_VERSION ) {
			$health = 'stale';
		} else {
			$health = 'ready';
		}

		$last_run = (int) ( $state['finished'] ?? 0 );
		if ( $last_run <= 0 ) {
			$last_run = (int) ( $state['started'] ?? 0 );
		}

		return [
			'state'         => $health,
			'posts_total'   => $posts_total,
			'posts_indexed' => $posts_indexed,
			'links_total'   => $links_total,
			'generation'    => $generation,
			'index_version' => $index_version,
			'needs_rebuild' => 'ready' !== $health,
			'table'         => (bool) $snap['table'],
			'done'          => (bool) $state['done'],
			'cursor'        => (int) $state['cursor'],
			'processed'     => (int) $state['processed'],
			'errors'        => (int) $state['errors'],
			'last_run'      => $last_run,
		];
	}

	/**
	 * Compact status for the admin screen and WP-CLI.
	 *
	 * The rebuild state (done/cursor/processed) is one option read and is
	 * always fresh. The expensive part — table existence and the three
	 * COUNT(*) queries — is cached in a transient for STATUS_TTL and
	 * dropped by save_state() (start/finish/clear) and by the cache
	 * generation bump on a post save.
	 *
	 * Absolute counters describe the whole site, so they are reported only
	 * to administrators: the search screen of an author must not become a
	 * way to estimate the size of someone else's content. Pass
	 * $with_counts = true from WP-CLI, which has no user to check.
	 *
	 * @param bool|null $with_counts True/false to force, null to decide by capability.
	 * @return array{table:bool,done:bool,cursor:int,processed:int,errors:int,links?:int,posts?:int,posts_indexed?:int}
	 */
	public static function status( ?bool $with_counts = null ): array {
		$state = self::state();

		$with_counts = $with_counts ?? current_user_can( 'manage_options' );
		$snapshot    = self::snapshot();

		$out = [
			'table'     => (bool) $snapshot['table'],
			'done'      => (bool) $state['done'],
			'cursor'    => (int) $state['cursor'],
			'processed' => (int) $state['processed'],
			'errors'    => (int) $state['errors'],
		];

		if ( $with_counts ) {
			$out['links']         = (int) $snapshot['links'];
			$out['posts']         = (int) $snapshot['posts'];
			$out['posts_indexed'] = (int) $snapshot['posts_indexed'];
		}

		return $out;
	}

	/**
	 * Table existence and index counters, cached for STATUS_TTL.
	 *
	 * The counters are kept even when the caller may not see them: one
	 * shared snapshot serves every caller, and computing them only for
	 * administrators would need a second cache key.
	 *
	 * @return array{table:bool,links:int,posts:int}
	 */
	private static function snapshot(): array {
		$cached = get_transient( self::STATUS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['table'], $cached['links'], $cached['posts'], $cached['posts_indexed'] ) ) {
			return $cached;
		}

		$snapshot = [
			'table'         => WSS_Links_Indexer::table_exists(),
			'links'         => WSS_Links_Indexer::count_rows(),
			'posts'         => WSS_Links_Indexer::count_posts(),
			'posts_indexed' => WSS_Links_Indexer::count_indexed_posts(),
		];

		set_transient( self::STATUS_TRANSIENT, $snapshot, self::STATUS_TTL );

		return $snapshot;
	}

	/**
	 * Drops the cached snapshot. Called when the index or the table may
	 * have changed: rebuild start/finish/clear and the cache generation
	 * bump after a post save.
	 */
	public static function flush_status(): void {
		delete_transient( self::STATUS_TRANSIENT );
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
			'done'          => true,
			'cursor'        => 0,
			'processed'     => 0,
			'errors'        => 0,
			'started'       => 0,
			'finished'      => time(),
			'index_version' => '',
		] );

		delete_transient( self::LOCK );
	}
}

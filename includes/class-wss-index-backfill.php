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

	/**
	 * Budget of one browser step, seconds.
	 *
	 * A browser request may sit behind a PHP or gateway time limit (often 30 s
	 * with a proxy in front). A batch that runs into it dies with a 500 and
	 * loses the whole step, so the batch stops itself well before that and
	 * answers with the progress it already has.
	 */
	private const BROWSER_BUDGET = 15.0;

	/**
	 * Batch size ladder used by the browser runner.
	 *
	 * When a step hits the budget the next one is smaller, so a site with
	 * heavy posts keeps making progress instead of timing out on every try.
	 */
	private const BATCH_STEPS = [ 50, 20, 10 ];

	/** Cached snapshot of the counters (diagnostics, not the job state). */
	private const STATUS_TRANSIENT = 'wss_index_status';
	private const STATUS_TTL       = 600;

	/** Option holding the browser-runner marker. */
	public const RUNNER_OPTION = 'wss_index_runner';

	/** Marker saying the one-time autoload repair already ran for a version. */
	private const AUTOLOAD_FIX_OPTION = 'wss_index_autoload_fixed';

	/** How long the browser marker stays fresh, seconds. */
	private const RUNNER_TTL = 300;

	private WSS_Links_Indexer $indexer;

	public function __construct( ?WSS_Links_Indexer $indexer = null ) {
		$this->indexer = $indexer ?? new WSS_Links_Indexer();
	}

	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'maybe_activate' ], 20 );
		add_action( 'init', [ __CLASS__, 'maybe_fix_autoload' ], 5 );
	}

	/* ============================================================
	 *  State
	 * ============================================================ */

	/**
	 * @return array{done:bool,cursor:int,processed:int,errors:int,started:int,finished:int,index_version:string,last_cron:int,batch:int,missing:int}
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
			'last_cron'     => 0,
			'batch'         => 0,
			'missing'       => 0,
			'links_written' => 0,
		];

		$raw = self::option_row();

		if ( null === $raw ) {
			return $defaults;
		}

		$state = maybe_unserialize( $raw );

		return is_array( $state ) ? array_merge( $defaults, $state ) : $defaults;
	}

	/**
	 * Serialized state row, read straight from the table.
	 *
	 * The compare-and-swap writes the row with SQL, so every read that must
	 * agree with it has to bypass the object cache: get_option() may serve a
	 * stale alloptions copy, and that is exactly how the cursor stalled in
	 * 2.6.0 (the swap compared an advanced row against a cached old cursor,
	 * lost, and answered "skipped" on every step).
	 *
	 * @return string|null Serialized value, or null when the row is missing.
	 */
	private static function option_row(): ?string {
		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::OPTION
			)
		);

		return null === $raw ? null : (string) $raw;
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
		// Never autoloaded: the cursor is large, changes on every batch and must
		// not be frozen into the alloptions cache that the compare-and-swap
		// bypasses. update_option() creates a missing row and, with an explicit
		// flag, also downgrades a row left autoloaded by 2.6.0.
		update_option( self::OPTION, $state, false );

		self::forget_option_cache();

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
		$state = self::state();

		// A job that is still running keeps its cursor; only a finished or
		// absent one is reset. state() reads the row, not the cache: a stale
		// copy must not reset a run that is already halfway through.
		if ( ! empty( $state['done'] ) || empty( $state['started'] ) ) {
			self::save_state( [
				'done'          => false,
				'cursor'        => 0,
				'processed'     => 0,
				'errors'        => 0,
				'started'       => time(),
				'finished'      => 0,
				'index_version' => '',
				'links_written' => 0,
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
			'links_written' => 0,
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

		// Existence is a property of the row, not of the option cache: a stale
		// "notoptions"/alloptions entry would make this run on every request.
		$exists = null !== self::option_row();

		if ( $exists ) {
			$state = self::state();
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
	 * The chain is kept alive deliberately: a single broken post or an
	 * exception mid-batch must not leave the job "running" with no event to
	 * continue it, so the next run is scheduled whenever the job is not done.
	 *
	 * @param bool $reschedule Queue the next run when this one is not the
	 *                        last (CLI and REST steps drive the loop themselves).
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

		$state              = self::state();
		$state['last_cron'] = time();

		if ( ! empty( $state['done'] ) ) {
			self::save_state( $state );
			delete_transient( self::LOCK );
			return 'done';
		}

		$batch    = max( 1, (int) apply_filters( 'wss_index_backfill_batch', self::DEFAULT_BATCH ) );
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$fetched  = 0;

		try {
			$ids     = $this->post_ids_after( (int) $state['cursor'], $batch );
			$fetched = count( $ids );

			foreach ( $ids as $post_id ) {
				try {
					$post = get_post( $post_id );

					if ( $post instanceof WP_Post ) {
						$written = $this->indexer->reindex_count( $post );

						if ( $written < 0 ) {
							$state['errors']++;
						} else {
							$state['links_written'] = (int) ( $state['links_written'] ?? 0 ) + $written;
						}
					} else {
						// The id does not resolve to a post any more (deleted
						// between the SELECT and this loop): it was walked, so
						// progress counts it, but there is nothing to index.
						$state['missing']++;
					}
				} catch ( \Throwable $e ) {
					// One broken post must not break the chain.
					$state['errors']++;
				}

				$state['processed']++;
				$state['cursor'] = (int) $post_id;

				if ( microtime( true ) > $deadline || $state['errors'] > self::MAX_ERRORS ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			// A failure before the first post still leaves the job resumable.
			$state['errors']++;
		}

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
			// Only when no event is pending already; a duplicate would run the
			// same batch twice.
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + self::SCHEDULE_DELAY, self::HOOK );
			}
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
	 * The step stops itself at BROWSER_BUDGET and answers with what it has
	 * (`partial`), so a slow batch cannot run into the PHP/gateway limit and
	 * kill the request. Hitting the budget shrinks the batch for the next
	 * step: the size is part of the job state, so it survives the request.
	 *
	 * @return array{processed:int,cursor:int,done:bool,total:int,indexed:int,skipped:bool,errors:int,partial:bool,missing:int,batch:int}
	 */
	public function run_browser_step(): array {
		$state      = self::state();
		$old_cursor = (int) $state['cursor'];
		$total      = (int) ( self::snapshot()['posts'] ?? 0 );

		$limit = self::effective_batch( (int) ( $state['batch'] ?? 0 ) );
		$limit = max( 1, (int) apply_filters( 'wss_index_backfill_batch', $limit ) );

		$deadline = microtime( true ) + self::BROWSER_BUDGET;

		$ids = $this->post_ids_after( $old_cursor, $limit );

		$processed     = 0;
		$indexed       = 0;
		$errors        = 0;
		$missing       = 0;
		$links_written = 0;
		$partial       = false;
		$new_cursor    = $old_cursor;

		foreach ( $ids as $post_id ) {
			if ( microtime( true ) > $deadline ) {
				// Budget spent: keep the cursor where it is and let the client
				// ask for the next (smaller) batch.
				$partial = true;
				break;
			}

			try {
				$post = get_post( $post_id );

				if ( $post instanceof WP_Post ) {
					$written = $this->indexer->reindex_count( $post );

					if ( $written < 0 ) {
						$errors++;
					} else {
						$indexed++;
						$links_written += $written;
					}
				} else {
					// The id no longer resolves to a post (deleted between the
					// SELECT and this loop): walked, nothing to index.
					$missing++;
				}
			} catch ( \Throwable $e ) {
				$errors++;
			}

			$processed++;
			$new_cursor = (int) $post_id;
		}

		$finished = ! $partial && count( $ids ) < $limit;

		$next_batch = $partial ? self::smaller_batch( $limit ) : $limit;

		$next = [
			// A step that did not reach the end means the job is running, even
			// when the previous run had finished. Leaving the stale `true` here
			// made start() treat the next click as "begin a new job" and reset
			// the cursor to zero, so every press walked the first few hundred
			// posts again instead of continuing.
			'done'          => false,
			'cursor'        => $new_cursor,
			'processed'     => (int) $state['processed'] + $processed,
			'errors'        => (int) $state['errors'] + $errors,
			'started'       => (int) ( $state['started'] ?? 0 ) ? (int) $state['started'] : time(),
			'finished'      => (int) ( $state['finished'] ?? 0 ),
			'index_version' => (string) ( $state['index_version'] ?? '' ),
			'last_cron'     => (int) ( $state['last_cron'] ?? 0 ),
			'batch'         => $next_batch,
			'missing'       => (int) $state['missing'] + $missing,
			'links_written' => (int) ( $state['links_written'] ?? 0 ) + $links_written,
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
				'partial'   => false,
				'missing'   => 0,
				'batch'     => self::effective_batch( (int) ( $fresh['batch'] ?? 0 ) ),
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
			'partial'   => $partial,
			'missing'   => $missing,
			'batch'     => $next_batch,
		];
	}

	/**
	 * Batch size for the next step (0 = the default ladder start).
	 */
	private static function effective_batch( int $stored ): int {
		$steps = self::BATCH_STEPS;

		if ( $stored > 0 ) {
			return max( end( $steps ), min( $steps[0], $stored ) );
		}

		return $steps[0];
	}

	/**
	 * Next size down the ladder, never below the smallest step.
	 */
	private static function smaller_batch( int $current ): int {
		$steps = self::BATCH_STEPS;

		foreach ( $steps as $step ) {
			if ( $current > $step ) {
				return $step;
			}
		}

		return end( $steps );
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

		$raw = self::option_row();

		if ( null === $raw ) {
			// No row yet: create it without autoload so it never lands in the
			// alloptions cache. A duplicate name means another worker was faster.
			$inserted = $wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => self::OPTION,
					'option_value' => maybe_serialize( $state ),
					'autoload'     => 'off',
				],
				[ '%s', '%s', '%s' ]
			);

			self::forget_option_cache();

			return 1 === (int) $inserted;
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

		self::forget_option_cache();

		return 1 === (int) $result;
	}

	/**
	 * Drops every cached copy of the state option.
	 *
	 * The compare-and-swap writes the row with raw SQL, so update_option() never
	 * runs and neither the option cache nor the alloptions bucket is invalidated
	 * by WordPress. With a persistent object cache that left a stale cursor in
	 * memory and every following step lost the swap.
	 */
	private static function forget_option_cache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * One-time autoload repair for sites that ran 2.6.0.
	 *
	 * 2.6.0 created the state option autoloaded, so a persistent object cache
	 * served a stale cursor from the alloptions bucket. The save path now writes
	 * the flag as off, but the row may sit untouched until the next batch while
	 * other code reads it through get_option(). The check costs one cached
	 * option read and rewrites nothing after the first run per version.
	 */
	public static function maybe_fix_autoload(): void {
		if ( WSS_VERSION === get_option( self::AUTOLOAD_FIX_OPTION ) ) {
			return;
		}

		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				self::OPTION
			)
		);

		if ( null !== $autoload && ! in_array( (string) $autoload, [ 'off', 'no' ], true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET autoload = 'off' WHERE option_name = %s",
					self::OPTION
				)
			);

			self::forget_option_cache();
		}

		update_option( self::AUTOLOAD_FIX_OPTION, WSS_VERSION, true );
	}

	/**
	 * Synchronous rebuild for WP-CLI: processes batches until the posts run
	 * out.
	 *
	 * The reliable path on a large site: no browser, no WP-Cron, no per-request
	 * time limit. With $resume the run continues from the stored cursor (what
	 * the browser or a previous CLI run left behind); otherwise it starts from
	 * zero.
	 *
	 * @param int           $batch  Posts per batch.
	 * @param callable|null $tick   Receives (processed, errors, cursor) after each batch.
	 * @param bool          $resume Continue from the stored cursor instead of resetting.
	 * @return array{processed:int,errors:int,missing:int,done:bool,cursor:int}
	 */
	public function rebuild_sync( int $batch = 200, ?callable $tick = null, bool $resume = false ): array {
		$batch = max( 1, $batch );

		$state = self::state();

		if ( ! $resume ) {
			$state = [
				'done'          => false,
				'cursor'        => 0,
				'processed'     => 0,
				'errors'        => 0,
				'started'       => time(),
				'finished'      => 0,
				'index_version' => '',
				'batch'         => 0,
				'missing'       => 0,
				'links_written' => 0,
			];
			self::save_state( $state );
		} else {
			$state['done']     = false;
			$state['finished'] = 0;
			self::save_state( $state );
		}

		$cursor        = (int) $state['cursor'];
		$processed     = (int) $state['processed'];
		$errors        = (int) $state['errors'];
		$missing       = (int) ( $state['missing'] ?? 0 );
		$links_written = (int) ( $state['links_written'] ?? 0 );
		$started       = (int) ( $state['started'] ?? 0 ) ? (int) $state['started'] : time();

		do {
			$ids = $this->post_ids_after( $cursor, $batch );

			foreach ( $ids as $post_id ) {
				try {
					$post = get_post( $post_id );
					if ( $post instanceof WP_Post ) {
						$written = $this->indexer->reindex_count( $post );

						if ( $written < 0 ) {
							$errors++;
						} else {
							$links_written += $written;
						}
					} else {
						$missing++;
					}
				} catch ( \Throwable $e ) {
					$errors++;
				}

				$processed++;
				$cursor = (int) $post_id;
			}

			if ( $tick !== null ) {
				$tick( $processed, $errors, $cursor );
			}
		} while ( count( $ids ) === $batch );

		$final = [
			'done'          => true,
			'cursor'        => $cursor,
			'processed'     => $processed,
			'errors'        => $errors,
			'started'       => $started,
			'finished'      => time(),
			'index_version' => WSS_Config::LINKS_INDEX_VERSION,
			'batch'         => 0,
			'missing'       => $missing,
			'links_written' => $links_written,
			'last_cron'     => (int) ( $state['last_cron'] ?? 0 ),
		];

		self::save_state( $final );

		return [
			'processed' => $processed,
			'errors'    => $errors,
			'missing'   => $missing,
			'done'      => true,
			'cursor'    => $cursor,
		];
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
	 * Completion is decided by the job state (done + index_version), never by
	 * a COUNT of the links table: a post without external links is indexed
	 * correctly but has no rows, so counting rows made a finished rebuild look
	 * unfinished forever (2.6.2 and earlier).
	 *
	 * state:
	 *  - empty   — the site has posts, nothing was ever indexed and the index
	 *              has no rows: a link search answers "nothing found";
	 *  - partial — the rebuild has not finished yet;
	 *  - stale   — the index was built with an older row format (for example
	 *              before anchor_kind existed);
	 *  - ready   — the rebuild finished and the format matches.
	 *
	 * /me and GET /wss/v1/index both use this method, so they cannot
	 * disagree.
	 *
	 * While a rebuild runs the counters are read live. A cached snapshot mixed
	 * with the live cursor produced nonsense on 2.6.3 ("0 links, 16681 posts
	 * walked, 16681 posts without links"): the snapshot was up to ten minutes
	 * old while the progress was current. Two extra COUNTs per step during a
	 * run are the price of numbers that agree; the cache is used only for a
	 * finished index.
	 *
	 * @return array{state:string,percent:int,posts_total:int,posts_processed:int,posts_with_links:int,posts_without_links:int,links_total:int,links_written:int,links_per_post:float,generation:int,index_version:string,needs_rebuild:bool,table:bool,done:bool,cursor:int,processed:int,errors:int,missing:int,batch:int,last_run:int,cron:array{next:int,last:int,disabled:bool},browser_marker:bool,schema:array}
	 */
	public static function report(): array {
		$state = self::state();
		$snap  = self::snapshot();
		$done  = ! empty( $state['done'] );

		if ( $done ) {
			$posts_total      = (int) $snap['posts'];
			$posts_with_links = (int) $snap['posts_indexed'];
			$links_total      = (int) $snap['links'];
		} else {
			// Live while the run is in flight: the snapshot may be minutes old.
			$posts_total      = WSS_Links_Indexer::count_posts();
			$posts_with_links = WSS_Links_Indexer::count_indexed_posts();
			$links_total      = WSS_Links_Indexer::count_rows();
		}

		$cursor          = (int) $state['cursor'];
		$posts_processed = $cursor > 0 ? WSS_Links_Indexer::count_posts( $cursor ) : 0;

		// Rows written for posts past the cursor (a post saved during the run)
		// must not push the difference below zero.
		$posts_without_links = max( 0, $posts_processed - $posts_with_links );

		$links_written = (int) ( $state['links_written'] ?? 0 );
		$links_per_post = $posts_processed > 0 ? round( $links_written / $posts_processed, 2 ) : 0.0;

		$generation    = (int) get_option( WSS_Config::CACHE_GEN_OPTION, 0 );
		$index_version = (string) ( $state['index_version'] ?? '' );

		$schema      = $snap['schema'] ?? WSS_Links_Indexer::schema_info();
		$schema_ok   = 'ok' === (string) ( $schema['state'] ?? 'missing' );
		$schema_miss = 'missing' === (string) ( $schema['state'] ?? 'missing' );

		if ( $schema_miss ) {
			// Nothing is stored at all: no rows to contradict the "empty".
			$health = 'empty';
		} elseif ( $posts_total === 0 ) {
			// Nothing to index: no format to compare against either.
			$health = 'ready';
		} elseif ( $links_total === 0 && $posts_processed === 0 ) {
			$health = 'empty';
		} elseif ( ! $done ) {
			$health = 'partial';
		} elseif ( $index_version !== WSS_Config::LINKS_INDEX_VERSION ) {
			$health = 'stale';
		} else {
			$health = 'ready';
		}

		$percent = $posts_total > 0
			? (int) min( 100, (int) round( $posts_processed * 100 / $posts_total ) )
			: 100;

		$last_run = (int) ( $state['finished'] ?? 0 );
		if ( $last_run <= 0 ) {
			$last_run = (int) ( $state['started'] ?? 0 );
		}

		return [
			'state'               => $health,
			'percent'             => $percent,
			'posts_total'         => $posts_total,
			'posts_processed'     => $posts_processed,
			'posts_with_links'    => $posts_with_links,
			'posts_without_links' => $posts_without_links,
			'links_total'         => $links_total,
			'links_written'       => $links_written,
			'links_per_post'      => $links_per_post,
			'generation'          => $generation,
			'index_version'       => $index_version,
			'needs_rebuild'       => 'ready' !== $health || ! $schema_ok,
			'schema'              => $schema,
			'table'               => (bool) $snap['table'],
			'done'                => $done,
			'cursor'              => $cursor,
			'processed'           => (int) $state['processed'],
			'errors'              => (int) $state['errors'],
			'missing'             => (int) ( $state['missing'] ?? 0 ),
			'batch'               => self::effective_batch( (int) ( $state['batch'] ?? 0 ) ),
			'last_run'            => $last_run,
			'cron'                => [
				'next'     => (int) wp_next_scheduled( self::HOOK ),
				'last'     => (int) ( $state['last_cron'] ?? 0 ),
				'disabled' => self::cron_disabled(),
			],
			'browser_marker'      => self::browser_active(),
		];
	}

	/**
	 * Whether WP-Cron is switched off on this site.
	 *
	 * With DISABLE_WP_CRON the background rebuild never runs: the browser
	 * button and `wp wss index rebuild` are the only ways to finish it.
	 */
	public static function cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Resets the job after the index table was recreated.
	 *
	 * The rebuild is not started: the operator sees the new empty index and
	 * decides when to run it. The browser marker and the cached snapshot are
	 * dropped so nothing describes the old table.
	 */
	public static function reset_after_recreate(): void {
		self::save_state( [
			'done'          => false,
			'cursor'        => 0,
			'processed'     => 0,
			'errors'        => 0,
			'started'       => 0,
			'finished'      => 0,
			'index_version' => '',
			'batch'         => 0,
			'missing'       => 0,
			'links_written' => 0,
		] );

		self::browser_release();
		self::flush_status();
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
		if ( is_array( $cached ) && isset( $cached['table'], $cached['links'], $cached['posts'], $cached['posts_indexed'], $cached['schema'] ) ) {
			return $cached;
		}

		$snapshot = [
			'table'         => WSS_Links_Indexer::table_exists(),
			'links'         => WSS_Links_Indexer::count_rows(),
			'posts'         => WSS_Links_Indexer::count_posts(),
			'posts_indexed' => WSS_Links_Indexer::count_indexed_posts(),
			'schema'        => WSS_Links_Indexer::schema_info(),
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
			'links_written' => 0,
		] );

		delete_transient( self::LOCK );
	}
}

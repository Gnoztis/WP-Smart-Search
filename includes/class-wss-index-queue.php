<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background link re-indexing of single posts.
 *
 * save_post / transition_post_status / deleted_post only add a post id to a
 * queue; one scheduled event drains it in small batches. An editor never pays
 * for link parsing inside the save request, and a bulk edit does not reindex
 * dozens of posts one after another.
 *
 * The queue is an option, not a transient: losing it would leave posts without
 * links, and Redis is optional. Two lists keep the intent of every id: index
 * (published or updated post) and remove (unpublished, trashed or deleted).
 */
final class WSS_Index_Queue {

	public const OPTION = 'wss_index_queue';
	public const HOOK   = 'wss_index_queue_worker';
	public const LOCK   = 'wss_index_queue_lock';

	/** Ids kept in the queue; the oldest are dropped and counted. */
	public const CAP = 500;

	/** Posts per run, and the wall-clock budget of one run. */
	public const BATCH  = 20;
	public const BUDGET = 15.0;

	/** Seconds between a save and the first run. */
	public const DELAY = 20;

	private WSS_Links_Indexer $indexer;

	public function __construct( WSS_Links_Indexer $indexer ) {
		$this->indexer = $indexer;
	}

	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init',       [ $this, 'maybe_schedule' ], 20 );
		add_action( 'admin_init', [ $this, 'maybe_schedule' ], 20 );
	}

	/* ============================================================
	 *  Enqueue
	 * ============================================================ */

	/** A published (or updated) post must be re-indexed in the background. */
	public static function enqueue_index( int $post_id ): void {
		self::enqueue( $post_id, false );
	}

	/** An unpublished, trashed or deleted post must lose its rows. */
	public static function enqueue_remove( int $post_id ): void {
		self::enqueue( $post_id, true );
	}

	private static function enqueue( int $post_id, bool $remove ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$queue = self::read();

		// The latest intent wins: a post published again after a trash must be
		// indexed, not removed, and the other way round.
		$queue['index']  = array_values( array_diff( $queue['index'], [ $post_id ] ) );
		$queue['remove'] = array_values( array_diff( $queue['remove'], [ $post_id ] ) );

		$list           = $remove ? 'remove' : 'index';
		$queue[ $list ] = array_values( array_unique( array_merge( $queue[ $list ], [ $post_id ] ) ) );
		$queue          = self::trim( $queue );

		self::write( $queue );
		self::schedule( self::DELAY );
	}

	/**
	 * Drops the oldest ids once the queue is over the cap.
	 */
	private static function trim( array $queue ): array {
		$total = count( $queue['index'] ) + count( $queue['remove'] );

		if ( $total <= self::CAP ) {
			return $queue;
		}

		$drop = $total - self::CAP;

		$queue['dropped'] = (int) ( $queue['dropped'] ?? 0 ) + $drop;

		$from_index = min( $drop, count( $queue['index'] ) );
		if ( $from_index > 0 ) {
			$queue['index'] = array_slice( $queue['index'], $from_index );
			$drop          -= $from_index;
		}

		if ( $drop > 0 ) {
			$queue['remove'] = array_slice( $queue['remove'], $drop );
		}

		return $queue;
	}

	/* ============================================================
	 *  Worker
	 * ============================================================ */

	/**
	 * Drains one batch.
	 *
	 * Returns 'empty' (nothing queued), 'rebuild' (a full rebuild is running
	 * and covers every post), 'busy' (another worker holds the lock) or 'done'.
	 */
	public function run( $reschedule = true ): string {
		$reschedule = (bool) $reschedule;
		$queue      = self::read();

		if ( 0 === count( $queue['index'] ) + count( $queue['remove'] ) ) {
			return 'empty';
		}

		// A rebuild walks every post; the worker must not compete with it.
		if ( ! WSS_Index_Backfill::report()['done'] ) {
			self::write( array_merge( $queue, [ 'skipped_at' => time() ] ) );

			if ( $reschedule ) {
				self::schedule( MINUTE_IN_SECONDS );
			}

			return 'rebuild';
		}

		if ( false !== get_transient( self::LOCK ) ) {
			if ( $reschedule ) {
				self::schedule( 5 );
			}

			return 'busy';
		}

		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$started = microtime( true );
		$batch   = 0;
		$errors  = 0;
		$handled = [ 'index' => [], 'remove' => [] ];

		foreach ( [ 'index', 'remove' ] as $kind ) {
			foreach ( $queue[ $kind ] as $post_id ) {
				if ( $batch >= self::BATCH || ( microtime( true ) - $started ) > self::BUDGET ) {
					break 2;
				}

				$post_id = (int) $post_id;
				$batch++;

				if ( 'index' === $kind ) {
					$post = get_post( $post_id );

					// The post may have been deleted after it was queued.
					$ok = ! $post instanceof WP_Post || $this->indexer->reindex( $post );
				} else {
					$this->indexer->delete_post( $post_id );
					$ok = true;
				}

				if ( ! $ok ) {
					$errors++;
					continue;
				}

				$handled[ $kind ][] = $post_id;
			}
		}

		// Re-read before writing: an enqueue during the run must not be lost.
		$current = self::read();

		foreach ( $handled as $kind => $ids ) {
			$current[ $kind ] = array_values( array_diff( $current[ $kind ], $ids ) );
		}

		$current['last_run'] = time();
		$current['errors']   = (int) $current['errors'] + $errors;

		self::write( $current );
		delete_transient( self::LOCK );

		if ( $reschedule && self::length() > 0 ) {
			self::schedule( 5 );
		}

		return 'done';
	}

	/**
	 * Self-healing: a non-empty queue with no scheduled event schedules one.
	 *
	 * Same idea as the runner marker of WSS-028: a failed cron registration or
	 * a cleared schedule must not leave the queue stuck forever.
	 */
	public function maybe_schedule(): void {
		// The queue option is not autoloaded, so a front-end request must not
		// pay a query for it. Saves schedule the event themselves; this is the
		// self-healing path for admin visits and cron.
		if ( ! is_admin() && ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return;
		}

		if ( self::length() > 0 && ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule( 5 );
		}
	}

	private static function schedule( int $delay ): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK );
	}

	/* ============================================================
	 *  Diagnostics
	 * ============================================================ */

	public static function length(): int {
		$queue = self::read();

		return count( $queue['index'] ) + count( $queue['remove'] );
	}

	/**
	 * @return array{length:int,last_run:int,errors:int,dropped:int,pending:int[],index:int,remove:int}
	 */
	public static function status(): array {
		$queue = self::read();

		return [
			'length'   => count( $queue['index'] ) + count( $queue['remove'] ),
			'last_run' => (int) $queue['last_run'],
			'errors'   => (int) $queue['errors'],
			'dropped'  => (int) $queue['dropped'],
			'pending'  => array_slice( array_merge( $queue['index'], $queue['remove'] ), 0, 20 ),
			'index'    => count( $queue['index'] ),
			'remove'   => count( $queue['remove'] ),
		];
	}

	/* ============================================================
	 *  Storage
	 * ============================================================ */

	/**
	 * @return array{index:int[],remove:int[],last_run:int,errors:int,dropped:int,skipped_at:int}
	 */
	private static function read(): array {
		$raw = get_option( self::OPTION, [] );

		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		return [
			'index'      => array_values( array_filter( array_map( 'intval', (array) ( $raw['index'] ?? [] ) ) ) ),
			'remove'     => array_values( array_filter( array_map( 'intval', (array) ( $raw['remove'] ?? [] ) ) ) ),
			'last_run'   => (int) ( $raw['last_run'] ?? 0 ),
			'errors'     => (int) ( $raw['errors'] ?? 0 ),
			'dropped'    => (int) ( $raw['dropped'] ?? 0 ),
			'skipped_at' => (int) ( $raw['skipped_at'] ?? 0 ),
		];
	}

	private static function write( array $queue ): void {
		// Autoload off: the queue is read by cron and diagnostics, not by
		// every front-end request.
		update_option( self::OPTION, $queue, false );
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Ajax_Handler {

	private WSS_Search_Engine $engine;
	private WSS_Rate_Limiter  $rate_limiter;
	private WSS_Index_Backfill $backfill;

	public function __construct( WSS_Search_Engine $engine, WSS_Rate_Limiter $rate_limiter, WSS_Index_Backfill $backfill ) {
		$this->engine       = $engine;
		$this->rate_limiter = $rate_limiter;
		$this->backfill     = $backfill;
	}

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . WSS_Config::AJAX_ACTION, [ $this, 'handle' ] );
		add_action( 'wp_ajax_' . WSS_Config::AJAX_INDEX_STEP, [ $this, 'handle_index_step' ] );
		add_action( 'wp_ajax_' . WSS_Config::AJAX_INDEX_STOP, [ $this, 'handle_index_stop' ] );
	}

	/**
	 * One step of a browser-driven rebuild of the links index.
	 *
	 * While the browser runs it is the only driver (see
	 * WSS_Index_Backfill::browser_begin()): the step takes the next batch by
	 * cursor, advances it atomically and returns the progress. No lock, no
	 * `busy` state — the model of wp-smart-search-frontend, where sequential
	 * AJAX requests of one browser serialise themselves.
	 *
	 * `start=1` begins a run (resetting a finished job, continuing a running
	 * one), `restart=1` drops the cursor to zero.
	 */
	public function handle_index_step(): void {
		nocache_headers();

		if ( ! wp_doing_ajax() ) {
			wp_send_json_error( [ 'message' => __( 'Некорректный контекст запроса.', 'wp-smart-search' ) ], 400 );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( $method !== 'POST' ) {
			wp_send_json_error( [ 'message' => __( 'Метод не поддерживается.', 'wp-smart-search' ) ], 405 );
		}

		if ( ! check_ajax_referer( WSS_Config::NONCE_INDEX_STEP, WSS_Config::NONCE_FIELD, false ) ) {
			WSS_Config::log_security_event( 'nonce_failed', 'ajax:index_step' );
			wp_die( -1, 403 );
		}

		if ( ! WSS_Config::user_can_rebuild() ) {
			WSS_Config::log_security_event( 'cap_denied', 'ajax:reindex' );
			wp_send_json_error( [ 'code' => 'wss_cap_denied', 'message' => __( 'Недостаточно прав для переиндексации.', 'wp-smart-search' ) ], 403 );
		}

		$start   = isset( $_POST['start'] ) ? absint( wp_unslash( $_POST['start'] ) ) : 0;
		$restart = isset( $_POST['restart'] ) ? absint( wp_unslash( $_POST['restart'] ) ) : 0;
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'rebuild';
		$mode    = 'refresh' === $mode ? 'refresh' : 'rebuild';

		if ( $restart > 0 ) {
			// "Пересобрать заново": the cursor and the format are dropped.
			WSS_Index_Backfill::reset();
		} elseif ( $start > 0 ) {
			if ( 'refresh' === $mode ) {
				// "Доиндексировать": an incremental pass that keeps the index
				// complete. A running job is continued, not restarted.
				WSS_Index_Backfill::refresh( false );
			} else {
				// A finished job is reset, a running one keeps its cursor.
				WSS_Index_Backfill::start( false );
			}
		}

		// Refresh the marker: cron keeps its hands off while the tab is open.
		WSS_Index_Backfill::browser_begin();

		$result = $this->backfill->run_browser_step();
		$report = WSS_Index_Backfill::report();

		$total     = (int) $report['posts_total'];
		$processed = (int) $report['posts_processed'];
		$done      = (bool) $result['done'];
		$message   = '';

		if ( $done ) {
			$message = ( 'refresh' === $report['mode'] && 0 === (int) $report['processed'] )
				? __( 'Индекс актуален, обновлять нечего.', 'wp-smart-search' )
				: __( 'Готово.', 'wp-smart-search' );
		}

		wp_send_json_success( [
			'done'          => $done,
			'mode'          => (string) $report['mode'],
			'refreshing'    => (bool) $report['refreshing'],
			'rebuild_required' => (bool) $report['rebuild_required'],
			'refresh_pending'  => (int) $report['refresh_pending'],
			'processed'     => $processed,
			'batch'         => (int) $result['processed'],
			'batch_size'    => (int) $result['batch'],
			'partial'       => (bool) $result['partial'],
			'cursor'        => (int) $result['cursor'],
			'total'         => $total,
			'percent'       => (int) $report['percent'],
			'indexed'       => (int) $report['posts_with_links'],
			'without_links' => (int) $report['posts_without_links'],
			'links_written' => (int) $report['links_written'],
			'links_per_post' => (string) number_format_i18n( (float) $report['links_per_post'], 2 ),
			'missing'       => (int) $report['missing'],
			'errors'        => (int) $report['errors'],
			'links'         => (int) $report['links_total'],
			'state'         => (string) $report['state'],
			'skipped'       => (bool) $result['skipped'],
			'message'       => $message,
		] );
	}

	/**
	 * Releases the browser marker: WP-Cron is free again, the cursor stays.
	 */
	public function handle_index_stop(): void {
		nocache_headers();

		if ( ! wp_doing_ajax() ) {
			wp_send_json_error( [ 'message' => __( 'Некорректный контекст запроса.', 'wp-smart-search' ) ], 400 );
		}

		if ( ! check_ajax_referer( WSS_Config::NONCE_INDEX_STOP, WSS_Config::NONCE_FIELD, false ) ) {
			WSS_Config::log_security_event( 'nonce_failed', 'ajax:index_stop' );
			wp_die( -1, 403 );
		}

		if ( ! WSS_Config::user_can_rebuild() ) {
			WSS_Config::log_security_event( 'cap_denied', 'ajax:reindex' );
			wp_send_json_error( [ 'code' => 'wss_cap_denied', 'message' => __( 'Недостаточно прав для переиндексации.', 'wp-smart-search' ) ], 403 );
		}

		WSS_Index_Backfill::browser_release();

		$report = WSS_Index_Backfill::report();

		wp_send_json_success( [
			'stopped'   => true,
			'cursor'    => (int) $report['cursor'],
			'processed' => (int) $report['processed'],
			'state'     => (string) $report['state'],
		] );
	}

	public function handle(): void {
		nocache_headers();

		if ( ! wp_doing_ajax() ) {
			wp_send_json_error( [ 'message' => __( 'Некорректный контекст запроса.', 'wp-smart-search' ) ], 400 );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( $method !== 'POST' ) {
			wp_send_json_error( [ 'message' => __( 'Метод не поддерживается.', 'wp-smart-search' ) ], 405 );
		}

		if ( ! check_ajax_referer( WSS_Config::NONCE_ACTION, WSS_Config::NONCE_FIELD, false ) ) {
			WSS_Config::log_security_event( 'nonce_failed', 'ajax:search' );
			wp_die( -1, 403 );
		}

		if ( ! WSS_Config::user_can_search() ) {
			wp_send_json_error( [ 'message' => __( 'Доступ запрещён.', 'wp-smart-search' ) ], 403 );
		}

		$rl = $this->rate_limiter->check();
		$this->rate_limiter->send_headers( $rl );
		if ( empty( $rl['allowed'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Слишком много запросов. Подождите минуту.', 'wp-smart-search' ) ], 429 );
		}

		$input = [
			'query'      => wp_unslash( $_POST['query'] ?? '' ),
			'refang'     => isset( $_POST['refang'] ) ? absint( $_POST['refang'] ) : 1,
			'type'       => wp_unslash( $_POST['type'] ?? 'all' ),
			'match'      => isset( $_POST['match'] ) ? sanitize_key( wp_unslash( $_POST['match'] ) ) : 'auto',
			'page'       => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
			'per_page'   => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : WSS_Config::POSTS_PER_PAGE,
			'statuses'   => isset( $_POST['statuses'] ) ? (array) wp_unslash( $_POST['statuses'] ) : [],
			'date_range' => wp_unslash( $_POST['date_range'] ?? 'any' ),
			'author'     => isset( $_POST['author'] ) ? absint( $_POST['author'] ) : 0,
			'orderby'    => wp_unslash( $_POST['orderby'] ?? 'date_desc' ),
			'exclude'    => isset( $_POST['exclude'] ) ? (array) wp_unslash( $_POST['exclude'] ) : [],
			'exclude_id' => isset( $_POST['exclude_id'] ) ? (array) wp_unslash( $_POST['exclude_id'] ) : [],
		];

		$result = $this->engine->do_search( $input, false );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}

		wp_send_json_success( $result );
	}
}
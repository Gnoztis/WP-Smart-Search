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
	}

	/**
	 * One step of a browser-driven rebuild of the links index.
	 *
	 * The browser loop (the indexing tab) sends one request per batch so a
	 * host without a working WP-Cron — or one where a cron batch takes too
	 * long — still gets visible progress. The step shares WSS_Index_Backfill
	 * with the cron worker: whoever takes the lock first works, the other
	 * answers busy.
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

		check_ajax_referer( WSS_Config::NONCE_INDEX_STEP, WSS_Config::NONCE_FIELD );

		if ( ! WSS_Config::user_can_rebuild() ) {
			wp_send_json_error( [ 'message' => __( 'Недостаточно прав для переиндексации.', 'wp-smart-search' ) ], 403 );
		}

		// A finished job is reset, a running one keeps its cursor; no cron
		// event is queued, the browser drives this run.
		WSS_Index_Backfill::start( false );

		$result = $this->backfill->run( false );
		$status = WSS_Index_Backfill::status( true );

		$total     = (int) ( $status['posts'] ?? 0 );
		$processed = (int) $status['processed'];
		$percent   = $total > 0 ? (int) min( 100, (int) round( $processed * 100 / $total ) ) : 0;

		$message = '';
		if ( 'busy' === $result ) {
			$message = __( 'Фоновая пересборка уже выполняется. Попробуйте позже.', 'wp-smart-search' );
		} elseif ( ! empty( $status['done'] ) ) {
			$message = __( 'Готово.', 'wp-smart-search' );
		}

		wp_send_json_success( [
			'done'      => ! empty( $status['done'] ),
			'processed' => $processed,
			'total'     => $total,
			'percent'   => $percent,
			'errors'    => (int) $status['errors'],
			'busy'      => 'busy' === $result,
			'links'     => isset( $status['links'] ) ? (int) $status['links'] : null,
			'message'   => $message,
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

		check_ajax_referer( WSS_Config::NONCE_ACTION, WSS_Config::NONCE_FIELD );

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
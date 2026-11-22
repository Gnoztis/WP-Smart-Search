<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Ajax_Handler {

	private WSS_Search_Engine $engine;
	private WSS_Rate_Limiter  $rate_limiter;

	public function __construct( WSS_Search_Engine $engine, WSS_Rate_Limiter $rate_limiter ) {
		$this->engine       = $engine;
		$this->rate_limiter = $rate_limiter;
	}

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . WSS_Config::AJAX_ACTION, [ $this, 'handle' ] );
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
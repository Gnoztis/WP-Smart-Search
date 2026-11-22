<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Rest_Controller {

	private WSS_Search_Engine $engine;
	private WSS_Rate_Limiter  $rate_limiter;

	public function __construct( WSS_Search_Engine $engine, WSS_Rate_Limiter $rate_limiter ) {
		$this->engine       = $engine;
		$this->rate_limiter = $rate_limiter;
	}

	public function register_hooks(): void {
		// Ранний 401 без HTML/редиректа — до всей REST-инфраструктуры.
		add_action( 'init', [ $this, 'early_auth_check' ], 1 );

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		add_filter( 'rest_authentication_errors', [ $this, 'authentication_filter' ] );
		add_filter( 'rest_pre_dispatch',          [ $this, 'pre_dispatch_filter' ], 10, 3 );
		add_filter( 'rest_post_dispatch',         [ $this, 'post_dispatch_filter' ], 10, 3 );

		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', [ $this, 'cors_handler' ], 10, 4 );
	}

	public function register_routes(): void {
		register_rest_route( WSS_Config::REST_NAMESPACE, '/search', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => [ $this, 'permission_check' ],
			'args'                => $this->search_args(),
		] );

		register_rest_route( WSS_Config::REST_NAMESPACE, '/me', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_me' ],
			'permission_callback' => [ $this, 'permission_check' ],
		] );
	}

	/* ============================================================
	 *  Ранний 401: срабатывает на init ДО REST-роутинга.
	 *  ВАЖНО: если WAF (nginx, Cloudflare, ModSecurity) блокирует
	 *  /wp-json/wss/v1/* до PHP — этот фильтр не спасёт. Нужно
	 *  добавить путь в whitelist WAF на уровне сервера.
	 * ============================================================ */

	public function early_auth_check(): void {
		if ( ! $this->is_wss_route_request() ) {
			return;
		}
		if ( $this->requested_method() === 'OPTIONS' ) {
			return;
		}
		if ( $this->get_authorization_header() !== '' ) {
			return; // есть заголовок — пусть REST обработает
		}

		if ( ! headers_sent() ) {
			status_header( 401 );
			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
		}
		echo wp_json_encode( [
			'code'    => 'wss_unauthorized',
			'message' => __( 'Authorization header required (Application Password / Basic Auth).', 'wp-smart-search' ),
			'data'    => [ 'status' => 401 ],
		] );
		exit;
	}

	/* ============================================================
	 *  Auth filter (только Application Password / Basic)
	 * ============================================================ */

	public function authentication_filter( $result ) {
		if ( ! $this->is_wss_route_request() ) {
			return $result;
		}
		if ( $this->requested_method() === 'OPTIONS' ) {
			return $result;
		}

		// Без Authorization header — wss_unauthorized (даже если есть cookie).
		if ( $this->get_authorization_header() === '' ) {
			return new WP_Error(
				'wss_unauthorized',
				__( 'Требуется Application Password. Cookie-аутентификация для API не поддерживается.', 'wp-smart-search' ),
				[ 'status' => 401 ]
			);
		}

		// Ошибки ядра (basic auth) → wss_invalid_credentials.
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wss_invalid_credentials',
				__( 'Неверные учётные данные Application Password.', 'wp-smart-search' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wss_invalid_credentials',
				__( 'Неверные учётные данные Application Password.', 'wp-smart-search' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! current_user_can( WSS_Config::CAPABILITY ) ) {
			return new WP_Error(
				'wss_forbidden',
				__( 'Недостаточно прав. Требуется роль Автор, Редактор или Администратор.', 'wp-smart-search' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/* ============================================================
	 *  Pre-dispatch — дублирующая проверка + OPTIONS
	 * ============================================================ */

	public function pre_dispatch_filter( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		if ( strpos( (string) $request->get_route(), '/' . WSS_Config::REST_NAMESPACE . '/' ) !== 0 ) {
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result !== null ) {
			return $result;
		}

		if ( strtoupper( (string) $request->get_method() ) === 'OPTIONS' ) {
			$response = new WP_REST_Response( null, 200 );
			$response->header( 'Allow', 'GET, POST, OPTIONS' );
			return $response;
		}

		if ( $this->get_authorization_header() === '' ) {
			return new WP_Error(
				'wss_unauthorized',
				__( 'Требуется Application Password. Передайте заголовок Authorization: Basic …', 'wp-smart-search' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wss_invalid_credentials',
				__( 'Неверные учётные данные Application Password.', 'wp-smart-search' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! current_user_can( WSS_Config::CAPABILITY ) ) {
			return new WP_Error(
				'wss_forbidden',
				__( 'Недостаточно прав. Требуется роль Автор, Редактор или Администратор.', 'wp-smart-search' ),
				[ 'status' => 403 ]
			);
		}

		return $result;
	}

	public function permission_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'wss_unauthorized', __( 'Требуется Application Password.', 'wp-smart-search' ), [ 'status' => 401 ] );
		}
		if ( ! current_user_can( WSS_Config::CAPABILITY ) ) {
			return new WP_Error( 'wss_forbidden', __( 'Недостаточно прав.', 'wp-smart-search' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/* ============================================================
	 *  JSON-щит: HTML-строка в ответе → JSON 500
	 * ============================================================ */

	public function post_dispatch_filter( $response, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( strpos( (string) $request->get_route(), '/' . WSS_Config::REST_NAMESPACE . '/' ) !== 0 ) {
			return $response;
		}

		$data = $response->get_data();

		if ( is_string( $data ) && $data !== '' ) {
			$trim  = trim( $data );
			$first = $trim === '' ? '' : $trim[0];
			if ( $first !== '{' && $first !== '[' ) {
				$status = (int) $response->get_status();
				if ( $status < 400 ) {
					$status = 500;
				}
				$err = new WP_REST_Response( [
					'code'    => 'wss_internal_error',
					'message' => __( 'Внутренняя ошибка сервера.', 'wp-smart-search' ),
					'data'    => [ 'status' => $status ],
				], $status );
				$err->header( 'Content-Type', 'application/json; charset=UTF-8' );
				return $err;
			}
		}

		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );
		return $response;
	}

	/* ============================================================
	 *  CORS (строгий белый список для /wss/v1/*)
	 * ============================================================ */

	public function cors_handler( $served, $result, $request, $server ) {
		$origin = function_exists( 'get_http_origin' ) ? get_http_origin() : '';
		if ( ! $origin ) {
			return $served;
		}

		$origin = esc_url_raw( (string) $origin );
		$route  = $request instanceof WP_REST_Request ? (string) $request->get_route() : '';
		$is_wss = strpos( $route, '/' . WSS_Config::REST_NAMESPACE . '/' ) === 0;

		if ( $is_wss ) {
			if ( ! $this->is_origin_allowed( $origin ) ) {
				return $served;
			}
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
			header( 'Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After' );
			header( 'Access-Control-Max-Age: 86400' );
			header( 'Vary: Origin', false );
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Disposition, Content-MD5, Content-Type' );

		return $served;
	}

	private function is_origin_allowed( string $origin ): bool {
		$origin = rtrim( $origin, '/' );
		foreach ( $this->allowed_origins() as $allowed ) {
			if ( rtrim( (string) $allowed, '/' ) === $origin ) {
				return true;
			}
		}
		return false;
	}

	private function allowed_origins(): array {
		$origins = [];
		foreach ( [ home_url(), site_url() ] as $url ) {
			$parsed = wp_parse_url( (string) $url );
			if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
				continue;
			}
			$o = $parsed['scheme'] . '://' . $parsed['host'];
			if ( ! empty( $parsed['port'] ) ) {
				$o .= ':' . (int) $parsed['port'];
			}
			$origins[] = $o;
		}
		$origins = array_values( array_unique( $origins ) );
		return array_values( array_unique( (array) apply_filters( 'wss_allowed_origins', $origins ) ) );
	}

	/* ============================================================
	 *  Хелперы
	 * ============================================================ */

	private function is_wss_route_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( $uri === '' ) {
			return false;
		}
		if ( strpos( $uri, '/' . WSS_Config::REST_NAMESPACE . '/' ) !== false ) {
			return true;
		}
		// Plain permalinks: /?rest_route=/wss/v1/search (возможно URL-encoded).
		if ( preg_match( '#[?&]rest_route=(?:%2F|/)wss(?:%2F|/)v1(?:%2F|/)#i', $uri ) ) {
			return true;
		}
		return false;
	}

	private function requested_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
	}

	private function get_authorization_header(): string {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return (string) $_SERVER['HTTP_AUTHORIZATION'];
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( strtolower( (string) $name ) === 'authorization' ) {
						return (string) $value;
					}
				}
			}
		}
		return '';
	}

	/* ============================================================
	 *  Args
	 * ============================================================ */

	private function search_args(): array {
		return [
			'q'          => [ 'description' => __( 'Поисковый запрос (мин. 2 символа).', 'wp-smart-search' ), 'type' => 'string' ],
			'refang'     => [ 'description' => __( 'Auto-refang (по умолчанию true).', 'wp-smart-search' ), 'type' => 'boolean' ],
			'scope'      => [
				'description' => __( 'posts | links | all (по умолчанию posts).', 'wp-smart-search' ),
				'type'        => 'string',
				'enum'        => WSS_Config::SCOPES,
			],
			'type'       => [ 'description' => __( 'all | tags | posts (по умолчанию posts для API).', 'wp-smart-search' ), 'type' => 'string' ],
			'match'      => [ 'description' => __( 'auto | fuzzy | exact (по умолчанию auto).', 'wp-smart-search' ), 'type' => 'string' ],
			'page'       => [ 'description' => __( 'Страница (1..N).', 'wp-smart-search' ), 'type' => 'integer' ],
			'per_page'   => [ 'description' => __( 'Элементов на страницу (1..50).', 'wp-smart-search' ), 'type' => 'integer' ],
			'statuses'   => [ 'description' => __( 'Массив статусов или строка через запятую.', 'wp-smart-search' ) ],
			'date_range' => [ 'description' => __( 'any | today | week | month | year', 'wp-smart-search' ), 'type' => 'string' ],
			'date_from'  => [ 'description' => __( 'С ISO-даты (включительно).', 'wp-smart-search' ), 'type' => 'string' ],
			'date_to'    => [ 'description' => __( 'По ISO-дату (включительно).', 'wp-smart-search' ), 'type' => 'string' ],
			'author'     => [ 'description' => __( 'ID автора.', 'wp-smart-search' ), 'type' => 'integer' ],
			'post_type'  => [ 'description' => __( 'post | page | … (по умолчанию post).', 'wp-smart-search' ), 'type' => 'string' ],
			'orderby'    => [ 'description' => __( 'date_desc | date_asc', 'wp-smart-search' ), 'type' => 'string' ],
			'exclude'    => [ 'description' => __( 'ID записей для исключения.', 'wp-smart-search' ) ],
			'exclude_id' => [ 'description' => __( 'ID записи (скаляр или массив).', 'wp-smart-search' ) ],
			// links scope:
			'url'        => [ 'description' => __( 'Точный URL (нормализуется).', 'wp-smart-search' ), 'type' => 'string' ],
			'domain'     => [ 'description' => __( 'Registrable domain (включая поддомены).', 'wp-smart-search' ), 'type' => 'string' ],
			'host'       => [ 'description' => __( 'Точный host (без поддоменов).', 'wp-smart-search' ), 'type' => 'string' ],
			'anchor'     => [ 'description' => __( 'Подстрока в anchor-тексте.', 'wp-smart-search' ), 'type' => 'string' ],
			'fields'     => [ 'description' => __( 'Список полей links.items через запятую.', 'wp-smart-search' ) ],
		];
	}

	/* ============================================================
	 *  Handlers
	 * ============================================================ */

	public function handle_search( WP_REST_Request $request ) {
		$rl = $this->rate_limiter->check();
		$this->rate_limiter->send_headers( $rl );
		if ( empty( $rl['allowed'] ) ) {
			return new WP_Error(
				'wss_rate_limited',
				__( 'Слишком много запросов. Подождите минуту.', 'wp-smart-search' ),
				[ 'status' => 429 ]
			);
		}

		$q_raw = $request->get_param( 'q' );
		$q_str = is_string( $q_raw ) ? trim( (string) $q_raw ) : '';

		// Для scope=links параметр q может быть не задан — тогда q берётся из
		// url/domain/host/anchor внутри движка. Для posts/all — q обязателен.
		$scope = (string) ( $request->get_param( 'scope' ) ?? 'posts' );
		$scope = sanitize_key( $scope );
		if ( ! in_array( $scope, WSS_Config::SCOPES, true ) ) {
			$scope = 'posts';
		}

		$url_p    = (string) ( $request->get_param( 'url' )    ?? '' );
		$domain_p = (string) ( $request->get_param( 'domain' ) ?? '' );
		$host_p   = (string) ( $request->get_param( 'host' )   ?? '' );
		$anchor_p = (string) ( $request->get_param( 'anchor' ) ?? '' );

		$has_link_filter = ( $url_p !== '' || $domain_p !== '' || $host_p !== '' || $anchor_p !== '' );

		if ( $scope === 'links' && $q_str === '' && $has_link_filter ) {
			// q формируется движком из url/domain/host/anchor.
			$q_str = $url_p !== '' ? $url_p
				: ( $domain_p !== '' ? $domain_p
				: ( $host_p !== '' ? $host_p : $anchor_p ) );
		}

		if ( $q_str === '' ) {
			return new WP_Error( 'wss_empty', __( 'Параметр q не может быть пустым.', 'wp-smart-search' ), [ 'status' => 400 ] );
		}

		if ( mb_strlen( $q_str ) < WSS_Config::MIN_LEN && ! $has_link_filter ) {
			return new WP_Error(
				'wss_too_short',
				sprintf(
					/* translators: %d: minimum length */
					__( 'Запрос должен содержать не менее %d символов.', 'wp-smart-search' ),
					WSS_Config::MIN_LEN
				),
				[ 'status' => 400 ]
			);
		}

		$input = [
			'query'      => $q_str,
			'refang'     => $request->get_param( 'refang' ),
			'scope'      => $scope,
			'type'       => (string) ( $request->get_param( 'type' ) ?? 'posts' ),
			'match'      => (string) ( $request->get_param( 'match' ) ?? 'auto' ),
			'page'       => (int) ( $request->get_param( 'page' ) ?? 1 ),
			'per_page'   => (int) ( $request->get_param( 'per_page' ) ?? WSS_Config::POSTS_PER_PAGE_MAX ),
			'statuses'   => ( $request->get_param( 'statuses' ) ?? WSS_Config::ALLOWED_STATUSES ),
			'date_range' => (string) ( $request->get_param( 'date_range' ) ?? 'any' ),
			'date_from'  => (string) ( $request->get_param( 'date_from' ) ?? '' ),
			'date_to'    => (string) ( $request->get_param( 'date_to' ) ?? '' ),
			'author'     => (int) ( $request->get_param( 'author' ) ?? 0 ),
			'post_type'  => (string) ( $request->get_param( 'post_type' ) ?? 'post' ),
			'orderby'    => (string) ( $request->get_param( 'orderby' ) ?? 'date_desc' ),
			'exclude'    => (array) ( $request->get_param( 'exclude' ) ?? [] ),
			'exclude_id' => (array) ( $request->get_param( 'exclude_id' ) ?? [] ),
			'url'        => $url_p,
			'domain'     => $domain_p,
			'host'       => $host_p,
			'anchor'     => $anchor_p,
			'fields'     => $request->get_param( 'fields' ),
		];

		$result = $this->engine->do_search( $input, true );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wss_' . $result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	public function handle_me( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		return new WP_REST_Response( [
			'id'                   => (int) $user->ID,
			'name'                 => (string) $user->display_name,
			'roles'                => array_values( (array) $user->roles ),
			'can_search'           => true,
			'can_filter_by_author' => current_user_can( 'edit_others_posts' ),
			'allowed_statuses'     => WSS_Config::ALLOWED_STATUSES,
			'max_per_page'         => WSS_Config::POSTS_PER_PAGE_MAX,
			'plugin_version'       => WSS_VERSION,
			'api_version'          => 'v1',
			'match_modes'          => [ 'auto', 'fuzzy', 'exact' ],
			'scopes'               => WSS_Config::SCOPES,
		], 200 );
	}
}
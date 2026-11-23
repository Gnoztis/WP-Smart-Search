<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Rest_Controller {

	private WSS_Search_Engine $engine;
	private WSS_Rate_Limiter  $rate_limiter;
	private WSS_Index_Backfill $backfill;
	private WSS_Tags $tags;

	public function __construct( WSS_Search_Engine $engine, WSS_Rate_Limiter $rate_limiter, ?WSS_Index_Backfill $backfill = null, ?WSS_Tags $tags = null ) {
		$this->engine       = $engine;
		$this->rate_limiter = $rate_limiter;
		$this->backfill     = $backfill ?? new WSS_Index_Backfill();
		$this->tags         = $tags ?? new WSS_Tags( new WSS_Cache() );
	}

	public function register_hooks(): void {
		// Early 401 without HTML/redirect, before the REST stack loads.
		add_action( 'init', [ $this, 'early_auth_check' ], 1 );

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		add_filter( 'rest_authentication_errors', [ $this, 'authentication_filter' ] );
		add_filter( 'rest_pre_dispatch',          [ $this, 'pre_dispatch_filter' ], 10, 3 );
		add_filter( 'rest_post_dispatch',         [ $this, 'post_dispatch_filter' ], 10, 3 );

		// Replace the core CORS handler with ours: strict allowlist for
		// wss/*, exact core behaviour for every other route.
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		add_filter( 'rest_pre_serve_request', [ $this, 'cors_handler' ], 10, 4 );
	}

	public function register_routes(): void {
		register_rest_route( WSS_Config::REST_NAMESPACE, '/search', [
			'methods'             => WSS_Config::REST_METHODS,
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => [ $this, 'permission_check' ],
			'args'                => $this->search_args(),
		] );

		register_rest_route( WSS_Config::REST_NAMESPACE, '/links/batch', [
			'methods'             => WSS_Config::REST_METHODS,
			'callback'            => [ $this, 'handle_links_batch' ],
			'permission_callback' => [ $this, 'permission_check' ],
			'args'                => $this->batch_args(),
		] );

		// GET and POST: clients that send every call as POST (GET responses
		// may be cached by a reverse proxy in front of the site) must not get
		// a 404 from WordPress, which is what a method mismatch returns.
		register_rest_route( WSS_Config::REST_NAMESPACE, '/me', [
			'methods'             => WSS_Config::REST_METHODS,
			'callback'            => [ $this, 'handle_me' ],
			'permission_callback' => [ $this, 'permission_check' ],
		] );

		// Index health: a link audit is meaningless while the index is
		// empty or stale, so consumers need to ask before trusting results.
		register_rest_route( WSS_Config::REST_NAMESPACE, '/index', [
			'methods'             => WSS_Config::REST_METHODS,
			'callback'            => [ $this, 'handle_index_status' ],
			'permission_callback' => [ $this, 'permission_check_admin' ],
		] );

		register_rest_route( WSS_Config::REST_NAMESPACE, '/index/rebuild', [
			'methods'             => WSS_Config::REST_METHODS,
			'callback'            => [ $this, 'handle_index_rebuild' ],
			'permission_callback' => [ $this, 'permission_check_admin' ],
		] );

		// One batch of the background worker, synchronously: lets an operator
		// verify the cron path without waiting for WP-Cron to fire.
		register_rest_route( WSS_Config::REST_NAMESPACE, '/index/step', [
			'methods'             => WSS_Config::REST_METHODS,
			'callback'            => [ $this, 'handle_index_step' ],
			'permission_callback' => [ $this, 'permission_check_admin' ],
		] );

		// Destructive: drops the whole index and creates it again. Requires an
		// explicit confirmation in the body so a stray POST cannot wipe it.
		register_rest_route( WSS_Config::REST_NAMESPACE, '/index/recreate', [
			'methods'             => WSS_Config::REST_METHODS,
			'callback'            => [ $this, 'handle_index_recreate' ],
			'permission_callback' => [ $this, 'permission_check_admin' ],
		] );

		// Terms: the pipeline used to call /wp/v2/tags twice per material
		// (slug, then search). One request answers both.
		register_rest_route( WSS_Config::REST_NAMESPACE, '/tags', [
			'methods'             => WSS_Config::REST_METHODS,
			'callback'            => [ $this, 'handle_tags' ],
			'permission_callback' => [ $this, 'permission_check' ],
			'args'                => $this->tags_args(),
		] );
	}

	/**
	 * Administrator gate for the index endpoints.
	 *
	 * The Authorization header and the search capability are already
	 * enforced for every wss route; these two are stricter.
	 *
	 * @param mixed $request
	 * @return true|WP_Error
	 */
	public function permission_check_admin( $request ) {
		$basic = $this->permission_check( $request );
		if ( is_wp_error( $basic ) ) {
			return $basic;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wss_forbidden',
				__( 'Недостаточно прав: диагностика индекса доступна администратору.', 'wp-smart-search' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/* ============================================================
	 *  Early 401: fires on init, BEFORE REST routing.
	 *  IMPORTANT: if a WAF (nginx, Cloudflare, ModSecurity) blocks
	 *  /wp-json/wss/v1/* before PHP, this filter cannot help — the
	 *  path must be allow-listed in the WAF.
	 * ============================================================ */

	public function early_auth_check(): void {
		if ( ! $this->is_wss_route_request() ) {
			return;
		}
		if ( $this->requested_method() === 'OPTIONS' ) {
			return;
		}
		if ( $this->get_authorization_header() !== '' ) {
			return; // header present — let REST handle it
		}

		if ( ! headers_sent() ) {
			status_header( 401 );
			nocache_headers();
			// Same headers as every REST answer: the early exit must not
			// become the one response a proxy is allowed to cache.
			$this->send_no_store_headers();
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
	 *  Auth filter (Application Password / Basic only)
	 * ============================================================ */

	public function authentication_filter( $result ) {
		if ( ! $this->is_wss_route_request() ) {
			return $result;
		}
		if ( $this->requested_method() === 'OPTIONS' ) {
			return $result;
		}

		// No Authorization header — wss_unauthorized (even with cookies).
		if ( $this->get_authorization_header() === '' ) {
			return new WP_Error(
				'wss_unauthorized',
				__( 'Требуется Application Password. Cookie-аутентификация для API не поддерживается.', 'wp-smart-search' ),
				[ 'status' => 401 ]
			);
		}

		// Core errors (basic auth) → wss_invalid_credentials.
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
	 *  Pre-dispatch — duplicate check + OPTIONS
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
			$response->header( 'Allow', $this->allowed_methods( (string) $request->get_route() ) );
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
		// Defense in depth: we do not rely on the filter order of
		// rest_authentication_errors — /wss/v1/* requires the
		// Authorization header (Application Password); cookie auth is
		// not supported here.
		if ( $this->get_authorization_header() === '' ) {
			return new WP_Error( 'wss_unauthorized', __( 'Требуется Application Password.', 'wp-smart-search' ), [ 'status' => 401 ] );
		}
		if ( ! is_user_logged_in() ) {
			WSS_Config::log_security_event( 'cap_denied', 'rest:unauthorized' );
			return new WP_Error( 'wss_unauthorized', __( 'Требуется Application Password.', 'wp-smart-search' ), [ 'status' => 401 ] );
		}
		if ( ! current_user_can( WSS_Config::CAPABILITY ) ) {
			WSS_Config::log_security_event( 'cap_denied', 'rest:forbidden' );
			return new WP_Error( 'wss_forbidden', __( 'Недостаточно прав.', 'wp-smart-search' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/* ============================================================
	 *  JSON shield: an HTML string in the response becomes a JSON 500
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

		// Search results depend on the authenticated user (drafts, private
		// posts), so no shared cache may store them: a reverse proxy that
		// caches GET /wp-json/wss/v1/* would serve one author's drafts to
		// another user. Vary makes the cache key user-specific anyway.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Vary', 'Authorization' );

		return $response;
	}

	/**
	 * Allow header for an OPTIONS preflight.
	 */
	private function allowed_methods(): string {
		// Every route accepts GET and POST (WSS_Config::REST_METHODS);
		// OPTIONS is the CORS preflight.
		return implode( ', ', array_merge( WSS_Config::REST_METHODS, [ 'OPTIONS' ] ) );
	}

	/**
	 * Headers that keep a reverse proxy away from an answer.
	 *
	 * Every route accepts GET because clients need it, but the answer depends
	 * on the authenticated user (drafts, private posts), so NGINX in front of
	 * the site must not cache it: no-store plus Vary: Authorization. Sent on
	 * success and on every error path, including the early 401.
	 */
	private function send_no_store_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
		header( 'Pragma: no-cache' );
		header( 'Vary: Authorization' );
	}

	/* ============================================================
	 *  CORS (strict allowlist for /wss/v1/*)
	 * ============================================================ */

	public function cors_handler( $served, $result, $request, $server ) {
		$route  = $request instanceof WP_REST_Request ? (string) $request->get_route() : '';
		$is_wss = strpos( $route, '/' . WSS_Config::REST_NAMESPACE . '/' ) === 0;

		// Other REST routes are served by core — no relaxations from us.
		if ( ! $is_wss ) {
			return function_exists( 'rest_send_cors_headers' ) ? rest_send_cors_headers( $served ) : $served;
		}

		$origin = function_exists( 'get_http_origin' ) ? (string) get_http_origin() : '';
		if ( $origin === '' ) {
			return $served;
		}

		$origin = esc_url_raw( $origin );
		if ( $origin === '' || ! $this->is_origin_allowed( $origin ) ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			// Auth for wss/* is header-only, cookies are irrelevant, so
			// Access-Control-Allow-Credentials is not sent.
			// The value passed esc_url_raw() (strips CR/LF), so header
			// injection is impossible.
			header( 'Access-Control-Allow-Origin: ' . $origin ); // phpcs:ignore -- esc_url_raw() strips CR/LF, so header injection is impossible.
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
			header( 'Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After' );
			header( 'Access-Control-Max-Age: 86400' );
			header( 'Vary: Origin', false );
		}

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
	 *  Helpers
	 * ============================================================ */

	private function is_wss_route_request(): bool {
		$route = $this->requested_rest_route();
		return $route !== '' && strpos( $route, '/' . WSS_Config::REST_NAMESPACE . '/' ) === 0;
	}

	/**
	 * REST route of the current request, resolved without booting REST.
	 *
	 * We used to look for /wss/v1/ inside REQUEST_URI, so any
	 * unrelated URL containing it got a 401 from the init hook.
	 */
	private function requested_rest_route(): string {
		// Only a string is a route: `?rest_route[]=x` must not reach a
		// string cast below (PHP would emit "Array to string conversion"
		// on every such request, unauthenticated included).
		$raw = isset( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : '';
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( $raw !== '' ) {
			return '/' . ltrim( $raw, '/' );
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( $uri === '' ) {
			return '';
		}

		$rest_path = (string) wp_parse_url( rest_url(), PHP_URL_PATH );
		$path      = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( $rest_path === '' || $rest_path === '/' || strpos( $rest_path, 'rest_route' ) !== false || $path === '' ) {
			return '';
		}
		if ( strpos( $path, $rest_path ) !== 0 ) {
			return '';
		}

		return '/' . ltrim( substr( $path, strlen( $rest_path ) ), '/' );
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
			'q'          => [
				'description'       => __( 'Поисковый запрос (мин. 2 символа); слова ищутся по основам, между словами — И.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'refang'     => [
				'description'       => __( 'Auto-refang (по умолчанию true).', 'wp-smart-search' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
			'scope'      => [
				'description'       => __( 'posts | links | all (по умолчанию posts).', 'wp-smart-search' ),
				'type'              => 'string',
				'enum'              => WSS_Config::SCOPES,
				'sanitize_callback' => 'sanitize_key',
			],
			'type'       => [
				'description'       => __( 'all | tags | posts (по умолчанию posts для API).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'match'      => [
				'description'       => __( 'auto | fuzzy | exact (по умолчанию auto).', 'wp-smart-search' ),
				'type'              => 'string',
				'enum'              => WSS_Config::MATCH_MODES,
				'sanitize_callback' => 'sanitize_key',
			],
			'page'       => [
				'description'       => __( 'Страница (1..N).', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'per_page'   => [
				'description'       => __( 'Элементов на страницу (1..50).', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'statuses'   => [
				'description' => __( 'Массив статусов или строка через запятую. Неизвестный статус — 400.', 'wp-smart-search' ),
			],
			'date_range' => [
				'description'       => __( 'any | today | week | month | year', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_from'  => [
				'description'       => __( 'С ISO-даты (включительно).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_to'    => [
				'description'       => __( 'По ISO-дату (включительно).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'author'     => [
				'description'       => __( 'ID автора (только для edit_others_posts).', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'post_type'  => [
				'description'       => __( 'post | page | … (по умолчанию post); неизвестный тип — 400.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'orderby'    => [
				'description'       => __( 'date_desc | date_asc', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'exclude'    => [
				'description' => __( 'ID записей для исключения (массив, максимум 200).', 'wp-smart-search' ),
			],
			'exclude_id' => [
				'description' => __( 'ID записи (скаляр или массив).', 'wp-smart-search' ),
			],
			// links scope:
			'url'        => [
				'description'       => __( 'Точный URL (нормализуется).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'domain'     => [
				'description'       => __( 'Registrable domain (включая поддомены).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'host'       => [
				'description'       => __( 'Точный host (без поддоменов).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'anchor'     => [
				'description'       => __( 'Подстрока в anchor-тексте.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'anchor_match' => [
				'description'       => __( 'Семантика anchor: words (по словам, по умолчанию), exact или substring.', 'wp-smart-search' ),
				'type'              => 'string',
				'enum'              => WSS_Config::ANCHOR_MATCH_MODES,
				'sanitize_callback' => 'sanitize_key',
			],
			'anchor_kind' => [
				'description' => __( 'Тип анкора: phrase, url, empty, unknown (можно списком).', 'wp-smart-search' ),
			],
			'post_id'    => [
				'description' => __( 'ID записи или массив ID: вернуть все ссылки этих записей.', 'wp-smart-search' ),
			],
			'fields'     => [
				'description' => sprintf(
					/* translators: 1: available fields, 2: required fields */
					__( 'Список полей links.items через запятую. Доступны: %1$s. Всегда возвращаются: %2$s.', 'wp-smart-search' ),
					implode( ', ', WSS_Config::LINK_FIELDS ),
					implode( ', ', WSS_Config::LINK_FIELDS_REQUIRED )
				),
			],
			'exact_total' => [
				'description'       => __( 'Считать точный total (по умолчанию true; false экономит один запрос COUNT).', 'wp-smart-search' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
		];
	}

	/**
	 * Arguments of GET /tags.
	 *
	 * The cross-parameter rules (slug+search, minimum lengths, the 50-slug
	 * cap) are validated in WSS_Tags so the errors keep the wss_* codes.
	 */
	private function tags_args(): array {
		return [
			'slug'           => [
				'description'       => __( 'Слаг или до 50 слагов через запятую.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'search'         => [
				'description'       => __( 'Подстрока имени, минимум 3 символа, регистр не важен.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'prefix'         => [
				'description'       => __( 'Имя начинается с подстроки, минимум 2 символа.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'post_id'        => [
				'description'       => __( 'Вернуть метки указанной записи.', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'taxonomy'       => [
				'description'       => __( 'post_tag (по умолчанию), category или any.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'include_counts' => [
				'description'       => __( 'Вернуть count (число постов), по умолчанию true.', 'wp-smart-search' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
			'page'           => [
				'description'       => __( 'Страница, с 1.', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'per_page'       => [
				'description'       => __( 'Элементов на страницу, 1..100, по умолчанию 20.', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'fields'         => [
				'description' => __( 'Поля items через запятую: id, name, slug, count. Неизвестное поле — 400.', 'wp-smart-search' ),
			],
		];
	}

	private function batch_args(): array {
		$item = [ 'type' => 'string' ];

		return [			'domains'    => [
				'description' => __( 'Домены для поиска ссылок (максимум 50 за вызов).', 'wp-smart-search' ),
				'type'        => 'array',
				'items'       => $item,
			],
			'hosts'      => [
				'description' => __( 'Точные хосты для поиска ссылок.', 'wp-smart-search' ),
				'type'        => 'array',
				'items'       => $item,
			],
			'urls'       => [
				'description' => __( 'Точные URL для поиска ссылок.', 'wp-smart-search' ),
				'type'        => 'array',
				'items'       => $item,
			],
			'anchors'    => [
				'description' => __( 'Подстроки anchor-текста.', 'wp-smart-search' ),
				'type'        => 'array',
				'items'       => $item,
			],
			'per_page'   => [
				'description'       => __( 'Элементов на каждый запрос (1..50, по умолчанию 5).', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'post_type'  => [
				'description'       => __( 'Тип записи (по умолчанию post).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'statuses'   => [
				'description' => __( 'Массив статусов или строка через запятую.', 'wp-smart-search' ),
			],
			'anchor_match' => [
				'description'       => __( 'Семантика anchors: words (по словам, по умолчанию), exact или substring.', 'wp-smart-search' ),
				'type'              => 'string',
				'enum'              => WSS_Config::ANCHOR_MATCH_MODES,
				'sanitize_callback' => 'sanitize_key',
			],
			'anchor_kind' => [
				'description' => __( 'Тип анкора: phrase, url, empty, unknown (можно списком).', 'wp-smart-search' ),
			],
			'fields'     => [
				'description' => __( 'Список полей links.items через запятую.', 'wp-smart-search' ),
			],
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

		$q_raw = $this->param( $request, 'q' );
		$q_str = is_string( $q_raw ) ? trim( (string) $q_raw ) : '';

		// For scope=links q may be omitted — the engine then takes it from
		// url/domain/host/anchor. For posts/all q is required.
		$scope = (string) ( $this->param( $request, 'scope' ) ?? 'posts' );
		$scope = sanitize_key( $scope );
		if ( ! in_array( $scope, WSS_Config::SCOPES, true ) ) {
			$scope = 'posts';
		}

		$url_p    = (string) ( $this->param( $request, 'url' )    ?? '' );
		$domain_p = (string) ( $this->param( $request, 'domain' ) ?? '' );
		$host_p   = (string) ( $this->param( $request, 'host' )   ?? '' );
		$anchor_p = (string) ( $this->param( $request, 'anchor' ) ?? '' );

		$post_id_p     = $this->param( $request, 'post_id' );
		$anchor_kind_p = $this->param( $request, 'anchor_kind' );

		$has_link_filter = ( $url_p !== '' || $domain_p !== '' || $host_p !== '' || $anchor_p !== '' )
			|| ! empty( $post_id_p )
			|| ! empty( $anchor_kind_p );

		if ( $scope === 'links' && $q_str === '' && $has_link_filter ) {
			// q is derived by the engine from url/domain/host/anchor. post_id
			// and anchor_kind need no q at all.
			$q_str = $url_p !== '' ? $url_p
				: ( $domain_p !== '' ? $domain_p
				: ( $host_p !== '' ? $host_p : $anchor_p ) );
		}

		if ( $q_str === '' && ! $has_link_filter ) {
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
			'refang'     => $this->param( $request, 'refang' ),
			'scope'      => $scope,
			'type'       => (string) ( $this->param( $request, 'type' ) ?? 'posts' ),
			'match'      => (string) ( $this->param( $request, 'match' ) ?? 'auto' ),
			'page'       => (int) ( $this->param( $request, 'page' ) ?? 1 ),
			'per_page'   => (int) ( $this->param( $request, 'per_page' ) ?? WSS_Config::POSTS_PER_PAGE_MAX ),
			'statuses'   => ( $this->param( $request, 'statuses' ) ?? WSS_Config::ALLOWED_STATUSES ),
			'date_range' => (string) ( $this->param( $request, 'date_range' ) ?? 'any' ),
			'date_from'  => (string) ( $this->param( $request, 'date_from' ) ?? '' ),
			'date_to'    => (string) ( $this->param( $request, 'date_to' ) ?? '' ),
			'author'     => (int) ( $this->param( $request, 'author' ) ?? 0 ),
			'post_type'  => (string) ( $this->param( $request, 'post_type' ) ?? 'post' ),
			'orderby'    => (string) ( $this->param( $request, 'orderby' ) ?? 'date_desc' ),
			'exclude'    => (array) ( $this->param( $request, 'exclude' ) ?? [] ),
			'exclude_id' => (array) ( $this->param( $request, 'exclude_id' ) ?? [] ),
			'url'        => $url_p,
			'domain'     => $domain_p,
			'host'       => $host_p,
			'anchor'     => $anchor_p,
			'anchor_match' => (string) ( $this->param( $request, 'anchor_match' ) ?? 'words' ),
			'anchor_kind'  => $anchor_kind_p,
			'post_id'    => $post_id_p,
			'fields'     => $this->param( $request, 'fields' ),
			'exact_total' => $this->param( $request, 'exact_total' ),
		];

		$result = $this->engine->do_search( $input, true );
		if ( is_wp_error( $result ) ) {
			return $this->as_rest_error( $result );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Request parameter with a merged, body-first lookup.
	 *
	 * Every route accepts GET and POST, and a POST may carry its parameters in
	 * the query string (clients often build the URL first and add a body
	 * later). The merge is explicit here, in one place: a JSON body wins over
	 * a form body, both win over the query string.
	 *
	 * @param WP_REST_Request $request
	 * @param string          $key
	 * @param mixed           $default
	 * @return mixed
	 */
	private function param( WP_REST_Request $request, string $key, $default = null ) {
		$json = $request->get_json_params();
		if ( is_array( $json ) && array_key_exists( $key, $json ) ) {
			return $json[ $key ];
		}

		$body = $request->get_body_params();
		if ( is_array( $body ) && array_key_exists( $key, $body ) ) {
			return $body[ $key ];
		}

		$query = $request->get_query_params();
		if ( is_array( $query ) && array_key_exists( $key, $query ) ) {
			return $query[ $key ];
		}

		$params = $request->get_params();

		return is_array( $params ) && array_key_exists( $key, $params ) ? $params[ $key ] : $default;
	}

	/**
	 * Single engine error format: wss_* code, status from data (400 by default).
	 */
	private function as_rest_error( WP_Error $error ): WP_Error {
		$data   = (array) $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 400;
		$code   = (string) $error->get_error_code();

		if ( strpos( $code, 'wss_' ) !== 0 ) {
			$code = 'wss_' . $code;
		}

		return new WP_Error( $code, $error->get_error_message(), [ 'status' => $status ] );
	}

	/**
	 * Batch link search: up to WSS_Config::BATCH_MAX lookups per call.
	 */
	public function handle_links_batch( WP_REST_Request $request ) {
		$rl = $this->rate_limiter->check();
		$this->rate_limiter->send_headers( $rl );
		if ( empty( $rl['allowed'] ) ) {
			return new WP_Error(
				'wss_rate_limited',
				__( 'Слишком много запросов. Подождите минуту.', 'wp-smart-search' ),
				[ 'status' => 429 ]
			);
		}

		$map     = [ 'domains' => 'domain', 'hosts' => 'host', 'urls' => 'url', 'anchors' => 'anchor' ];
		$lookups = [];
		$total   = 0;

		foreach ( $map as $param => $type ) {
			$values = $this->param( $request, $param );
			if ( ! is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}
				$value = trim( $value );
				if ( $value === '' ) {
					continue;
				}
				if ( mb_strlen( $value ) > 2048 ) {
					return new WP_Error(
						'wss_batch_value_too_long',
						__( 'Значение в batch-запросе длиннее 2048 символов.', 'wp-smart-search' ),
						[ 'status' => 400 ]
					);
				}
				$total++;
				if ( count( $lookups ) < WSS_Config::BATCH_MAX ) {
					$lookups[] = [ $type => $value ];
				}
			}
		}

		if ( empty( $lookups ) ) {
			return new WP_Error(
				'wss_empty_batch',
				__( 'Передайте хотя бы один домен, хост, URL или anchor.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}

		$input = [
			'per_page'     => $this->param( $request, 'per_page' ),
			'post_type'    => (string) ( $this->param( $request, 'post_type' ) ?? 'post' ),
			'statuses'     => $this->param( $request, 'statuses' ) ?? WSS_Config::ALLOWED_STATUSES,
			'fields'       => $this->param( $request, 'fields' ),
			'anchor_match' => (string) ( $this->param( $request, 'anchor_match' ) ?? 'words' ),
			'anchor_kind'  => $this->param( $request, 'anchor_kind' ),
		];

		$result = $this->engine->do_links_batch( $lookups, $input, true );
		if ( is_wp_error( $result ) ) {
			return $this->as_rest_error( $result );
		}

		$result['meta']['truncated'] = $total > count( $lookups );
		$result['meta']['requested'] = $total;

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /wss/v1/index — read-only index health.
	 *
	 * A link audit must call this first: an empty or stale index answers
	 * "nothing found" for every query, which is not the same as "no links".
	 */
	public function handle_index_status( WP_REST_Request $request ) {
		return new WP_REST_Response( WSS_Index_Backfill::report(), 200 );
	}

	/**
	 * GET|POST /wss/v1/index/rebuild — queue the background rebuild and answer.
	 *
	 * The job runs in WP-Cron; the request only schedules it, so it never
	 * blocks on a long rebuild. Both verbs are accepted (NGINX caches GET),
	 * which makes a repeated call — a proxy retry, a double click — safe: a
	 * job that is already running keeps its cursor and one scheduled event,
	 * and the answer says so.
	 */
	public function handle_index_rebuild( WP_REST_Request $request ) {
		$running = ! WSS_Index_Backfill::report()['done'];

		WSS_Index_Backfill::start();

		$report = WSS_Index_Backfill::report();

		return new WP_REST_Response( [
			'accepted'       => true,
			'queued'         => true,
			'already_running' => $running,
			'state'          => $report['state'],
			'posts_total'    => $report['posts_total'],
			'processed'      => $report['processed'],
			'last_run'       => $report['last_run'],
		], 202 );
	}

	/**
	 * GET|POST /wss/v1/index/recreate — drop the index and create it again.
	 *
	 * The body must carry {"confirm":"recreate"}: the action deletes every
	 * indexed row, and a mistyped request must not do that by accident.
	 * Recreating while a rebuild is running is refused: the worker would be
	 * writing into a table that no longer exists.
	 */
	public function handle_index_recreate( WP_REST_Request $request ) {
		$confirm = $this->param( $request, 'confirm' );

		if ( 'recreate' !== ( is_string( $confirm ) ? trim( $confirm ) : '' ) ) {
			return new WP_Error(
				'wss_confirm_required',
				__( 'Пересоздание удалит индекс. Передайте confirm=recreate, чтобы подтвердить.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}

		$before = WSS_Index_Backfill::report();

		if ( ! $before['done'] && 'empty' !== $before['state'] ) {
			return new WP_Error(
				'wss_busy',
				__( 'Идёт пересборка индекса: дождитесь её завершения или остановите её, затем пересоздайте таблицу.', 'wp-smart-search' ),
				[ 'status' => 409 ]
			);
		}

		$result = WSS_Links_Indexer::recreate();
		WSS_Index_Backfill::reset_after_recreate();

		$report = WSS_Index_Backfill::report();

		return new WP_REST_Response( [
			'recreated'     => (bool) $result['ok'],
			'old_version'   => (string) $result['old_version'],
			'new_version'   => (string) $result['new_version'],
			'rows_dropped'  => (int) $result['rows_dropped'],
			'schema'        => $report['schema'],
			'state'         => (string) $report['state'],
			'needs_rebuild' => (bool) $report['needs_rebuild'],
		], 200 );
	}

	/**
	 * POST /wss/v1/index/step — exactly one batch of the background worker.
	 *
	 * Meant for diagnostics: with WP-Cron disabled or slow there was no way
	 * to check that the cron path still works. It takes the same lock and
	 * writes the same state as the scheduled run, so a successful answer
	 * proves the chain is healthy. Returns 409 when the batch could not run
	 * (another worker holds the lock, or the browser drives the rebuild).
	 */
	public function handle_index_step( WP_REST_Request $request ) {
		$result = $this->backfill->run( false );
		$report = WSS_Index_Backfill::report();

		if ( 'ran' !== $result && 'done' !== $result ) {
			return new WP_REST_Response( [
				'ran'      => false,
				'reason'   => $result,
				'state'    => $report['state'],
				'retry_in' => 5,
			], 409 );
		}

		return new WP_REST_Response( [
			'ran'                 => true,
			'done'                => (bool) $report['done'],
			'state'               => $report['state'],
			'percent'             => (int) $report['percent'],
			'cursor'              => (int) $report['cursor'],
			'batch'               => (int) $report['batch'],
			'posts_total'         => (int) $report['posts_total'],
			'posts_processed'     => (int) $report['posts_processed'],
			'posts_with_links'    => (int) $report['posts_with_links'],
			'posts_without_links' => (int) $report['posts_without_links'],
			'links_total'         => (int) $report['links_total'],
			'links_written'       => (int) $report['links_written'],
			'links_per_post'      => (float) $report['links_per_post'],
			'missing'             => (int) $report['missing'],
			'errors'              => (int) $report['errors'],
			'cron'                => $report['cron'],
		], 200 );
	}

	/**
	 * GET /wss/v1/tags — terms by slug, by name (substring/prefix) or by post.
	 *
	 * Authorised and limited like /search, with its own 60/min bucket. An
	 * unknown tag is a 200 with an empty list, never a 404.
	 */
	public function handle_tags( WP_REST_Request $request ) {
		$rl = $this->rate_limiter->check( WSS_Config::tags_rate_limit(), 'rl_tags' );
		$this->rate_limiter->send_headers( $rl );

		if ( empty( $rl['allowed'] ) ) {
			return new WP_Error(
				'wss_rate_limited',
				__( 'Слишком много запросов. Подождите минуту.', 'wp-smart-search' ),
				[ 'status' => 429 ]
			);
		}

		$result = $this->tags->query( [
			'slug'           => $this->param( $request, 'slug' ),
			'search'         => $this->param( $request, 'search' ),
			'prefix'         => $this->param( $request, 'prefix' ),
			'post_id'        => $this->param( $request, 'post_id' ),
			'taxonomy'       => $this->param( $request, 'taxonomy' ),
			'include_counts' => $this->param( $request, 'include_counts' ),
			'page'           => $this->param( $request, 'page' ),
			'per_page'       => $this->param( $request, 'per_page' ),
			'fields'         => $this->param( $request, 'fields' ),
		] );

		if ( is_wp_error( $result ) ) {
			return $this->as_rest_error( $result );
		}

		return new WP_REST_Response( $result, 200 );
	}

	public function handle_me( WP_REST_Request $request ) {
		$user         = wp_get_current_user();
		$can          = current_user_can( WSS_Config::CAPABILITY );
		$multi        = current_user_can( 'edit_others_posts' );
		$all_statuses = WSS_Config::can_search_all_statuses();
		$mode         = WSS_Config::visibility_mode();

		return new WP_REST_Response( [
			'id'                   => (int) $user->ID,
			'name'                 => (string) $user->display_name,
			'roles'                => array_values( (array) $user->roles ),
			'can_search'           => $can,
			'can_filter_by_author' => $multi,
			'allowed_statuses'     => WSS_Config::ALLOWED_STATUSES,
			// shared: posts of every author in these statuses are visible,
			// which is what duplicate checks before publishing rely on.
			'visibility_mode'      => $mode,
			'visible_statuses'     => $all_statuses
				? WSS_Config::ALLOWED_STATUSES
				: ( 'shared' === $mode ? WSS_Config::SHARED_STATUSES : [ 'publish' ] ),
			// Private posts stay with their author (and with editors that
			// also have read_private_posts).
			'private_scope'        => $all_statuses ? 'all' : 'own',
			'max_per_page'         => WSS_Config::POSTS_PER_PAGE_MAX,
			// A broader query stops at these many matched posts; the response
			// then carries posts.truncated = true.
			'max_matched_posts'    => WSS_Config::MAX_MATCHED_POSTS,
			'max_words'            => WSS_Config::MAX_WORDS,
			'max_query_len'        => WSS_Config::MAX_QUERY_LEN,
			'batch_limit'          => WSS_Config::BATCH_MAX,
			'link_fields'          => WSS_Config::LINK_FIELDS,
			'required_link_fields' => WSS_Config::LINK_FIELDS_REQUIRED,
			'plugin_version'       => WSS_VERSION,
			// Update diagnostics: the real basename and what the
			// update_plugins transient holds for our main file.
			'update'               => WSS_GitHub_Updater::diagnostic(),
			// Effective /tags limit and its origin (filter, constant, default):
			// the plugin must refuse before a proxy bucket turns 429 into 503.
			'tags_rate_limit'        => WSS_Config::tags_rate_limit(),
			'tags_rate_limit_source' => WSS_Config::tags_rate_limit_source(),
			'api_version'          => 'v1',
			// redis | none — see WSS_Cache::backend().
			'cache_backend'        => WSS_Cache::backend(),
			// Links index health: an empty index with existing posts means
			// the data was lost and a rebuild is queued.
			'index'                => WSS_Index_Backfill::report(),
			'match_modes'          => WSS_Config::MATCH_MODES,
			'scopes'               => WSS_Config::SCOPES,
		], 200 );
	}
}
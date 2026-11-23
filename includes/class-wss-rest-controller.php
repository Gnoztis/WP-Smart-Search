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
	 *
	 *  No sanitize_callback is set on purpose: REST sanitises BEFORE it
	 *  validates, and sanitize_key( array ) is a TypeError in PHP 8 — an
	 *  array sent to scope/type/match/post_type would answer 500 instead of
	 *  400. The handlers read the raw value (param()) and validate it, so an
	 *  unusable value becomes a wss_* 400 with a readable message.
	 * ============================================================ */

	private function search_args(): array {
		return [
			'q'          => [
				'description'       => __( 'Поисковый запрос (мин. 2 символа); слова ищутся по основам, между словами — И.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'refang'     => [
				'description'       => __( 'Auto-refang (по умолчанию true).', 'wp-smart-search' ),
				'type'              => 'boolean',
				'sanitize_callback' => null,
			],
			'scope'      => [
				'description'       => __( 'posts | links | all (по умолчанию posts).', 'wp-smart-search' ),
				'type'              => 'string',
				'enum'              => WSS_Config::SCOPES,
				'sanitize_callback' => null,
			],
			'type'       => [
				'description'       => __( 'all | tags | posts (по умолчанию posts для API).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'match'      => [
				'description'       => __( 'auto | fuzzy | exact (по умолчанию auto).', 'wp-smart-search' ),
				'type'              => 'string',
				'enum'              => WSS_Config::MATCH_MODES,
				'sanitize_callback' => null,
			],
			'page'       => [
				'description'       => __( 'Страница (1..N).', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => null,
			],
			'per_page'   => [
				'description'       => __( 'Элементов на страницу (1..50; при scheduled_only — до 100).', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => null,
			],
			'scheduled_only' => [
				'description' => __( 'Режим запланированных: status=future, q можно не передавать; автор видит свои, редактор — все.', 'wp-smart-search' ),
			],
			'statuses'   => [
				'description' => __( 'Массив статусов или строка через запятую. Неизвестный статус — 400.', 'wp-smart-search' ),
			],
			'date_range' => [
				'description'       => __( 'any | today | week | month | year', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'date_from'  => [
				'description'       => __( 'С ISO-даты (включительно).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'date_to'    => [
				'description'       => __( 'По ISO-дату (включительно).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'author'     => [
				'description'       => __( 'ID автора (только для edit_others_posts).', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => null,
			],
			'post_type'  => [
				'description'       => __( 'post | page | … (по умолчанию post); неизвестный тип — 400.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'orderby'    => [
				'description'       => __( 'date_desc | date_asc', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
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
				'sanitize_callback' => null,
			],
			'domain'     => [
				'description'       => __( 'Registrable domain (включая поддомены).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'host'       => [
				'description'       => __( 'Точный host (без поддоменов).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'anchor'     => [
				'description'       => __( 'Подстрока в anchor-тексте.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'anchor_match' => [
				'description'       => __( 'Семантика anchor: words (по словам, по умолчанию), exact или substring.', 'wp-smart-search' ),
				'type'              => 'string',
				'enum'              => WSS_Config::ANCHOR_MATCH_MODES,
				'sanitize_callback' => null,
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
				'sanitize_callback' => null,
			],
		];
	}

	/**
	 * Arguments of GET|POST /tags.
	 *
	 * Deliberately no `type`/`sanitize_callback` on slug and post_id: REST
	 * sanitises before it validates, so `sanitize_text_field( array )` would
	 * turn a JSON array into "" and `absint( 'abc' )` into 0 — both would
	 * silently become "no filter" and answer with the whole taxonomy. The
	 * values are validated in WSS_Tags instead, where a bad value is a 400
	 * with a wss_* code.
	 */
	private function tags_args(): array {
		return [
			'slug'           => [
				'description' => __( 'Слаг или список слагов через запятую (до 50), либо JSON-массив строк.', 'wp-smart-search' ),
			],
			'search'         => [
				'description'       => __( 'Подстрока имени, минимум 3 символа, регистр не важен.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'prefix'         => [
				'description'       => __( 'Имя начинается с подстроки, минимум 2 символа.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'post_id'        => [
				'description' => __( 'Вернуть метки указанной записи; только положительное целое.', 'wp-smart-search' ),
			],
			'taxonomy'       => [
				'description'       => __( 'post_tag (по умолчанию), category или any.', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'include_counts' => [
				'description'       => __( 'Вернуть count (число постов), по умолчанию true.', 'wp-smart-search' ),
				'type'              => 'boolean',
				'sanitize_callback' => null,
			],
			'page'           => [
				'description'       => __( 'Страница, с 1.', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => null,
			],
			'per_page'       => [
				'description'       => __( 'Элементов на страницу, 1..100, по умолчанию 20.', 'wp-smart-search' ),
				'type'              => 'integer',
				'sanitize_callback' => null,
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
				'sanitize_callback' => null,
			],
			'post_type'  => [
				'description'       => __( 'Тип записи (по умолчанию post).', 'wp-smart-search' ),
				'type'              => 'string',
				'sanitize_callback' => null,
			],
			'statuses'   => [
				'description' => __( 'Массив статусов или строка через запятую.', 'wp-smart-search' ),
			],
			'anchor_match' => [
				'description'       => __( 'Семантика anchors: words (по словам, по умолчанию), exact или substring.', 'wp-smart-search' ),
				'type'              => 'string',
				'enum'              => WSS_Config::ANCHOR_MATCH_MODES,
				'sanitize_callback' => null,
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

		// A narrowing filter that is present but null/empty would silently
		// mean "no filter" — author: null used to search every author,
		// date_from: null every date. Same matrix as /tags.
		$empty = $this->reject_empty_filters( $request, [
			'author'     => 'wss_invalid_author',
			'post_id'    => 'wss_invalid_post_id',
			'date_from'  => 'wss_invalid_date',
			'date_to'    => 'wss_invalid_date',
			'type'       => 'wss_invalid_type',
			'match'      => 'wss_invalid_match',
			'scope'      => 'wss_invalid_scope',
			'post_type'  => 'wss_invalid_post_type',
			'exclude'    => 'wss_invalid_exclude',
			'exclude_id' => 'wss_invalid_exclude',
			'statuses'   => 'wss_invalid_statuses',
		] );
		if ( $empty ) {
			return $empty;
		}

		$q_raw = $this->param( $request, 'q' );
		$q_str = is_string( $q_raw ) ? trim( (string) $q_raw ) : '';

		// scheduled_only is a mode: it selects status=future and makes the
		// text query optional (the client enumerates the scheduled queue).
		$scheduled_only = $this->bool_param( $request, 'scheduled_only' );
		if ( is_wp_error( $scheduled_only ) ) {
			return $scheduled_only;
		}

		// scope is a mode with a documented default, but only when it is
		// absent. A present value that cannot be applied is a 400: the old
		// code coerced it to "posts" before the engine saw it, so
		// scope=garbage answered 200 and searched posts while the client
		// believed it searched links.
		if ( ! $this->has_param( $request, 'scope' ) ) {
			$scope = 'posts';
		} else {
			$raw_scope = $this->param( $request, 'scope' );

			if ( ! is_string( $raw_scope ) || '' === trim( $raw_scope ) ) {
				return new WP_Error(
					'wss_invalid_scope',
					sprintf(
						/* translators: %s: allowed scopes */
						__( 'Недопустимый scope. Разрешены: %s.', 'wp-smart-search' ),
						implode( ', ', WSS_Config::SCOPES )
					),
					[ 'status' => 400 ]
				);
			}

			$scope = sanitize_key( $raw_scope );

			if ( ! in_array( $scope, WSS_Config::SCOPES, true ) ) {
				return new WP_Error(
					'wss_invalid_scope',
					sprintf(
						/* translators: 1: given scope, 2: allowed scopes */
						__( 'Недопустимый scope: %1$s. Разрешены: %2$s.', 'wp-smart-search' ),
						$scope,
						implode( ', ', WSS_Config::SCOPES )
					),
					[ 'status' => 400 ]
				);
			}
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

		if ( $q_str === '' && ! $has_link_filter && ! $scheduled_only ) {
			return new WP_Error( 'wss_empty', __( 'Параметр q не может быть пустым.', 'wp-smart-search' ), [ 'status' => 400 ] );
		}

		if ( mb_strlen( $q_str ) < WSS_Config::MIN_LEN && ! $has_link_filter && ! $scheduled_only ) {
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

		// Only parameters the client actually sent reach the engine. A
		// placeholder for an absent filter (author => 0, date_from => '') used
		// to look like a value — and after the strict validation like a bad
		// one: an absent author must stay absent, not become 0.
		$input = [];

		foreach (
			[
				'q', 'refang', 'scope', 'type', 'match', 'page', 'per_page',
				'statuses', 'date_range', 'date_from', 'date_to', 'author',
				'post_type', 'orderby', 'exclude', 'exclude_id', 'anchor_match',
				'anchor_kind', 'post_id', 'fields', 'exact_total',
			] as $key
		) {
			if ( $this->has_param( $request, $key ) ) {
				$input[ $key ] = $this->param( $request, $key );
			}
		}

		$input['query'] = $q_str;
		$input['url']   = $url_p;
		$input['domain'] = $domain_p;
		$input['host']  = $host_p;
		$input['anchor'] = $anchor_p;

		// Contract defaults for modes, not filters: the documented API default
		// for `type` is "posts", and per_page defaults to the maximum page.
		// Everything else (statuses, date_range, post_type, orderby, page,
		// match, anchor_match) keeps the engine's own default when absent.
		if ( ! array_key_exists( 'type', $input ) ) {
			$input['type'] = 'posts';
		}

		$input['scheduled_only'] = $scheduled_only;

		if ( $scheduled_only ) {
			// The mode owns the status filter: future only.
			$input['statuses'] = [ 'future' ];

			if ( ! array_key_exists( 'per_page', $input ) ) {
				$input['per_page'] = WSS_Config::SCHEDULED_PER_PAGE_MAX;
			}

			// Only posts the user could edit: an author sees their own
			// scheduled queue, an editor (edit_others_posts) sees everyone's.
			if ( ! current_user_can( 'edit_others_posts' ) ) {
				$input['author'] = get_current_user_id();
			}
		}

		if ( ! array_key_exists( 'per_page', $input ) ) {
			$input['per_page'] = WSS_Config::POSTS_PER_PAGE_MAX;
		}

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
	 * Whether the request carries the parameter at all.
	 *
	 * `{"slug": null}` is a *present* parameter: isset() would say otherwise
	 * and the request would silently become "no filter".
	 *
	 * @param WP_REST_Request $request
	 * @param string          $key
	 */
	private function has_param( WP_REST_Request $request, string $key ): bool {
		foreach ( [ $request->get_query_params(), $request->get_body_params(), $request->get_json_params() ] as $params ) {
			if ( is_array( $params ) && array_key_exists( $key, $params ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Present but empty narrowing filters are a 400.
	 *
	 * The matrix: a filter that narrows the result (slug, search, prefix,
	 * post_id, author, dates, type, match, scope, post_type, exclude) can
	 * never be null or "" — otherwise the request silently means "everything".
	 * Parameters that cannot widen the answer (page, per_page, fields,
	 * include_counts) and modes (taxonomy) keep their documented defaults.
	 *
	 * @param WP_REST_Request $request
	 * @param array<string,string> $codes Parameter => error code.
	 * @return WP_Error|null
	 */
	private function reject_empty_filters( WP_REST_Request $request, array $codes ) {
		foreach ( $codes as $key => $code ) {
			if ( ! $this->has_param( $request, $key ) ) {
				continue;
			}

			$value = $this->param( $request, $key );

			$empty = null === $value
				|| '' === $value
				|| ( is_string( $value ) && '' === trim( $value ) )
				|| ( is_array( $value ) && [] === $value );

			if ( ! $empty ) {
				continue;
			}

			return new WP_Error(
				$code,
				sprintf(
					/* translators: %s: parameter name */
					__( 'Параметр %s передан пустым: фильтр не может означать «всё». Уберите параметр или передайте значение.', 'wp-smart-search' ),
					$key
				),
				[ 'status' => 400 ]
			);
		}

		return null;
	}

	/**
	 * Boolean parameter: absent/null/""/blank keep the default, a value that
	 * is not a boolean is a 400 — a mode never guesses.
	 *
	 * @param WP_REST_Request $request
	 * @param string          $key
	 * @param bool            $default
	 * @return bool|WP_Error
	 */
	private function bool_param( WP_REST_Request $request, string $key, bool $default = false ) {
		if ( ! $this->has_param( $request, $key ) ) {
			return $default;
		}

		$value = $this->param( $request, $key );

		if ( null === $value || '' === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return $default;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) && ( 0 === $value || 1 === $value ) ) {
			return 1 === $value;
		}

		if ( is_string( $value ) ) {
			$clean = strtolower( trim( $value ) );

			if ( in_array( $clean, [ '1', 'true', 'on', 'yes' ], true ) ) {
				return true;
			}
			if ( in_array( $clean, [ '0', 'false', 'off', 'no' ], true ) ) {
				return false;
			}
		}

		return new WP_Error(
			'wss_invalid_scheduled_only',
			sprintf(
				/* translators: %s: parameter name */
				__( 'Параметр %s должен быть true или false.', 'wp-smart-search' ),
				$key
			),
			[ 'status' => 400 ]
		);
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

		$empty = $this->reject_empty_filters( $request, [
			'author'     => 'wss_invalid_author',
			'date_from'  => 'wss_invalid_date',
			'date_to'    => 'wss_invalid_date',
			'type'       => 'wss_invalid_type',
			'match'      => 'wss_invalid_match',
			'scope'      => 'wss_invalid_scope',
			'post_type'  => 'wss_invalid_post_type',
			'exclude'    => 'wss_invalid_exclude',
			'exclude_id' => 'wss_invalid_exclude',
			'statuses'   => 'wss_invalid_statuses',
		] );
		if ( $empty ) {
			return $empty;
		}

		$map     = [ 'domains' => 'domain', 'hosts' => 'host', 'urls' => 'url', 'anchors' => 'anchor' ];
		$lookups = [];
		$total   = 0;

		foreach ( $map as $param => $type ) {
			if ( ! $this->has_param( $request, $param ) ) {
				continue;
			}

			$values = $this->param( $request, $param );

			// Present but not a list: silently skipping it would turn the
			// parameter into "no filter" (the same trap as slug: null).
			if ( ! is_array( $values ) ) {
				return new WP_Error(
					'wss_invalid_lookup',
					sprintf(
						/* translators: %s: parameter name */
						__( 'Параметр %s должен быть массивом строк.', 'wp-smart-search' ),
						$param
					),
					[ 'status' => 400 ]
				);
			}

			foreach ( $values as $value ) {
				if ( ! is_string( $value ) ) {
					return new WP_Error(
						'wss_invalid_lookup',
						sprintf(
							/* translators: %s: parameter name */
							__( 'Параметр %s должен содержать только строки.', 'wp-smart-search' ),
							$param
						),
						[ 'status' => 400 ]
					);
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

		// Same rule as /search: only what the client sent. The engine keeps its
		// own defaults for post_type/statuses/anchor_match/per_page.
		$input = [];

		foreach ( [ 'per_page', 'post_type', 'statuses', 'fields', 'anchor_match', 'anchor_kind' ] as $key ) {
			if ( $this->has_param( $request, $key ) ) {
				$input[ $key ] = $this->param( $request, $key );
			}
		}

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

		// An unknown parameter is a 400, not something to ignore: a client
		// sending `slugs=` (plural) must be told it is wrong, otherwise the
		// request silently becomes "no filter" and answers with every term.
		$unknown = $this->unknown_params( $request );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'wss_unknown_param',
				sprintf(
					/* translators: 1: unknown parameters, 2: allowed parameters */
					__( 'Неизвестные параметры: %1$s. Допустимые: %2$s (плюс служебные WordPress: _fields, _embed, _locale, _envelope, context, nonce).', 'wp-smart-search' ),
					implode( ', ', $unknown ),
					implode( ', ', WSS_Config::TAGS_PARAMS )
				),
				[ 'status' => 400 ]
			);
		}

		// Only the keys the request actually carries: WSS_Tags decides
		// presence with array_key_exists(), so a null slug must reach it.
		$input = [];
		foreach ( [ 'slug', 'search', 'prefix', 'post_id', 'taxonomy', 'include_counts', 'page', 'per_page', 'fields' ] as $key ) {
			if ( $this->has_param( $request, $key ) ) {
				$input[ $key ] = $this->param( $request, $key );
			}
		}

		$result = $this->tags->query( $input );

		if ( is_wp_error( $result ) ) {
			return $this->as_rest_error( $result );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Parameters of a /tags request that the endpoint does not declare.
	 *
	 * WordPress-internal parameters (_fields, _embed, _locale, _envelope,
	 * context, nonce) are tolerated; everything else is a client bug.
	 *
	 * @return string[]
	 */
	private function unknown_params( WP_REST_Request $request ): array {
		$known = array_merge( WSS_Config::TAGS_PARAMS, WSS_Config::TAGS_INTERNAL_PARAMS );
		$given = [];

		foreach ( [ $request->get_query_params(), $request->get_body_params(), $request->get_json_params() ] as $params ) {
			if ( is_array( $params ) ) {
				$given = array_merge( $given, array_keys( $params ) );
			}
		}

		$given = array_values( array_unique( array_map( 'strval', $given ) ) );

		return array_values( array_diff( $given, $known ) );
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
			// The author filter is supported for every user with can_search:
			// it narrows within the posts that user can already see. The
			// "of others" nuance is covered by visibility_mode/private_scope,
			// so this flag must not be false for an author-level account.
			'can_filter_by_author' => $can,
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
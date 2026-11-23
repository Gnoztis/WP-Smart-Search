<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Search_Engine {

	private WSS_Cache $cache;

	public function __construct( WSS_Cache $cache ) {
		$this->cache = $cache;
	}

	/* ============================================================
	 *  Entry point
	 * ============================================================ */

	public function do_search( array $input, bool $is_api ) {
		$raw = isset( $input['query'] ) ? (string) $input['query'] : '';

		$refang = $this->to_bool( $input['refang'] ?? null, true );
		if ( $refang && $raw !== '' ) {
			$raw = WSS_Refang::refang_query( $raw );
		}

		// A parameter that is present but unusable is a 400, never "no
		// filter": `type=bogus` must not quietly become "all", a bad page is
		// not page 1, and post_id=abc must not search every post. Admin
		// (AJAX) callers keep the lenient fallback — the panel sends
		// whatever the form holds.
		$type = $this->enum_param( $input, 'type', [ 'all', 'tags', 'posts' ], 'all', 'wss_invalid_type', $is_api );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$match = $this->enum_param( $input, 'match', WSS_Config::MATCH_MODES, 'auto', 'wss_invalid_match', $is_api );
		if ( is_wp_error( $match ) ) {
			return $match;
		}

		$scope = $this->enum_param( $input, 'scope', WSS_Config::SCOPES, WSS_Config::SCOPE_POSTS, 'wss_invalid_scope', $is_api );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$page = $this->int_param( $input, 'page', 1, 'wss_invalid_page', $is_api );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		if ( $is_api ) {
			$post_id_check = $this->post_id_filter( $input['post_id'] ?? null, true );
			if ( is_wp_error( $post_id_check ) ) {
				return $post_id_check;
			}
		}

		// A link query may have no words at all: "all links of these posts"
		// (post_id) and "links of a kind" (anchor_kind) are complete queries.
		$has_link_selector = ( null !== $this->post_id_filter( $input['post_id'] ?? null ) )
			|| ( null !== $this->anchor_kind_filter( $input['anchor_kind'] ?? null, false ) );

		$parsed = $this->parse_query( $raw );
		if ( is_wp_error( $parsed ) ) {
			if ( WSS_Config::SCOPE_POSTS === $scope || ! $has_link_selector ) {
				return $parsed;
			}
			$parsed = [
				'words'   => [],
				'tokens'  => 0,
				'dropped' => 0,
			];
		}

		$words = $parsed['words'];

		// The stem, not the whole word: "sandboxes" and "sandbox" must
		// find each other.
		$terms = WSS_Morphology::terms( $words );
		if ( empty( $terms ) && ! ( WSS_Config::SCOPE_POSTS !== $scope && $has_link_selector ) ) {
			return new WP_Error( 'invalid', __( 'Не удалось разобрать запрос.', 'wp-smart-search' ) );
		}

		$filters = $this->parse_filters( $input, $is_api );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		$filters['query'] = $raw;

		$per_page = $this->int_param( $input, 'per_page', WSS_Config::POSTS_PER_PAGE, 'wss_invalid_per_page', $is_api );
		if ( is_wp_error( $per_page ) ) {
			return $per_page;
		}
		$per_page = min( WSS_Config::POSTS_PER_PAGE_MAX, $per_page );

		$patterns = $this->build_word_patterns( $terms );

		$payload = [
			'query'  => $raw,
			'refang' => $refang,
			'scope'  => $scope,
			'meta'   => [
				'plugin_version' => WSS_VERSION,
				'terms'          => array_values( $terms ),
				'tokens'         => $parsed['tokens'],
				'dropped_tokens' => $parsed['dropped'],
				'max_words'      => WSS_Config::MAX_WORDS,
			],
		];

		if ( WSS_Config::SCOPE_LINKS !== $scope && ! empty( $terms ) ) {
			$payload['type']  = $type;
			$payload['match'] = $match;

			if ( $type === 'all' || $type === 'tags' ) {
				$payload['tags'] = $this->search_tags( $terms );
			}
			if ( $type === 'all' || $type === 'posts' ) {
				$payload['posts'] = $this->search_posts(
					$terms,
					$patterns,
					$page,
					$per_page,
					$filters,
					$is_api,
					$match
				);
			}
		}

		if ( $scope === WSS_Config::SCOPE_LINKS || $scope === WSS_Config::SCOPE_ALL ) {
			$links = $this->search_links( $terms, $input, $filters, $is_api );
			if ( is_wp_error( $links ) ) {
				return $links;
			}
			$payload['links'] = $links;

			// Link filters (post_id, anchor_mode, anchor kinds) are part of
			// the answer's meta: the client must see what was actually asked.
			if ( isset( $links['meta'] ) && is_array( $links['meta'] ) ) {
				foreach ( $links['meta'] as $key => $value ) {
					if ( ( is_array( $value ) && ! empty( $value ) ) || ( is_string( $value ) && $value !== '' ) ) {
						$payload['meta'][ $key ] = $value;
					}
				}
			}
		}

		return $payload;
	}

	/**
	 * Batch link search: several domains/hosts/URLs/anchors in one
	 * HTTP request. Keeps a 47-domain audit from turning into 47
	 * requests and hitting PHP-FPM limits (503).
	 *
	 * @param array<int,array<string,string>> $lookups Each element is one
	 *        of ['domain'=>…], ['host'=>…], ['url'=>…], ['anchor'=>…].
	 * @return array|WP_Error
	 */
	public function do_links_batch( array $lookups, array $base_input, bool $is_api ) {
		$filters = $this->parse_filters( $base_input, $is_api );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		$filters['query'] = '';

		$per_page = $this->int_param( $base_input, 'per_page', 5, 'wss_invalid_per_page', $is_api );
		if ( is_wp_error( $per_page ) ) {
			return $per_page;
		}
		$per_page = min( WSS_Config::POSTS_PER_PAGE_MAX, $per_page );

		$clean = $base_input;
		foreach ( [ 'url', 'domain', 'host', 'anchor' ] as $key ) {
			unset( $clean[ $key ] );
		}

		$results = [];
		foreach ( $lookups as $lookup ) {
			$input = array_merge(
				$clean,
				$lookup,
				[
					'page'        => 1,
					'per_page'    => $per_page,
					'exact_total' => false,
				]
			);

			$result = $this->search_links( [], $input, $filters, $is_api );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result['lookup'] = $lookup;
			$results[]        = $result;
		}

		return [
			'results' => $results,
			'meta'    => [
				'count'       => count( $results ),
				'per_page'    => $per_page,
				'total_exact' => false,
			],
		];
	}

	/* ============================================================
	 *  Utilities
	 * ============================================================ */

	private function to_bool( $value, bool $default = false ): bool {
		if ( null === $value || '' === $value ) {
			return $default;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value !== 0;
		}
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), [ '0', 'false', 'no', 'off' ], true );
		}
		return (bool) $value;
	}

	private function parse_filters( array $input, bool $is_api ) {
		$statuses_raw = $input['statuses'] ?? [];
		if ( is_string( $statuses_raw ) ) {
			$statuses_raw = array_map( 'trim', explode( ',', $statuses_raw ) );
		}
		if ( ! is_array( $statuses_raw ) ) {
			return new WP_Error(
				'wss_invalid_statuses',
				__( 'Параметр statuses должен быть массивом или списком через запятую.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}

		$statuses = [];
		$invalid  = [];
		foreach ( $statuses_raw as $status ) {
			if ( ! is_string( $status ) && ! is_int( $status ) ) {
				$invalid[] = '?';
				continue;
			}
			$status = sanitize_key( (string) $status );
			if ( $status === '' ) {
				continue;
			}
			if ( in_array( $status, WSS_Config::ALLOWED_STATUSES, true ) ) {
				$statuses[] = $status;
			} else {
				$invalid[] = $status;
			}
		}
		$statuses = array_values( array_unique( $statuses ) );

		// In the API an unknown status is a client error, not a silent
		// fallback to all statuses. In the admin (AJAX) we ignore junk.
		if ( ! empty( $invalid ) && $is_api ) {
			return new WP_Error(
				'wss_invalid_statuses',
				sprintf(
					/* translators: 1: given statuses, 2: allowed statuses */
					__( 'Недопустимые статусы: %1$s. Разрешены: %2$s.', 'wp-smart-search' ),
					implode( ', ', array_unique( $invalid ) ),
					implode( ', ', WSS_Config::ALLOWED_STATUSES )
				),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $statuses ) ) {
			$statuses = WSS_Config::ALLOWED_STATUSES;
		}

		$date_range = isset( $input['date_range'] ) ? $input['date_range'] : 'any';
		if ( $is_api && ! is_string( $date_range ) ) {
			return new WP_Error(
				'wss_invalid_date_range',
				__( 'date_range должен быть строкой: any, today, week, month или year.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}
		$date_range = sanitize_key( (string) $date_range );
		if ( ! in_array( $date_range, [ 'any', 'today', 'week', 'month', 'year' ], true ) ) {
			if ( $is_api ) {
				return new WP_Error(
					'wss_invalid_date_range',
					sprintf(
						/* translators: %s: given value */
						__( 'Недопустимый date_range: %s. Разрешены: any, today, week, month, year.', 'wp-smart-search' ),
						$date_range
					),
					[ 'status' => 400 ]
				);
			}
			$date_range = 'any';
		}

		// The author filter only narrows the result set; visibility is
		// enforced by status_scope(), so it is safe for every user.
		$author = $this->int_param( $input, 'author', 0, 'wss_invalid_author', $is_api );
		if ( is_wp_error( $author ) ) {
			return $author;
		}

		$orderby = isset( $input['orderby'] ) ? $input['orderby'] : 'date_desc';
		if ( $is_api && ! is_string( $orderby ) ) {
			return new WP_Error(
				'wss_invalid_orderby',
				__( 'orderby должен быть строкой: date_desc или date_asc.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}
		$orderby = sanitize_key( (string) $orderby );
		if ( ! in_array( $orderby, [ 'date_desc', 'date_asc' ], true ) ) {
			if ( $is_api ) {
				return new WP_Error(
					'wss_invalid_orderby',
					sprintf(
						/* translators: %s: given value */
						__( 'Недопустимый orderby: %s. Разрешены: date_desc, date_asc.', 'wp-smart-search' ),
						$orderby
					),
					[ 'status' => 400 ]
				);
			}
			$orderby = 'date_desc';
		}

		$post_type = isset( $input['post_type'] ) ? $input['post_type'] : 'post';
		if ( $is_api && ! is_string( $post_type ) ) {
			return new WP_Error(
				'wss_invalid_post_type',
				__( 'post_type должен быть строкой.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}
		$post_type = sanitize_key( (string) $post_type );
		if ( $post_type === '' ) {
			$post_type = 'post';
		}
		if ( $is_api && ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'wss_invalid_post_type',
				sprintf(
					/* translators: %s: post type name */
					__( 'Неизвестный тип записи: %s.', 'wp-smart-search' ),
					$post_type
				),
				[ 'status' => 400 ]
			);
		}

		$exclude = [];
		foreach ( [ 'exclude', 'exclude_id' ] as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$ids = is_array( $input[ $key ] ) ? $input[ $key ] : [ $input[ $key ] ];
			foreach ( $ids as $id ) {
				if ( is_bool( $id ) || is_array( $id ) || ! is_scalar( $id )
					|| ! preg_match( '/^[0-9]+$/', trim( (string) $id ) ) ) {
					// An unusable exclude would silently stop excluding.
					if ( $is_api ) {
						return new WP_Error(
							'wss_invalid_exclude',
							sprintf(
								/* translators: %s: parameter name */
								__( 'Параметр %s должен содержать только целые id записей.', 'wp-smart-search' ),
								$key
							),
							[ 'status' => 400 ]
						);
					}
					continue;
				}
				$id = (int) $id;
				if ( $id > 0 ) {
					$exclude[] = $id;
				}
			}
		}
		$exclude = array_values( array_unique( $exclude ) );
		if ( count( $exclude ) > WSS_Config::MAX_EXCLUDE ) {
			$exclude = array_slice( $exclude, 0, WSS_Config::MAX_EXCLUDE );
		}

		// An unparseable date is not "any date": the filter would silently
		// disappear and answer with the whole archive.
		$date_from = isset( $input['date_from'] ) && is_string( $input['date_from'] ) ? trim( $input['date_from'] ) : '';
		$date_to   = isset( $input['date_to'] ) && is_string( $input['date_to'] ) ? trim( $input['date_to'] ) : '';

		if ( $is_api ) {
			foreach ( [ 'date_from' => $date_from, 'date_to' => $date_to ] as $key => $value ) {
				if ( $value !== '' && $this->parse_date_boundary( $value, 'date_to' === $key ) <= 0 ) {
					return new WP_Error(
						'wss_invalid_date',
						sprintf(
							/* translators: %s: parameter name */
							__( 'Некорректная дата в %s: нужен формат YYYY-MM-DD или YYYY-MM-DDTHH:MM.', 'wp-smart-search' ),
							$key
						),
						[ 'status' => 400 ]
					);
				}
			}
			if ( $date_from !== '' && $date_to !== '' && $this->parse_date_boundary( $date_from, false ) > $this->parse_date_boundary( $date_to, true ) ) {
				return new WP_Error(
					'wss_invalid_date',
					__( 'date_from позже date_to.', 'wp-smart-search' ),
					[ 'status' => 400 ]
				);
			}
		}

		// Honoured only when the match list had to be cut: the engine then
		// either counts the matches separately or reports the list size
		// (see search_posts()).
		$exact_total = ! isset( $input['exact_total'] ) || $this->to_bool( $input['exact_total'], true );

		return [
			'statuses'    => $statuses,
			'date_range'  => $date_range,
			'author'      => $author,
			'orderby'     => $orderby,
			'exclude'     => $exclude,
			'post_type'   => $post_type,
			'date_from'   => $date_from,
			'date_to'     => $date_to,
			'exact_total' => $exact_total,
		];
	}

	/* ============================================================
	 *  Query parsing
	 * ============================================================ */

	private function parse_query( string $raw ) {
		$raw = trim( sanitize_text_field( $raw ) );

		if ( mb_strlen( $raw ) > WSS_Config::MAX_QUERY_LEN ) {
			$raw = mb_substr( $raw, 0, WSS_Config::MAX_QUERY_LEN );
		}
		if ( $raw === '' ) {
			return new WP_Error( 'empty', __( 'Введите не менее 2 символов.', 'wp-smart-search' ) );
		}
		if ( mb_strlen( $raw ) < WSS_Config::MIN_LEN ) {
			if ( preg_match( '/^[\p{L}\p{N}]$/u', $raw ) ) {
				return new WP_Error( 'too_short', __( 'Введите не менее 2 символов.', 'wp-smart-search' ) );
			}
		}

		$parts = preg_split( '/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return new WP_Error( 'empty', __( 'Некорректный запрос.', 'wp-smart-search' ) );
		}

		// How many words the user sent and how many were dropped by
		// the limit — the client must know the query was truncated instead
		// of silently getting narrower results.
		$tokens  = count( $parts );
		$parts   = array_slice( $parts, 0, WSS_Config::MAX_WORDS );
		$dropped = max( 0, $tokens - count( $parts ) );

		$token_re = '/^' . WSS_Config::TOKEN_CHARS . '+$/u';
		$words    = [];
		foreach ( $parts as $p ) {
			if ( preg_match( $token_re, $p ) ) {
				$words[] = $p;
			}
		}

		if ( empty( $words ) ) {
			return new WP_Error(
				'invalid',
				__( 'Не удалось разобрать запрос. Используйте буквы, цифры и символы . - + _ * @ : / ? # & = % ~ \' !', 'wp-smart-search' )
			);
		}

		return [
			'words'   => $words,
			'tokens'  => $tokens,
			'dropped' => $dropped,
		];
	}

	/* ============================================================
	 *  Matching
	 * ============================================================ */

	private function build_word_patterns( array $terms ): array {
		$patterns = [];
		foreach ( $terms as $term ) {
			$patterns[] = WSS_Morphology::pattern( (string) $term );
		}
		return $patterns;
	}

	/**
	 * Status restriction based on the current user's capabilities and on the
	 * configured visibility model (WSS_Config::visibility_mode()).
	 *
	 * shared (default): every user with the search capability sees posts in
	 * WSS_Config::SHARED_STATUSES by any author — draft and future posts
	 * included — because duplicates are checked before publishing. Private
	 * posts remain visible to their author and to editors only.
	 *
	 * own: published posts plus the user's own posts in other statuses.
	 *
	 * Private posts of other authors are only reachable for users with
	 * both edit_others_posts and read_private_posts — see
	 * WSS_Config::can_search_all_statuses().
	 *
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	private function status_scope( array $filters ): array {
		$statuses = $filters['statuses'];
		$uid      = (int) get_current_user_id();

		if ( $uid > 0 && WSS_Config::can_search_all_statuses() ) {
			$ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			return [
				'sql'    => "p.post_status IN ($ph)",
				'params' => array_values( $statuses ),
			];
		}

		if ( $uid <= 0 ) {
			$statuses = array_values( array_intersect( $statuses, [ 'publish' ] ) );
		}

		// Published posts are public in every mode; the rest of the shared
		// statuses only in the shared model.
		$shared = array_values( array_intersect( $statuses, [ 'publish' ] ) );
		if ( 'shared' === WSS_Config::visibility_mode() ) {
			$shared = array_values( array_intersect( $statuses, WSS_Config::SHARED_STATUSES ) );
		}

		$own    = array_values( array_diff( $statuses, $shared ) );
		$parts  = [];
		$params = [];

		if ( ! empty( $shared ) ) {
			$ph      = implode( ',', array_fill( 0, count( $shared ), '%s' ) );
			$parts[] = "p.post_status IN ($ph)";
			foreach ( $shared as $status ) {
				$params[] = $status;
			}
		}
		if ( $uid > 0 && ! empty( $own ) ) {
			$ph       = implode( ',', array_fill( 0, count( $own ), '%s' ) );
			$parts[]  = "(p.post_author = %d AND p.post_status IN ($ph))";
			$params[] = $uid;
			foreach ( $own as $status ) {
				$params[] = $status;
			}
		}
		if ( empty( $parts ) ) {
			$parts[] = '1=0';
		}

		return [
			'sql'    => '(' . implode( ' OR ', $parts ) . ')',
			'params' => $params,
		];
	}

	private function matches_all_words( string $haystack, array $patterns ): bool {
		foreach ( $patterns as $p ) {
			if ( ! preg_match( $p, $haystack ) ) {
				return false;
			}
		}
		return true;
	}

	private function normalize_hyphens( string $s ): string {
		return preg_replace( '/[\x{2010}-\x{2015}\x{2212}]/u', '-', $s );
	}

	private function tag_matches_all_words( string $haystack, array $words ): bool {
		$haystack_n = $this->normalize_hyphens( $haystack );
		foreach ( $words as $w ) {
			$w_n = $this->normalize_hyphens( $w );
			if ( mb_stripos( $haystack_n, $w_n ) === false ) {
				return false;
			}
		}
		return true;
	}

	private function extract_urls_from_html( string $html ): string {
		if ( $html === '' || strpos( $html, '=' ) === false ) {
			return '';
		}
		if ( ! preg_match_all( '/\b(?:href|src|data-src)\s*=\s*(["\'])(.+?)\1/is', $html, $m ) ) {
			return '';
		}
		$urls = [];
		foreach ( $m[2] as $u ) {
			$u = html_entity_decode( (string) $u, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$u = trim( $u );
			if ( $u !== '' && ! isset( $urls[ $u ] ) ) {
				$urls[ $u ] = true;
			}
		}
		return empty( $urls ) ? '' : implode( ' ', array_keys( $urls ) );
	}

	/* ============================================================
	 *  Tags
	 * ============================================================ */

	private function search_tags( array $words ): array {
		$cache_key = 'wss_tags_' . WSS_Config::TAGS_CACHE_VER . '_'
			. $this->cache->generation() . '_'
			. md5( implode( '|', $words ) );

		$cached = $this->cache->get( $cache_key );
		if ( $cached !== null ) {
			return $cached;
		}

		global $wpdb;

		$where  = [];
		$params = [];

		foreach ( $words as $w ) {
			$like     = '%' . $wpdb->esc_like( $w ) . '%';
			$where[]  = '(t.name LIKE %s OR t.slug LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$sql = "SELECT t.term_id, t.name, t.slug, tt.count
			FROM {$wpdb->terms} AS t
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id
			WHERE tt.taxonomy = 'post_tag'
			  AND " . implode( ' AND ', $where ) . "
			ORDER BY tt.count DESC, t.name ASC
			LIMIT " . (int) WSS_Config::TAGS_SCAN;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$haystack = $row->name . ' ' . $row->slug;
			if ( ! $this->tag_matches_all_words( $haystack, $words ) ) {
				continue;
			}
			$out[] = [
				'id'    => (int) $row->term_id,
				'name'  => (string) $row->name,
				'slug'  => (string) $row->slug,
				'count' => (int) $row->count,
				'url'   => admin_url( 'edit.php?tag=' . rawurlencode( (string) $row->slug ) ),
			];
			if ( count( $out ) >= WSS_Config::TAGS_OUT ) {
				break;
			}
		}

		$this->cache->set( $cache_key, $out, WSS_Config::TAGS_CACHE_TTL );
		return $out;
	}

	/* ============================================================
	 *  Posts: chunked match list + cache + slice
	 * ============================================================ */

	private function search_posts(
		array $words,
		array $patterns,
		int $page,
		int $per_page,
		array $filters,
		bool $is_api,
		string $match
	): array {
		// The cache depends on visibility — may see others' posts or not —
		// not on whether the request came via API or AJAX. The visibility
		// mode is part of the key so a switch between shared and own never
		// serves entries built under the other model.
		$scope = WSS_Config::can_search_all_statuses()
			? 'e'
			: 'u' . (int) get_current_user_id();

		$cache_key = 'wss_posts_' . WSS_Config::POSTS_CACHE_VER . '_'
			. $this->cache->generation() . '_'
			. $scope . '_'
			. WSS_Config::visibility_mode() . '_'
			. md5( wp_json_encode( [
				'statuses'   => $filters['statuses'],
				'date_range' => $filters['date_range'],
				'date_from'  => $filters['date_from'] ?? '',
				'date_to'    => $filters['date_to'] ?? '',
				'author'     => $filters['author'],
				'orderby'    => $filters['orderby'],
				'exclude'    => $filters['exclude'],
				'post_type'  => $filters['post_type'] ?? 'post',
				'match'      => $match,
			] ) ) . '_'
			. md5( implode( '|', $words ) );

		$cached = $this->cache->get( $cache_key );
		if ( ! is_array( $cached ) || ! isset( $cached['items'] ) || ! is_array( $cached['items'] ) ) {
			$cached = $this->build_posts_list( $words, $patterns, $filters, $is_api, $match );
			$this->cache->set( $cache_key, $cached, WSS_Config::POSTS_CACHE_TTL );
		}

		$all       = $cached['items'];
		$truncated = ! empty( $cached['truncated'] );

		usort( $all, function ( $a, $b ) use ( $filters ) {
			$ea = ! empty( $a['exact_match'] );
			$eb = ! empty( $b['exact_match'] );
			if ( $ea !== $eb ) {
				return $ea ? -1 : 1;
			}
			if ( ( $a['score'] ?? 0 ) !== ( $b['score'] ?? 0 ) ) {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
			$da = strtotime( (string) ( $a['date_iso'] ?? '' ) );
			$db = strtotime( (string) ( $b['date_iso'] ?? '' ) );
			if ( $da === $db ) {
				return 0;
			}
			return $filters['orderby'] === 'date_asc' ? $da <=> $db : $db <=> $da;
		} );

		// $total is the number of items in the list, except for a cut list:
		// then the client asked for the exact number and gets a COUNT over
		// the same WHERE, so `total` stays meaningful while `truncated`
		// says the list itself stops at WSS_Config::MAX_MATCHED_POSTS.
		$reachable = count( $all );
		$total     = $reachable;
		if ( $truncated && ! empty( $filters['exact_total'] ) ) {
			// A failed COUNT returns 0, which cannot be right here: the list
			// was cut, so at least MAX_MATCHED_POSTS posts matched.
			$counted = $this->count_matching_posts( $this->fuzzy_conditions( $words, $filters ) );
			$total   = $counted > 0 ? $counted : $reachable;
		}

		// Pages cover the items that really exist: with a cut list the last
		// page must not be empty just because `total` is bigger.
		$total_pages = max( 1, (int) ceil( ( $truncated ? $reachable : $total ) / $per_page ) );
		$page        = min( $page, $total_pages );
		$offset      = ( $page - 1 ) * $per_page;
		$items       = array_slice( $all, $offset, $per_page );

		if ( ! empty( $items ) ) {
			$post_ids = array_map( static fn( $item ) => (int) $item['id'], $items );
			$map      = $this->fetch_taxonomies_for_posts( $post_ids );
			foreach ( $items as $i => $item ) {
				$pid = (int) $item['id'];
				if ( isset( $map[ $pid ] ) ) {
					$items[ $i ]['categories'] = $map[ $pid ]['categories'];
					$items[ $i ]['tags']       = $map[ $pid ]['tags'];
				}
			}
		}

		return [
			'items'       => array_values( $items ),
			'total'       => $total,
			'truncated'   => $truncated,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * Match list of the current query.
	 *
	 * Rows are read in batches and formatted one by one, so the peak memory
	 * is one batch of post_content plus the finished items — never the whole
	 * table. The number of matched posts is capped by
	 * WSS_Config::MAX_MATCHED_POSTS; `truncated` tells the caller the list
	 * was cut (and that `total` then needs a separate COUNT).
	 *
	 * @return array{items:array<int,array>,truncated:bool}
	 */
	private function build_posts_list(
		array $words,
		array $patterns,
		array $filters,
		bool $is_api,
		string $match
	): array {
		$q = (string) ( $filters['query'] ?? '' );

		if ( $match === 'exact' ) {
			$exact = $this->find_exact_post( $q, $filters, $is_api );
			return [
				'items'     => $exact ? [ $this->format_post_item( $exact, $words, true ) ] : [],
				'truncated' => false,
			];
		}

		$items = [];
		$seen  = [];

		// An exact permalink match goes first and is never counted against
		// the cap: it is the most relevant item of the query.
		if ( $match === 'auto' ) {
			$exact_post = $this->find_exact_post( $q, $filters, $is_api );
			if ( $exact_post instanceof WP_Post ) {
				$items[]                      = $this->format_post_item( $exact_post, $words, true );
				$seen[ (int) $exact_post->ID ] = true;
			}
		}

		$where     = $this->fuzzy_conditions( $words, $filters );
		$cap       = max( 1, (int) WSS_Config::MAX_MATCHED_POSTS );
		$chunk     = max( 1, (int) WSS_Config::MATCH_CHUNK_SIZE );
		$offset    = 0;
		$matched   = 0;
		$truncated = false;

		while ( true ) {
			$rows    = $this->query_fuzzy_rows( $where, $filters, $chunk, $offset );
			$offset += $chunk;

			foreach ( $rows as $row ) {
				$id = (int) $row->ID;
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				if ( ! $this->matches_all_words( $this->row_haystack( $row ), $patterns ) ) {
					continue;
				}

				$seen[ $id ] = true;
				$items[]     = $this->format_row( $row, $words, false );
				$matched++;

				// The engine stops at the limit instead of probing for one
				// more row, so exactly MAX_MATCHED_POSTS matches also set
				// the flag. `total` stays exact when it is reported.
				if ( $matched >= $cap ) {
					$truncated = true;
					break 2;
				}
			}

			if ( count( $rows ) < $chunk ) {
				break;
			}
		}

		return [
			'items'     => $items,
			'truncated' => $truncated,
		];
	}

	/**
	 * WHERE clause shared by the row batches and the exact COUNT.
	 *
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	private function fuzzy_conditions( array $words, array $filters ): array {
		global $wpdb;

		$where  = [];
		$params = [];

		$scope   = $this->status_scope( $filters );
		$where[] = $scope['sql'];
		$params  = array_merge( $params, $scope['params'] );

		$where[]  = 'p.post_type = %s';
		$params[] = (string) ( $filters['post_type'] ?? 'post' );

		$date_range = $filters['date_range'];
		if ( $date_range !== 'any' ) {
			$now = current_time( 'timestamp' );
			switch ( $date_range ) {
				case 'today': $after_ts = strtotime( 'today', $now ); break;
				case 'week':  $after_ts = strtotime( '-7 days', $now ); break;
				case 'month': $after_ts = strtotime( '-1 month', $now ); break;
				case 'year':  $after_ts = strtotime( '-1 year', $now ); break;
				default:      $after_ts = 0;
			}
			if ( $after_ts > 0 ) {
				$where[]  = 'p.post_date >= %s';
				$params[] = wp_date( 'Y-m-d H:i:s', $after_ts, wp_timezone() );
			}
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$ts = $this->parse_date_boundary( (string) $filters['date_from'], false );
			if ( $ts > 0 ) {
				$where[]  = 'p.post_date >= %s';
				$params[] = wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() );
			}
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$ts = $this->parse_date_boundary( (string) $filters['date_to'], true );
			if ( $ts > 0 ) {
				$where[]  = 'p.post_date <= %s';
				$params[] = wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() );
			}
		}

		if ( $filters['author'] > 0 ) {
			$where[]  = 'p.post_author = %d';
			$params[] = $filters['author'];
		}

		foreach ( $words as $w ) {
			$like     = '%' . $wpdb->esc_like( $w ) . '%';
			$where[]  = '(p.post_title LIKE %s OR p.post_content LIKE %s OR p.post_excerpt LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $filters['exclude'] ) ) {
			$ex_ph   = implode( ',', array_fill( 0, count( $filters['exclude'] ), '%d' ) );
			$where[] = "p.ID NOT IN ($ex_ph)";
			foreach ( $filters['exclude'] as $ex ) {
				$params[] = $ex;
			}
		}

		return [
			'sql'    => implode( ' AND ', $where ),
			'params' => $params,
		];
	}

	/**
	 * One batch of matching rows, ordered by date.
	 *
	 * p.ID is the tiebreaker: rows sharing a post_date need a deterministic
	 * order, otherwise LIMIT/OFFSET can skip or repeat a row between
	 * batches.
	 *
	 * @param array{sql:string,params:array<int,mixed>} $where
	 * @return array<int,object>
	 */
	private function query_fuzzy_rows( array $where, array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$order = $filters['orderby'] === 'date_asc' ? 'ASC' : 'DESC';

		$sql = "SELECT p.ID, p.post_title, p.post_content, p.post_excerpt,
					p.post_status, p.post_date, p.post_author, p.post_type
			FROM {$wpdb->posts} AS p
			WHERE " . $where['sql'] . "
			ORDER BY p.post_date $order, p.ID $order
			LIMIT %d OFFSET %d";

		$params = array_merge( $where['params'], [ $limit, $offset ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Exact number of matching posts — only used when the list was cut.
	 *
	 * @param array{sql:string,params:array<int,mixed>} $where
	 */
	private function count_matching_posts( array $where ): int {
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} AS p WHERE " . $where['sql'];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where['params'] ) );
	}

	/* ============================================================
	 *  Exact match by permalink
	 * ============================================================ */

	private function find_exact_post( string $q, array $filters, bool $is_api ): ?WP_Post {
		$q = trim( $q );
		if ( $q === '' ) {
			return null;
		}

		$id = WSS_Refang::extract_id( $q );
		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post
				&& $post->post_type === ( $filters['post_type'] ?? 'post' )
				&& $this->post_passes_filters( $post, $filters, $is_api )
			) {
				return $post;
			}
			return null;
		}

		$norm_q = WSS_Refang::normalize_url( $q );
		if ( $norm_q === '' ) {
			return null;
		}

		$refanged = WSS_Refang::refang_query( $q );
		$slug     = WSS_Refang::extract_slug( $refanged );

		$candidates = [];

		$lookup_url = $refanged;
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $lookup_url ) ) {
			$lookup_url = 'http://' . $lookup_url;
		}
		$id_from_url = url_to_postid( $lookup_url );
		if ( $id_from_url > 0 ) {
			$candidates[] = (int) $id_from_url;
		}

		if ( $slug !== '' ) {
			$by_path = get_page_by_path( $slug, OBJECT, (string) ( $filters['post_type'] ?? 'post' ) );
			if ( $by_path instanceof WP_Post ) {
				$candidates[] = (int) $by_path->ID;
			}
		}

		if ( $slug !== '' ) {
			$slug_variants = [ $slug ];
			if ( preg_match( '/^(.+?)_(\d+)$/', $slug, $m ) ) {
				$slug_variants[] = $m[1];
			}
			if ( preg_match( '/^(.+?)-(\d+)$/', $slug, $m ) ) {
				$slug_variants[] = $m[1];
			}
			$slug_variants = array_values( array_unique( array_filter( $slug_variants ) ) );

			global $wpdb;
			$sph = implode( ',', array_fill( 0, count( WSS_Config::ALLOWED_STATUSES ), '%s' ) );

			foreach ( $slug_variants as $s ) {
				$sql    = "SELECT ID FROM {$wpdb->posts}
					WHERE post_type = %s
					  AND post_name = %s
					  AND post_status IN ($sph)
					LIMIT 10";
				$params = array_merge(
					[ (string) ( $filters['post_type'] ?? 'post' ), $s ],
					WSS_Config::ALLOWED_STATUSES
				);
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
				foreach ( (array) $ids as $cid ) {
					$candidates[] = (int) $cid;
				}
			}
		}

		$candidates = array_values( array_unique( array_filter( $candidates, static fn( $v ) => $v > 0 ) ) );

		foreach ( $candidates as $cid ) {
			$post = get_post( $cid );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$norm_perma = WSS_Refang::normalize_url( (string) get_permalink( $post->ID ) );
			if ( $norm_perma === '' || $norm_perma !== $norm_q ) {
				continue;
			}
			if ( ! $this->post_passes_filters( $post, $filters, $is_api ) ) {
				continue;
			}
			return $post;
		}

		return null;
	}

	private function post_passes_filters( WP_Post $post, array $filters, bool $is_api ): bool {
		if ( ! in_array( $post->post_status, $filters['statuses'], true ) ) {
			return false;
		}
		if ( in_array( (int) $post->ID, $filters['exclude'], true ) ) {
			return false;
		}
		if ( $filters['author'] > 0 && (int) $post->post_author !== (int) $filters['author'] ) {
			return false;
		}
		if ( ! WSS_Config::can_search_all_statuses() ) {
			$uid = (int) get_current_user_id();
			if ( $uid <= 0 ) {
				return false;
			}

			// Published posts are public; the shared statuses (drafts,
			// scheduled, pending) are visible in the shared model only.
			$shared = 'publish' === $post->post_status
				|| ( 'shared' === WSS_Config::visibility_mode()
					&& in_array( $post->post_status, WSS_Config::SHARED_STATUSES, true ) );

			if ( ! $shared && (int) $post->post_author !== $uid ) {
				return false;
			}
		}
		return true;
	}

	private function wp_post_to_row( WP_Post $post ): object {
		return (object) [
			'ID'           => $post->ID,
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_date'    => $post->post_date,
			'post_author'  => $post->post_author,
			'post_type'    => $post->post_type,
		];
	}

	private function row_haystack( $row ): string {
		$content_raw      = (string) $row->post_content;
		$content_pre      = preg_replace( '/<br\s*\/?>/i', ' ', $content_raw );
		$content_stripped = wp_strip_all_tags( (string) $content_pre );
		$content_decoded  = html_entity_decode( $content_stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$content_urls     = $this->extract_urls_from_html( $content_raw );

		$searchable = $content_decoded;
		if ( $content_urls !== '' ) {
			$searchable .= ' ' . $content_urls;
		}

		$excerpt_stripped = wp_strip_all_tags( (string) $row->post_excerpt );
		$excerpt_decoded  = html_entity_decode( $excerpt_stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return (string) $row->post_title . ' ' . $searchable . ' ' . $excerpt_decoded;
	}

	private function format_post_item( WP_Post $post, array $words, bool $is_exact ): array {
		return $this->format_row( $this->wp_post_to_row( $post ), $words, $is_exact );
	}

	private function format_row( $row, array $words, bool $is_exact ): array {
		$content_raw = (string) $row->post_content;

		$content_pre      = preg_replace( '/<br\s*\/?>/i', ' ', $content_raw );
		$content_stripped = wp_strip_all_tags( (string) $content_pre );
		$content_decoded  = html_entity_decode( $content_stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$content_urls     = $this->extract_urls_from_html( $content_raw );

		$searchable = $content_decoded;
		if ( $content_urls !== '' ) {
			$searchable .= ' ' . $content_urls;
		}

		if ( $is_exact ) {
			$match_type  = 'exact_url';
			$score       = 100;
			$exact_match = true;
		} else {
			$match_type  = 'content';
			$score       = 60;
			$exact_match = false;

			foreach ( $words as $w ) {
				if ( mb_stripos( (string) $row->post_title, $w ) !== false ) {
					$match_type = 'title';
					$score      = 90;
					break;
				}
			}

			if ( $match_type !== 'title' ) {
				$tags = get_the_tags( (int) $row->ID );
				if ( is_array( $tags ) ) {
					foreach ( $tags as $tag ) {
						if ( $this->tag_matches_all_words( $tag->name . ' ' . $tag->slug, $words ) ) {
							$match_type = 'tag';
							$score      = 70;
							break;
						}
					}
				}
			}
		}

		$date_iso = mysql2date( 'c', $row->post_date );

		$author_id     = (int) $row->post_author;
		$author_name   = __( 'Неизвестный автор', 'wp-smart-search' );
		$author_url    = '';
		$author_avatar = '';

		if ( $author_id > 0 ) {
			$author = get_userdata( $author_id );
			if ( $author ) {
				$author_name = $author->display_name !== '' ? $author->display_name : $author->user_login;
			}
			$author_url = admin_url( 'edit.php?author=' . $author_id );
			$avatar     = get_avatar_url( $author_id, [ 'size' => 48 ] );
			if ( is_string( $avatar ) && $avatar !== '' ) {
				$author_avatar = $avatar;
			}
		}

		$permalink = (string) get_permalink( (int) $row->ID );
		$edit      = get_edit_post_link( (int) $row->ID, 'raw' );

		return [
			'id'           => (int) $row->ID,
			'title'        => $row->post_title !== '' ? $row->post_title : __( '(без названия)', 'wp-smart-search' ),
			'status'       => (string) $row->post_status,
			'status_label' => $this->get_status_label( (string) $row->post_status ),
			'date'         => mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->post_date ),
			'date_iso'     => $date_iso,
			'permalink'    => $permalink,
			'url'          => $edit ? $edit : admin_url( 'post.php?post=' . (int) $row->ID . '&action=edit' ),
			'excerpt'      => $this->make_excerpt( $searchable, $words ),
			'match_type'   => $match_type,
			'score'        => $score,
			'exact_match'  => $exact_match,
			'author'       => [
				'id'     => $author_id,
				'name'   => $author_name,
				'url'    => $author_url,
				'avatar' => $author_avatar,
			],
			'categories'   => [],
			'tags'         => [],
			'post_type'    => (string) $row->post_type,
		];
	}

	private function get_status_label( string $status ): string {
		$map = [
			'publish' => __( 'Опубликовано', 'wp-smart-search' ),
			'draft'   => __( 'Черновик', 'wp-smart-search' ),
			'pending' => __( 'На утверждении', 'wp-smart-search' ),
			'private' => __( 'Приватно', 'wp-smart-search' ),
			'future'  => __( 'Запланировано', 'wp-smart-search' ),
		];
		return $map[ $status ] ?? $status;
	}

	private function fetch_taxonomies_for_posts( array $post_ids ): array {
		global $wpdb;

		$post_ids = array_values( array_filter(
			array_map( 'intval', $post_ids ),
			static fn( $v ) => $v > 0
		) );
		if ( empty( $post_ids ) ) {
			return [];
		}

		$ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$sql = "SELECT tr.object_id, tt.taxonomy, t.term_id, t.name, t.slug
			FROM {$wpdb->term_relationships} AS tr
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id
			WHERE tt.taxonomy IN ('post_tag','category')
			  AND tr.object_id IN ($ph)
			ORDER BY tt.taxonomy ASC, t.name ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $post_ids ) );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$map = [];
		foreach ( $rows as $row ) {
			$oid = (int) $row->object_id;
			if ( ! isset( $map[ $oid ] ) ) {
				$map[ $oid ] = [ 'categories' => [], 'tags' => [] ];
			}
			$is_cat = ( $row->taxonomy === 'category' );
			$slug   = (string) $row->slug;
			$item   = [
				'id'   => (int) $row->term_id,
				'name' => (string) $row->name,
				'url'  => $is_cat
					? admin_url( 'edit.php?category_name=' . rawurlencode( $slug ) )
					: admin_url( 'edit.php?tag=' . rawurlencode( $slug ) ),
			];
			if ( $is_cat ) {
				$map[ $oid ]['categories'][] = $item;
			} else {
				$map[ $oid ]['tags'][] = $item;
			}
		}
		return $map;
	}

	private function make_excerpt( string $content, array $words ): string {
		$content = trim( preg_replace( '/\s+/u', ' ', $content ) );
		if ( $content === '' ) {
			return '';
		}
		$len = mb_strlen( $content );
		$pos = false;
		foreach ( $words as $w ) {
			$p = mb_stripos( $content, $w );
			if ( $p !== false && ( $pos === false || $p < $pos ) ) {
				$pos = $p;
			}
		}
		if ( $pos === false ) {
			$pos = 0;
		}
		$start   = max( 0, $pos - 60 );
		$excerpt = mb_substr( $content, $start, 200 );
		if ( $start > 0 ) {
			$excerpt = '…' . $excerpt;
		}
		if ( $start + 200 < $len ) {
			$excerpt .= '…';
		}
		return $excerpt;
	}

	/* ============================================================
	 *  Search links (new scope)
	 * ============================================================ */

	/**
	 * @return array|WP_Error
	 */
	private function search_links( array $words, array $input, array $filters, bool $is_api ) {
		global $wpdb;
		$table = $wpdb->prefix . WSS_Config::LINKS_TABLE;

		$where  = [];
		$params = [];

		/* ---------- Post-level filters ---------- */

		$scope   = $this->status_scope( $filters );
		$where[] = $scope['sql'];
		$params  = array_merge( $params, $scope['params'] );

		$where[]  = 'p.post_type = %s';
		$params[] = (string) ( $filters['post_type'] ?? 'post' );

		// The author filter only narrows the result set; visibility is
		// enforced by status_scope().
		if ( ! empty( $filters['author'] ) && (int) $filters['author'] > 0 ) {
			$where[]  = 'p.post_author = %d';
			$params[] = (int) $filters['author'];
		}

		if ( $filters['date_range'] !== 'any' ) {
			$now      = current_time( 'timestamp' );
			$after_ts = 0;
			switch ( $filters['date_range'] ) {
				case 'today': $after_ts = strtotime( 'today', $now ); break;
				case 'week':  $after_ts = strtotime( '-7 days', $now ); break;
				case 'month': $after_ts = strtotime( '-1 month', $now ); break;
				case 'year':  $after_ts = strtotime( '-1 year', $now ); break;
			}
			if ( $after_ts > 0 ) {
				$where[]  = 'p.post_date >= %s';
				$params[] = wp_date( 'Y-m-d H:i:s', $after_ts, wp_timezone() );
			}
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$ts = $this->parse_date_boundary( (string) $filters['date_from'], false );
			if ( $ts > 0 ) {
				$where[]  = 'p.post_date >= %s';
				$params[] = wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() );
			}
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$ts = $this->parse_date_boundary( (string) $filters['date_to'], true );
			if ( $ts > 0 ) {
				$where[]  = 'p.post_date <= %s';
				$params[] = wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() );
			}
		}

		if ( ! empty( $filters['exclude'] ) ) {
			$eph     = implode( ',', array_fill( 0, count( $filters['exclude'] ), '%d' ) );
			$where[] = "p.ID NOT IN ($eph)";
			foreach ( $filters['exclude'] as $ex ) {
				$params[] = $ex;
			}
		}

		/* ---------- Link-level filters ---------- */

		$match_type     = 'all';
		$anchor_patterns = [];
		$anchor_terms    = [];

		$url_p    = isset( $input['url'] ) && $input['url'] !== '' ? (string) $input['url'] : '';
		$domain_p = isset( $input['domain'] ) && $input['domain'] !== '' ? (string) $input['domain'] : '';
		$host_p   = isset( $input['host'] ) && $input['host'] !== '' ? (string) $input['host'] : '';
		$anchor_p = isset( $input['anchor'] ) && $input['anchor'] !== '' ? (string) $input['anchor'] : '';

		// Links of specific posts: an audit checks one post at a time.
		$post_ids = $this->post_id_filter( $input['post_id'] ?? null, $is_api );
		if ( is_wp_error( $post_ids ) ) {
			return $post_ids;
		}

		if ( $url_p !== '' ) {
			$norm = WSS_Refang::normalize_url( $url_p );
			if ( $norm !== '' ) {
				$where[]    = 'l.url_norm_hash = %s';
				$params[]   = md5( $norm );
				$match_type = 'exact_url';
			}
		}

		if ( $domain_p !== '' ) {
			$d = WSS_Links_Indexer::registrable_domain( $domain_p );
			if ( $d !== '' ) {
				$where[]  = 'l.domain = %s';
				$params[] = $d;
				if ( $match_type === 'all' ) {
					$match_type = 'domain';
				}
			}
		}

		if ( $match_type === 'all' && $host_p !== '' ) {
			$h = strtolower( preg_replace( '/^www\./i', '', trim( $host_p ) ) );
			if ( $h !== '' ) {
				$where[]    = 'l.host = %s';
				$params[]   = $h;
				$match_type = 'host';
			}
		}

		if ( $anchor_p !== '' ) {
			$anchor_match = isset( $input['anchor_match'] ) ? sanitize_key( (string) $input['anchor_match'] ) : 'words';
			if ( ! in_array( $anchor_match, WSS_Config::ANCHOR_MATCH_MODES, true ) ) {
				return new WP_Error(
					'wss_invalid_anchor_match',
					sprintf(
						/* translators: %s: allowed anchor matching modes */
						__( 'Недопустимый anchor_match. Разрешены: %s.', 'wp-smart-search' ),
						implode( ', ', WSS_Config::ANCHOR_MATCH_MODES )
					),
					[ 'status' => 400 ]
				);
			}

			if ( 'substring' === $anchor_match ) {
				$where[]  = 'l.anchor LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $anchor_p ) . '%';
				if ( $match_type === 'all' ) {
					$match_type = 'anchor_substring';
				}
			} elseif ( 'exact' === $anchor_match ) {
				$where[]  = 'l.anchor = %s';
				$params[] = $anchor_p;
				if ( $match_type === 'all' ) {
					$match_type = 'anchor_exact';
				}
			} else {
				// Words with morphology: a broad LIKE prefilter, the final
				// decision is the whole-word regex below.
				$anchor_words = preg_split( '/\s+/u', $anchor_p, -1, PREG_SPLIT_NO_EMPTY );
				$anchor_words = is_array( $anchor_words ) ? array_slice( $anchor_words, 0, WSS_Config::MAX_WORDS ) : [];
				$anchor_terms = WSS_Morphology::terms( $anchor_words );

				foreach ( $anchor_terms as $anchor_term ) {
					$where[]  = 'l.anchor LIKE %s';
					$params[] = '%' . $wpdb->esc_like( $anchor_term ) . '%';
				}
				foreach ( $anchor_terms as $anchor_term ) {
					$anchor_patterns[] = WSS_Morphology::word_pattern( $anchor_term );
				}
				if ( $match_type === 'all' ) {
					$match_type = 'anchor_words';
				}
			}
		}

		// Single post filter: all links of the given posts. An empty list
		// (all ids were zero or the cap dropped everything) is an empty
		// result, not an error: audits walk a list and hit deleted posts.
		$no_match = false;
		if ( null !== $post_ids ) {
			if ( empty( $post_ids ) ) {
				$no_match = true;
			} else {
				$ph      = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
				$where[] = "l.post_id IN ($ph)";
				foreach ( $post_ids as $pid ) {
					$params[] = $pid;
				}
				if ( $match_type === 'all' ) {
					$match_type = 'post_id';
				}
			}
		}

		// Anchor kind filter: phrase | url | empty | unknown.
		$anchor_kind_filter = $this->anchor_kind_filter( $input['anchor_kind'] ?? null, $is_api );
		if ( is_wp_error( $anchor_kind_filter ) ) {
			return $anchor_kind_filter;
		}
		if ( null !== $anchor_kind_filter && ! empty( $anchor_kind_filter ) ) {
			$ph      = implode( ',', array_fill( 0, count( $anchor_kind_filter ), '%s' ) );
			$where[] = "l.anchor_kind IN ($ph)";
			foreach ( $anchor_kind_filter as $kind ) {
				$params[] = $kind;
			}
			if ( $match_type === 'all' ) {
				$match_type = 'anchor_kind';
			}
		}

		/* ---------- fallback q → domain or anchor ---------- */
		if ( $match_type === 'all' && null === $post_ids && null === $anchor_kind_filter ) {
			$q = (string) ( $filters['query'] ?? '' );
			if ( $q !== '' ) {
				$norm = WSS_Refang::normalize_url( $q );
				$dom  = '';
				if ( $norm !== '' ) {
					$parts = wp_parse_url( $norm );
					if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
						$dom = WSS_Links_Indexer::registrable_domain( (string) $parts['host'] );
					}
				}
				if ( $dom !== '' ) {
					$where[]    = '(l.domain = %s OR l.url_norm_hash = %s)';
					$params[]   = $dom;
					$params[]   = md5( $norm );
					$match_type = 'domain';
				} else {
					$where[]    = 'l.anchor LIKE %s';
					$params[]   = '%' . $wpdb->esc_like( $q ) . '%';
					$match_type = 'anchor_substring';
				}
			}
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = max( 1, min( WSS_Config::POSTS_PER_PAGE_MAX, (int) ( $input['per_page'] ?? WSS_Config::POSTS_PER_PAGE_MAX ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// An exact total needs a second query (COUNT). In batch mode it
		// is not needed: has_more comes from LIMIT + 1.
		$exact_total = ! isset( $input['exact_total'] ) || $this->to_bool( $input['exact_total'], true );

		$fields = $this->parse_fields( $input['fields'] ?? null );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// No link filter applied (for example an invalid URL came from
		// batch), or every requested post id was unusable: an empty answer
		// is the honest one.
		if ( $match_type === 'all' || $no_match ) {
			return [
				'items'       => [],
				'total_exact' => $exact_total,
				'page'        => $page,
				'per_page'    => $per_page,
				'has_more'    => false,
				'meta'        => [
					'post_id'      => null === $post_ids ? [] : $post_ids,
					'anchor_match' => $anchor_p !== '' ? ( $anchor_match ?? 'words' ) : '',
					'anchor_terms' => $anchor_terms,
					'anchor_kind'  => null === $anchor_kind_filter ? [] : $anchor_kind_filter,
				],
			];
		}

		if ( empty( $where ) ) {
			return [
				'items'    => [],
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => false,
			];
		}

		$where_sql = implode( ' AND ', $where );

		$total = 0;
		if ( $exact_total ) {
			$count_sql = "SELECT COUNT(*)
				FROM $table l
				INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id
				WHERE $where_sql";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		}

		$limit = $exact_total ? $per_page : ( $per_page + 1 );

		$sql = "SELECT l.id, l.post_id, l.url, l.url_norm, l.host, l.domain,
					l.anchor, l.anchor_kind, l.context, p.post_title, p.post_status,
					p.post_type
			FROM $table l
			INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id
			WHERE $where_sql
			ORDER BY p.post_date DESC, l.id DESC
			LIMIT %d OFFSET %d";

		$params_page = array_merge( $params, [ $limit, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params_page ) );

		$items = [];
		$dropped = 0;
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$post_id = (int) $row->post_id;

				// Words mode: the LIKE prefilter is broader than a word
				// match, so the regex makes the final decision here.
				if ( ! empty( $anchor_patterns ) && ! $this->matches_all_words( (string) $row->anchor, $anchor_patterns ) ) {
					$dropped++;
					continue;
				}

				$permalink = (string) get_permalink( $post_id );

				$items[] = [
					'post_id'     => $post_id,
					'title'       => $row->post_title !== '' ? $row->post_title : __( '(без названия)', 'wp-smart-search' ),
					'permalink'   => $permalink,
					'status'      => (string) $row->post_status,
					'post_type'   => (string) $row->post_type,
					'url'         => (string) $row->url,
					'host'        => (string) $row->host,
					'domain'      => (string) $row->domain,
					'anchor'      => (string) $row->anchor,
					'anchor_kind' => (string) $row->anchor_kind,
					'context'     => (string) $row->context,
					'match_type'  => $match_type,
				];
			}
		}

		$has_more = $exact_total
			? ( $page * $per_page ) < $total
			: count( $items ) > $per_page;

		if ( ! $exact_total && count( $items ) > $per_page ) {
			$items = array_slice( $items, 0, $per_page );
		}

		if ( $fields !== null ) {
			foreach ( $items as &$item ) {
				$filtered = [];
				foreach ( $fields as $f ) {
					if ( array_key_exists( $f, $item ) ) {
						$filtered[ $f ] = $item[ $f ];
					}
				}
				$item = $filtered;
			}
			unset( $item );
		}

		// The word regex rejected rows the LIKE prefilter let through, so the
		// SQL total is an upper bound; subtract them for a closer number.
		if ( $dropped > 0 && $exact_total ) {
			$total = max( 0, $total - $dropped );
		}

		$out = [
			'items'       => array_values( $items ),
			'total_exact' => $exact_total && empty( $anchor_patterns ),
			'page'        => $page,
			'per_page'    => $per_page,
			'has_more'    => (bool) $has_more,
			'meta'        => [
				'post_id'      => null === $post_ids ? [] : $post_ids,
				'anchor_match' => $anchor_p !== '' ? ( $anchor_match ?? 'words' ) : '',
				'anchor_terms' => $anchor_terms,
				'anchor_kind'  => null === $anchor_kind_filter ? [] : $anchor_kind_filter,
			],
		];

		if ( $exact_total ) {
			$out['total'] = $total;
		}
		if ( $fields !== null ) {
			$out['fields'] = array_values( $fields );
		}

		return $out;
	}

	/**
	 * One of an allowed set, or a 400 in API mode.
	 *
	 * @param array    $input
	 * @param string   $key
	 * @param string[] $allowed
	 * @param string   $default
	 * @param string   $code
	 * @param bool     $is_api
	 * @return string|WP_Error
	 */
	private function enum_param( array $input, string $key, array $allowed, string $default, string $code, bool $is_api ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $default;
		}

		$value = $input[ $key ];

		if ( ! is_string( $value ) ) {
			return $is_api
				? new WP_Error(
					$code,
					sprintf(
						/* translators: %s: parameter name */
						__( 'Параметр %s должен быть строкой.', 'wp-smart-search' ),
						$key
					),
					[ 'status' => 400 ]
				)
				: $default;
		}

		$value = sanitize_key( $value );

		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}

		return $is_api
			? new WP_Error(
				$code,
				sprintf(
					/* translators: 1: parameter name, 2: given value, 3: allowed values */
					__( 'Недопустимое значение %1$s: %2$s. Разрешены: %3$s.', 'wp-smart-search' ),
					$key,
					$value,
					implode( ', ', $allowed )
				),
				[ 'status' => 400 ]
			)
			: $default;
	}

	/**
	 * A positive integer, or a 400 in API mode.
	 *
	 * @param array  $input
	 * @param string $key
	 * @param int    $default
	 * @param string $code
	 * @param bool   $is_api
	 * @return int|WP_Error
	 */
	private function int_param( array $input, string $key, int $default, string $code, bool $is_api ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $default;
		}

		$value = $input[ $key ];

		$usable = ! is_bool( $value ) && is_scalar( $value )
			&& preg_match( '/^[0-9]+$/', trim( (string) $value ) )
			&& (int) $value > 0;

		if ( $usable ) {
			return (int) $value;
		}

		return $is_api
			? new WP_Error(
				$code,
				sprintf(
					/* translators: %s: parameter name */
					__( 'Параметр %s должен быть положительным целым числом.', 'wp-smart-search' ),
					$key
				),
				[ 'status' => 400 ]
			)
			: $default;
	}

	/**
	 * Normalised post_id filter: null when absent, a list of ids otherwise.
	 *
	 * An empty list means "nothing can match" (no usable ids) and must not be
	 * confused with "no filter". A present but unusable id is a 400 in API
	 * mode: dropping it would silently search every post, which is how
	 * "post_id=abc" used to look like a valid answer.
	 *
	 * @param mixed $value Scalar, comma separated string or array.
	 * @param bool  $is_api
	 * @return int[]|null|WP_Error
	 */
	private function post_id_filter( $value, bool $is_api = false ) {
		if ( null === $value || '' === $value || [] === $value ) {
			return null;
		}

		$raw     = is_array( $value ) ? $value : [ $value ];
		$ids     = [];
		$invalid = [];

		foreach ( $raw as $id ) {
			if ( is_bool( $id ) || is_array( $id ) || ! is_scalar( $id )
				|| ! preg_match( '/^[0-9]+$/', trim( (string) $id ) )
				|| (int) $id <= 0 ) {
				$invalid[] = is_scalar( $id ) ? (string) $id : '?';
				continue;
			}

			$ids[] = (int) $id;
		}

		if ( ! empty( $invalid ) ) {
			if ( $is_api ) {
				return new WP_Error(
					'wss_invalid_post_id',
					sprintf(
						/* translators: %s: given values */
						__( 'post_id должен быть положительным целым числом (или списком): %s.', 'wp-smart-search' ),
						implode( ', ', $invalid )
					),
					[ 'status' => 400 ]
				);
			}

			return null;
		}

		$ids = array_values( array_unique( $ids ) );

		if ( count( $ids ) > WSS_Config::MAX_POST_ID_FILTER ) {
			$ids = array_slice( $ids, 0, WSS_Config::MAX_POST_ID_FILTER );
		}

		return $ids;
	}

	/**
	 * Normalised anchor_kind filter: null when absent.
	 *
	 * @param mixed $value Scalar, comma separated string or array.
	 * @return string[]|null|WP_Error
	 */
	private function anchor_kind_filter( $value, bool $is_api ) {
		if ( null === $value || '' === $value || [] === $value ) {
			return null;
		}

		$raw = is_array( $value ) ? $value : explode( ',', (string) $value );
		$kinds = [];
		$invalid = [];

		foreach ( $raw as $kind ) {
			if ( ! is_scalar( $kind ) ) {
				$invalid[] = '?';
				continue;
			}
			$kind = sanitize_key( (string) $kind );
			if ( $kind === '' ) {
				continue;
			}
			if ( in_array( $kind, WSS_Config::ANCHOR_KINDS, true ) ) {
				$kinds[] = $kind;
			} else {
				$invalid[] = $kind;
			}
		}

		if ( ! empty( $invalid ) && $is_api ) {
			return new WP_Error(
				'wss_invalid_anchor_kind',
				sprintf(
					/* translators: 1: given kinds, 2: allowed kinds */
					__( 'Недопустимый anchor_kind: %1$s. Разрешены: %2$s.', 'wp-smart-search' ),
					implode( ', ', array_unique( $invalid ) ),
					implode( ', ', WSS_Config::ANCHOR_KINDS )
				),
				[ 'status' => 400 ]
			);
		}

		return array_values( array_unique( $kinds ) );
	}

	/**
	 * Parses the fields parameter.
	 *
	 * Returns null (all fields), a field list (required fields added)
	 * or WP_Error when the client asks for an unknown field: silently
	 * dropping post_id/url/anchor is no longer allowed, it caused
	 * broken integrations.
	 *
	 * @param mixed $fields
	 * @return string[]|null|WP_Error
	 */
	private function parse_fields( $fields ) {
		if ( $fields === null || $fields === '' || $fields === [] ) {
			return null;
		}
		if ( is_string( $fields ) ) {
			$fields = array_map( 'trim', explode( ',', $fields ) );
		}
		if ( ! is_array( $fields ) ) {
			return new WP_Error(
				'wss_invalid_fields',
				__( 'Параметр fields должен быть списком через запятую или массивом.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}

		// Every element must be a scalar: a nested array would otherwise be
		// cast to the string "Array" and turn into a confusing 400.
		$clean = [];
		foreach ( $fields as $field ) {
			if ( ! is_scalar( $field ) ) {
				return new WP_Error(
					'wss_invalid_fields',
					__( 'Параметр fields должен содержать строки.', 'wp-smart-search' ),
					[ 'status' => 400 ]
				);
			}
			$clean[] = (string) $field;
		}

		$fields = array_values( array_unique( array_filter( array_map(
			'sanitize_key',
			$clean
		) ) ) );

		if ( empty( $fields ) ) {
			return null;
		}

		$unknown = array_values( array_diff( $fields, WSS_Config::LINK_FIELDS ) );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'wss_invalid_fields',
				sprintf(
					/* translators: 1: unknown fields, 2: available fields */
					__( 'Неизвестные поля: %1$s. Доступны: %2$s.', 'wp-smart-search' ),
					implode( ', ', $unknown ),
					implode( ', ', WSS_Config::LINK_FIELDS )
				),
				[ 'status' => 400 ]
			);
		}

		return array_values( array_unique( array_merge( WSS_Config::LINK_FIELDS_REQUIRED, $fields ) ) );
	}

	private function parse_date_boundary( string $value, bool $is_to ): int {
		$value = trim( $value );
		if ( $value === '' ) {
			return 0;
		}
		$has_time = (bool) preg_match( '/\d{2}:\d{2}/', $value ) || (bool) preg_match( '/T\d{2}/', $value );
		if ( $is_to && ! $has_time ) {
			$value .= ' 23:59:59';
		}
		$ts = strtotime( $value );
		return $ts === false ? 0 : (int) $ts;
	}
}
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
	 *  Точка входа
	 * ============================================================ */

	public function do_search( array $input, bool $is_api ) {
		$raw = isset( $input['query'] ) ? (string) $input['query'] : '';

		$refang = $this->to_bool( $input['refang'] ?? null, true );
		if ( $refang && $raw !== '' ) {
			$raw = WSS_Refang::refang_query( $raw );
		}

		$type = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'all';
		if ( ! in_array( $type, [ 'all', 'tags', 'posts' ], true ) ) {
			$type = 'all';
		}

		$match = isset( $input['match'] ) ? sanitize_key( (string) $input['match'] ) : 'auto';
		if ( ! in_array( $match, [ 'auto', 'fuzzy', 'exact' ], true ) ) {
			$match = 'auto';
		}

		$scope = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : WSS_Config::SCOPE_POSTS;
		if ( ! in_array( $scope, WSS_Config::SCOPES, true ) ) {
			$scope = WSS_Config::SCOPE_POSTS;
		}

		$page = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$page = max( 1, $page );

		$words = $this->parse_query( $raw );
		if ( is_wp_error( $words ) ) {
			return $words;
		}

		$filters          = $this->parse_filters( $input, $is_api );
		$filters['query'] = $raw;

		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : WSS_Config::POSTS_PER_PAGE;
		$per_page = max( 1, min( WSS_Config::POSTS_PER_PAGE_MAX, $per_page ) );

		$patterns = $this->build_word_patterns( $words );

		$payload = [
			'query'  => $raw,
			'refang' => $refang,
			'scope'  => $scope,
		];

		if ( $scope !== WSS_Config::SCOPE_LINKS ) {
			$payload['type']  = $type;
			$payload['match'] = $match;

			if ( $type === 'all' || $type === 'tags' ) {
				$payload['tags'] = $this->search_tags( $words );
			}
			if ( $type === 'all' || $type === 'posts' ) {
				$payload['posts'] = $this->search_posts(
					$words,
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
			$payload['links'] = $this->search_links( $words, $input, $filters, $is_api );
		}

		return $payload;
	}

	/* ============================================================
	 *  Утилиты
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

	private function parse_filters( array $input, bool $is_api ): array {
		$statuses_raw = $input['statuses'] ?? [];
		if ( is_string( $statuses_raw ) ) {
			$statuses_raw = array_map( 'trim', explode( ',', $statuses_raw ) );
		}
		$statuses = array_values( array_intersect( (array) $statuses_raw, WSS_Config::ALLOWED_STATUSES ) );
		if ( empty( $statuses ) ) {
			$statuses = WSS_Config::ALLOWED_STATUSES;
		}

		$date_range = isset( $input['date_range'] ) ? sanitize_key( (string) $input['date_range'] ) : 'any';
		if ( ! in_array( $date_range, [ 'any', 'today', 'week', 'month', 'year' ], true ) ) {
			$date_range = 'any';
		}

		$author = isset( $input['author'] ) ? absint( $input['author'] ) : 0;
		if ( ! $is_api && ! current_user_can( 'edit_others_posts' ) ) {
			$author = 0;
		}

		$orderby = isset( $input['orderby'] ) ? sanitize_key( (string) $input['orderby'] ) : 'date_desc';
		if ( ! in_array( $orderby, [ 'date_desc', 'date_asc' ], true ) ) {
			$orderby = 'date_desc';
		}

		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';
		if ( $post_type === '' ) {
			$post_type = 'post';
		}

		$exclude = [];
		if ( isset( $input['exclude'] ) ) {
			$exclude = array_merge( $exclude, (array) $input['exclude'] );
		}
		if ( isset( $input['exclude_id'] ) ) {
			$exclude = array_merge( $exclude, (array) $input['exclude_id'] );
		}
		$exclude = array_values( array_filter( array_map( 'absint', $exclude ) ) );

		return [
			'statuses'   => $statuses,
			'date_range' => $date_range,
			'author'     => $author,
			'orderby'    => $orderby,
			'exclude'    => $exclude,
			'post_type'  => $post_type,
			'date_from'  => isset( $input['date_from'] ) ? (string) $input['date_from'] : '',
			'date_to'    => isset( $input['date_to'] )   ? (string) $input['date_to']   : '',
		];
	}

	/* ============================================================
	 *  Парсинг запроса
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
		$parts = array_slice( $parts, 0, WSS_Config::MAX_WORDS );

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
		return $words;
	}

	/* ============================================================
	 *  Сопоставление
	 * ============================================================ */

	private function build_word_patterns( array $words ): array {
		$patterns = [];
		foreach ( $words as $w ) {
			$patterns[] = '/(?<![\p{L}\p{N}_])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}_])/iu';
		}
		return $patterns;
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
	 *  Теги
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
	 *  Посты: full-list cache + slice
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
		$scope = $is_api
			? 'api_all'
			: ( current_user_can( 'edit_others_posts' ) ? 'e' : 'u' . get_current_user_id() );

		$cache_key = 'wss_posts_' . WSS_Config::POSTS_CACHE_VER . '_'
			. $this->cache->generation() . '_'
			. $scope . '_'
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

		$all = $this->cache->get( $cache_key );
		if ( $all === null ) {
			$all = $this->build_posts_list( $words, $patterns, $filters, $is_api, $match );
			$this->cache->set( $cache_key, $all, WSS_Config::POSTS_CACHE_TTL );
		}

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

		$total       = count( $all );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
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
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * Полный (несрезанный) список совпадений.
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
			return $exact ? [ $this->format_post_item( $exact, $words, true ) ] : [];
		}

		$rows = $this->query_fuzzy( $words, $patterns, $filters, $is_api );

		$exact_post = null;
		if ( $match === 'auto' ) {
			$exact_post = $this->find_exact_post( $q, $filters, $is_api );
			if ( $exact_post ) {
				$found = false;
				foreach ( $rows as $r ) {
					if ( (int) $r->ID === (int) $exact_post->ID ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					$rows[] = $this->wp_post_to_row( $exact_post );
				}
			}
		}

		$out = [];
		foreach ( $rows as $row ) {
			$is_exact = $exact_post instanceof WP_Post && (int) $row->ID === (int) $exact_post->ID;

			if ( ! $is_exact ) {
				$haystack = $this->row_haystack( $row );
				if ( ! $this->matches_all_words( $haystack, $patterns ) ) {
					continue;
				}
			}

			$out[] = $this->format_row( $row, $words, $is_exact );
		}

		return $out;
	}

	private function query_fuzzy( array $words, array $patterns, array $filters, bool $is_api ): array {
		global $wpdb;

		$where  = [];
		$params = [];

		$selected_statuses = $filters['statuses'];

		if ( $is_api ) {
			$ph      = implode( ',', array_fill( 0, count( $selected_statuses ), '%s' ) );
			$where[] = "p.post_status IN ($ph)";
			foreach ( $selected_statuses as $s ) {
				$params[] = $s;
			}
		} else {
			if ( current_user_can( 'edit_others_posts' ) ) {
				$ph      = implode( ',', array_fill( 0, count( $selected_statuses ), '%s' ) );
				$where[] = "p.post_status IN ($ph)";
				foreach ( $selected_statuses as $s ) {
					$params[] = $s;
				}
			} else {
				$publish_selected = in_array( 'publish', $selected_statuses, true );
				$own_statuses     = array_values( array_diff( $selected_statuses, [ 'publish' ] ) );

				$sub = [];
				if ( $publish_selected ) {
					$sub[] = "p.post_status = 'publish'";
				}
				if ( ! empty( $own_statuses ) ) {
					$ph       = implode( ',', array_fill( 0, count( $own_statuses ), '%s' ) );
					$sub[]    = "(p.post_author = %d AND p.post_status IN ($ph))";
					$params[] = get_current_user_id();
					foreach ( $own_statuses as $s ) {
						$params[] = $s;
					}
				}
				if ( empty( $sub ) ) {
					$sub[] = '1=0';
				}
				$where[] = '(' . implode( ' OR ', $sub ) . ')';
			}
		}

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

		$order = $filters['orderby'] === 'date_asc' ? 'ASC' : 'DESC';

		$sql = "SELECT p.ID, p.post_title, p.post_content, p.post_excerpt,
					p.post_status, p.post_date, p.post_author, p.post_type
			FROM {$wpdb->posts} AS p
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY p.post_date $order";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		return is_array( $rows ) ? $rows : [];
	}

	/* ============================================================
	 *  Exact-match по permalink
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
		if ( ! $is_api && ! current_user_can( 'edit_others_posts' ) ) {
			if ( $post->post_status !== 'publish' && (int) $post->post_author !== get_current_user_id() ) {
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
	 *  Search links (новый scope)
	 * ============================================================ */

	private function search_links( array $words, array $input, array $filters, bool $is_api ): array {
		global $wpdb;
		$table = $wpdb->prefix . WSS_Config::LINKS_TABLE;

		$where  = [];
		$params = [];

		/* ---------- Post-level фильтры ---------- */

		$statuses = $filters['statuses'];
		$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$where[]  = "p.post_status IN ($ph)";
		foreach ( $statuses as $s ) {
			$params[] = $s;
		}

		$where[]  = 'p.post_type = %s';
		$params[] = (string) ( $filters['post_type'] ?? 'post' );

		if ( ! $is_api && ! current_user_can( 'edit_others_posts' ) ) {
			$where[]  = 'p.post_author = %d';
			$params[] = get_current_user_id();
		} elseif ( ! empty( $filters['author'] ) && (int) $filters['author'] > 0 ) {
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

		/* ---------- Link-level фильтры ---------- */

		$match_type = 'all';

		if ( isset( $input['url'] ) && $input['url'] !== '' ) {
			$norm = WSS_Refang::normalize_url( (string) $input['url'] );
			if ( $norm !== '' ) {
				$where[]    = 'l.url_norm_hash = %s';
				$params[]   = md5( $norm );
				$match_type = 'exact_url';
			}
		}

		if ( isset( $input['domain'] ) && $input['domain'] !== '' ) {
			$d = WSS_Links_Indexer::registrable_domain( (string) $input['domain'] );
			if ( $d !== '' ) {
				$where[]  = 'l.domain = %s';
				$params[] = $d;
				if ( $match_type === 'all' ) {
					$match_type = 'domain';
				}
			}
		}

		if ( $match_type === 'all' && isset( $input['host'] ) && $input['host'] !== '' ) {
			$h = strtolower( preg_replace( '/^www\./i', '', trim( (string) $input['host'] ) ) );
			if ( $h !== '' ) {
				$where[]    = 'l.host = %s';
				$params[]   = $h;
				$match_type = 'host';
			}
		}

		if ( isset( $input['anchor'] ) && $input['anchor'] !== '' ) {
			$where[]  = 'l.anchor LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $input['anchor'] ) . '%';
			if ( $match_type === 'all' ) {
				$match_type = 'anchor';
			}
		}

		// Fallback: q → domain или anchor.
		if ( $match_type === 'all' ) {
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
					$match_type = 'anchor';
				}
			}
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = max( 1, min( WSS_Config::POSTS_PER_PAGE_MAX, (int) ( $input['per_page'] ?? WSS_Config::POSTS_PER_PAGE_MAX ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( empty( $where ) ) {
			return [
				'items'    => [],
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => false,
			];
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*)
			FROM $table l
			INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id
			WHERE $where_sql";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$sql = "SELECT l.id, l.post_id, l.url, l.url_norm, l.host, l.domain,
					l.anchor, l.context, p.post_title, p.post_status
			FROM $table l
			INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id
			WHERE $where_sql
			ORDER BY p.post_date DESC, l.id DESC
			LIMIT %d OFFSET %d";

		$params_page = array_merge( $params, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params_page ) );

		$items = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$post_id   = (int) $row->post_id;
				$permalink = (string) get_permalink( $post_id );

				$items[] = [
					'post_id'    => $post_id,
					'title'      => $row->post_title !== '' ? $row->post_title : __( '(без названия)', 'wp-smart-search' ),
					'permalink'  => $permalink,
					'status'     => (string) $row->post_status,
					'url'        => (string) $row->url,
					'host'       => (string) $row->host,
					'domain'     => (string) $row->domain,
					'anchor'     => (string) $row->anchor,
					'context'    => (string) $row->context,
					'match_type' => $match_type,
				];
			}
		}

		$fields = $input['fields'] ?? null;
		if ( is_string( $fields ) ) {
			$fields = array_values( array_filter( array_map( 'trim', explode( ',', $fields ) ) ) );
		}
		if ( is_array( $fields ) && ! empty( $fields ) ) {
			$fields = array_map( 'sanitize_key', $fields );
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

		return [
			'items'    => array_values( $items ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => ( $page * $per_page ) < $total,
		];
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
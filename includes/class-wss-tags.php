<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terms for GET /wss/v1/tags — the lookup the pipeline used to run through
 * /wp/v2/tags (two requests per material: first the slug, then a search).
 *
 * Three access patterns, one endpoint:
 *  - slug (one or up to 50): an exact lookup on the indexed slug column;
 *  - search / prefix: a substring or prefix of the name, for "does a close
 *    tag already exist?";
 *  - post_id: the terms of one post, for the audit after publishing.
 *
 * The post search stays in WSS_Search_Engine; this class only reads terms and
 * never touches post content.
 */
final class WSS_Tags {

	private WSS_Cache $cache;

	public function __construct( WSS_Cache $cache ) {
		$this->cache = $cache;
	}

	public function register_hooks(): void {
		// Any term change invalidates the answers; a new tag must be visible
		// immediately, not after the 60-second TTL.
		add_action( 'created_term', [ $this, 'on_term_change' ] );
		add_action( 'edited_term', [ $this, 'on_term_change' ] );
		add_action( 'delete_term', [ $this, 'on_term_change' ] );
	}

	/**
	 * Moves the cache generation on.
	 *
	 * A generation number inside the cache key is cheaper than scanning Redis
	 * for a key prefix, and the old entries fall out on their own TTL.
	 */
	public function on_term_change(): void {
		update_option(
			WSS_Config::TAGS_GEN_OPTION,
			(int) get_option( WSS_Config::TAGS_GEN_OPTION, 0 ) + 1,
			false
		);
	}

	/**
	 * Answers one /tags request.
	 *
	 * @param array $input Raw request parameters.
	 * @return array|WP_Error
	 */
	public function query( array $input ) {
		$slugs    = $this->parse_slugs( $input['slug'] ?? null );
		if ( is_wp_error( $slugs ) ) {
			return $slugs;
		}

		$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		$prefix   = isset( $input['prefix'] ) ? trim( (string) $input['prefix'] ) : '';
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : 'post_tag';
		$counts   = $this->to_bool( $input['include_counts'] ?? null, true );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = (int) ( $input['per_page'] ?? WSS_Config::TAGS_PER_PAGE );
		$per_page = max( 1, min( WSS_Config::TAGS_PER_PAGE_MAX, $per_page ) );
		$fields   = $this->parse_fields( $input['fields'] ?? null );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// post_id is either absent or a positive integer. "abc", 1.5, -1, 0,
		// true or an array is a client bug and must be a 400 — silently turning
		// it into "no filter" answered with the whole taxonomy (40 759 terms).
		$post_id_raw = $input['post_id'] ?? null;
		$post_id     = 0;

		if ( null !== $post_id_raw && '' !== $post_id_raw ) {
			if ( is_bool( $post_id_raw ) || ! is_scalar( $post_id_raw )
				|| ! preg_match( '/^[0-9]+$/', trim( (string) $post_id_raw ) )
				|| (int) $post_id_raw <= 0 ) {
				return new WP_Error(
					'wss_invalid_post_id',
					__( 'post_id должен быть положительным целым числом.', 'wp-smart-search' ),
					[ 'status' => 400 ]
				);
			}

			$post_id = (int) $post_id_raw;
		}

		if ( null !== $slugs && $search !== '' ) {
			return new WP_Error(
				'wss_ambiguous_query',
				__( 'Параметры slug и search нельзя передавать вместе: для проверки по слагу достаточно slug.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}

		if ( $search !== '' && mb_strlen( $search ) < WSS_Config::TAGS_MIN_SEARCH ) {
			return new WP_Error(
				'wss_search_too_short',
				sprintf(
					/* translators: %d: minimum number of characters */
					__( 'search должен содержать не менее %d символов.', 'wp-smart-search' ),
					WSS_Config::TAGS_MIN_SEARCH
				),
				[ 'status' => 400 ]
			);
		}

		if ( $prefix !== '' && mb_strlen( $prefix ) < WSS_Config::TAGS_MIN_PREFIX ) {
			return new WP_Error(
				'wss_prefix_too_short',
				sprintf(
					/* translators: %d: minimum number of characters */
					__( 'prefix должен содержать не менее %d символов.', 'wp-smart-search' ),
					WSS_Config::TAGS_MIN_PREFIX
				),
				[ 'status' => 400 ]
			);
		}

		if ( '' === $taxonomy ) {
			$taxonomy = 'post_tag';
		}

		if ( 'any' !== $taxonomy && ! in_array( $taxonomy, WSS_Config::TAGS_TAXONOMIES, true ) ) {
			return new WP_Error(
				'wss_invalid_taxonomy',
				sprintf(
					/* translators: %s: allowed taxonomies */
					__( 'Недопустимая таксономия. Разрешены: %s, any.', 'wp-smart-search' ),
					implode( ', ', WSS_Config::TAGS_TAXONOMIES )
				),
				[ 'status' => 400 ]
			);
		}

		// A post the current user may not read contributes nothing: the
		// response stays a 200 with an empty list, like an unknown tag.
		if ( $post_id > 0 && ! $this->post_is_readable( $post_id ) ) {
			return $this->empty_response( $slugs, $search, $prefix, $post_id, $taxonomy, $page, $per_page );
		}

		$key = $this->cache_key( $slugs, $search, $prefix, $post_id, $taxonomy, $counts, $page, $per_page, $fields );

		$cached = $this->cache->get( $key );
		if ( is_array( $cached ) && isset( $cached['items'], $cached['total'] ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		$out = $this->run_query( $slugs, $search, $prefix, $post_id, $taxonomy, $counts, $page, $per_page, $fields );

		$this->cache->set( $key, $out, WSS_Config::TAGS_ENDPOINT_CACHE_TTL );

		$out['cached'] = false;

		return $out;
	}

	/* ============================================================
	 *  Query
	 * ============================================================ */

	/**
	 * @param string[]|null $slugs
	 * @param string[]|null $fields
	 * @return array
	 */
	private function run_query( $slugs, string $search, string $prefix, int $post_id, string $taxonomy, bool $counts, int $page, int $per_page, $fields ): array {
		global $wpdb;

		[ $where, $params ] = $this->where_clause( $slugs, $search, $prefix, $post_id, $taxonomy );

		if ( null === $where ) {
			return $this->empty_response( $slugs, $search, $prefix, $post_id, $taxonomy, $page, $per_page, $fields );
		}

		$count_sql = "SELECT COUNT(DISTINCT t.term_id)
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			WHERE {$where}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$limit  = $per_page;
		$offset = ( $page - 1 ) * $per_page;

		$sql = "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.count
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			WHERE {$where}
			ORDER BY tt.count DESC, t.name ASC
			LIMIT %d OFFSET %d";

		$params_page = array_merge( $params, [ $limit, $offset ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params_page ) );

		$items = [];
		$seen  = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$id = (int) $row->term_id;

				// "any" joins every public taxonomy: a term shared by two of
				// them comes back twice, keep the row with the highest count
				// (the query is already sorted by it).
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;

				$item = [
					'id'   => $id,
					'name' => (string) $row->name,
					'slug' => (string) $row->slug,
				];

				if ( $counts ) {
					$item['count'] = (int) $row->count;
				}

				$items[] = $this->project( $item, $fields );
			}
		}

		return [
			'query'    => $this->query_block( $slugs, $search, $prefix, $post_id ),
			'taxonomy' => $taxonomy,
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => ( $page * $per_page ) < $total,
			'cached'   => false,
		];
	}

	/**
	 * WHERE fragment for the query, or [null, []] when nothing can match.
	 *
	 * @param string[]|null $slugs
	 * @return array{0:string|null,1:array<int,mixed>}
	 */
	private function where_clause( $slugs, string $search, string $prefix, int $post_id, string $taxonomy ): array {
		global $wpdb;

		$where  = [];
		$params = [];

		if ( 'any' === $taxonomy ) {
			$taxonomies = $this->public_taxonomies();

			if ( empty( $taxonomies ) ) {
				return [ null, [] ];
			}

			$ph      = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
			$where[] = "tt.taxonomy IN ($ph)";
			$params  = array_merge( $params, $taxonomies );
		} else {
			$where[]  = 'tt.taxonomy = %s';
			$params[] = $taxonomy;
		}

		if ( null !== $slugs ) {
			$ph      = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
			$where[] = "t.slug IN ($ph)";
			$params  = array_merge( $params, $slugs );
		}

		if ( $search !== '' ) {
			// The substring match runs on wp_terms only; the big post tables
			// are never touched by it.
			$where[]  = 't.name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( $prefix !== '' ) {
			// Prefixes use the name index of wp_terms.
			$where[]  = 't.name LIKE %s';
			$params[] = $wpdb->esc_like( $prefix ) . '%';
		}

		if ( $post_id > 0 ) {
			$where[]  = "EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} tr
				WHERE tr.object_id = %d AND tr.term_taxonomy_id = tt.term_taxonomy_id
			)";
			$params[] = $post_id;
		}

		return [ implode( ' AND ', $where ), $params ];
	}

	/**
	 * Public taxonomies, internal ones never included.
	 *
	 * @return string[]
	 */
	private function public_taxonomies(): array {
		$all = get_taxonomies( [ 'public' => true ], 'names' );
		$out = [];

		foreach ( (array) $all as $name ) {
			$name = (string) $name;

			if ( 0 === strpos( $name, 'wp_' ) ) {
				continue;
			}
			if ( in_array( $name, [ 'post_format', 'nav_menu', 'link_category' ], true ) ) {
				continue;
			}
			if ( function_exists( 'is_taxonomy_viewable' ) && ! is_taxonomy_viewable( $name ) ) {
				continue;
			}

			$out[] = $name;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether the current user may see the terms of this post.
	 *
	 * Published and scheduled posts are fine; private ones need read access.
	 */
	private function post_is_readable( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( 'private' === $post->post_status ) {
			return current_user_can( 'read_post', $post->ID );
		}

		return true;
	}

	/* ============================================================
	 *  Parameters
	 * ============================================================ */

	/**
	 * Normalised slug list: null when absent, [] never returned.
	 *
	 * @param mixed $value Single slug or a comma separated list.
	 * @return string[]|WP_Error|null
	 */
	private function parse_slugs( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$raw = is_array( $value ) ? $value : explode( ',', (string) $value );

		$entries = [];
		foreach ( $raw as $slug ) {
			if ( ! is_scalar( $slug ) ) {
				continue;
			}

			// Trim, lower case, spaces to dashes — the way WordPress stores
			// a slug, so the comparison stays an index lookup.
			$slug = strtolower( trim( (string) $slug ) );
			$slug = (string) preg_replace( '/\s+/u', '-', $slug );

			if ( $slug !== '' ) {
				$entries[] = $slug;
			}
		}

		if ( empty( $entries ) ) {
			return new WP_Error(
				'wss_empty_slug',
				__( 'Параметр slug пуст: передайте хотя бы один слаг.', 'wp-smart-search' ),
				[ 'status' => 400 ]
			);
		}

		// Count what the client sent, not what survived deduplication: a
		// request with 51 entries must be a 400 even if two of them repeat.
		if ( count( $entries ) > WSS_Config::TAGS_MAX_SLUGS ) {
			return new WP_Error(
				'wss_too_many_slugs',
				sprintf(
					/* translators: %d: maximum number of slugs */
					__( 'За один запрос можно проверить не более %d слагов.', 'wp-smart-search' ),
					WSS_Config::TAGS_MAX_SLUGS
				),
				[ 'status' => 400 ]
			);
		}

		return array_values( array_unique( $entries ) );
	}

	/**
	 * @param mixed $fields
	 * @return string[]|null|WP_Error Null means "all fields".
	 */
	private function parse_fields( $fields ) {
		if ( null === $fields || '' === $fields || [] === $fields ) {
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

		$out     = [];
		$invalid = [];

		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || $field === '' ) {
				continue;
			}

			if ( in_array( $field, WSS_Config::TAGS_FIELDS, true ) ) {
				$out[] = $field;
			} else {
				$invalid[] = $field;
			}
		}

		if ( ! empty( $invalid ) ) {
			return new WP_Error(
				'wss_invalid_fields',
				sprintf(
					/* translators: 1: given fields, 2: allowed fields */
					__( 'Неизвестные поля: %1$s. Допустимые поля: %2$s.', 'wp-smart-search' ),
					implode( ', ', $invalid ),
					implode( ', ', WSS_Config::TAGS_FIELDS )
				),
				[ 'status' => 400 ]
			);
		}

		return empty( $out ) ? null : array_values( array_unique( $out ) );
	}

	/**
	 * @param array        $item
	 * @param string[]|null $fields
	 */
	private function project( array $item, $fields ): array {
		if ( null === $fields ) {
			return $item;
		}

		$out = [];
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $item ) ) {
				$out[ $field ] = $item[ $field ];
			}
		}

		return $out;
	}

	/**
	 * @param string[]|null $slugs
	 */
	private function query_block( $slugs, string $search, string $prefix, int $post_id ): array {
		return [
			'slug'    => $slugs,
			'search'  => $search !== '' ? $search : null,
			'prefix'  => $prefix !== '' ? $prefix : null,
			'post_id' => $post_id > 0 ? $post_id : null,
		];
	}

	/**
	 * @param string[]|null $slugs
	 * @param string[]|null $fields
	 */
	private function empty_response( $slugs, string $search, string $prefix, int $post_id, string $taxonomy, int $page, int $per_page, $fields = null ): array {
		return [
			'query'    => $this->query_block( $slugs, $search, $prefix, $post_id ),
			'taxonomy' => $taxonomy,
			'items'    => [],
			'total'    => 0,
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => false,
			'cached'   => false,
		];
	}

	/**
	 * Cache key: the generation moves on every term change, so a new tag is
	 * visible immediately while the old answers expire on their own.
	 *
	 * @param string[]|null $slugs
	 * @param string[]|null $fields
	 */
	private function cache_key( $slugs, string $search, string $prefix, int $post_id, string $taxonomy, bool $counts, int $page, int $per_page, $fields ): string {
		$generation = (int) get_option( WSS_Config::TAGS_GEN_OPTION, 0 );

		// A post's terms follow the visibility of that post, so the key must
		// not mix users with different scopes.
		$scope = WSS_Config::can_search_all_statuses() ? 'all' : 'limited';

		$payload = [
			WSS_Config::TAGS_ENDPOINT_CACHE_VER,
			$generation,
			$scope,
			$slugs,
			$search,
			$prefix,
			$post_id,
			$taxonomy,
			$counts,
			$page,
			$per_page,
			$fields,
		];

		return 'tags:' . md5( (string) wp_json_encode( $payload ) );
	}

	/**
	 * @param mixed $value
	 */
	private function to_bool( $value, bool $default ): bool {
		if ( null === $value || '' === $value ) {
			return $default;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), [ '1', 'true', 'yes', 'on' ], true );
	}
}

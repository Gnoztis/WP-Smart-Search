<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Links_Indexer {

	/**
	 * Columns the runtime queries rely on. A table without them is not
	 * "empty", it is broken: searches fail or silently skip rows.
	 */
	private const REQUIRED_COLUMNS = [
		'id', 'post_id', 'url', 'url_hash', 'url_norm', 'url_norm_hash',
		'scheme', 'host', 'domain', 'path', 'anchor', 'anchor_kind',
		'context', 'created_at',
	];

	/** @var array<int,bool> */
	private array $indexed = [];
	private array $deleted = [];

	public function register_hooks(): void {
		add_action( 'init',                   [ $this, 'maybe_install' ] );
		add_action( 'save_post',              [ $this, 'on_save_post' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
		add_action( 'deleted_post',           [ $this, 'on_delete' ], 10, 1 );
	}

	/* ============================================================
	 *  Table install / upgrade
	 * ============================================================ */

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . WSS_Config::LINKS_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			url TEXT NOT NULL,
			url_hash CHAR(32) NOT NULL,
			url_norm TEXT NOT NULL,
			url_norm_hash CHAR(32) NOT NULL,
			scheme VARCHAR(16) NOT NULL DEFAULT '',
			host VARCHAR(255) NOT NULL DEFAULT '',
			domain VARCHAR(255) NOT NULL DEFAULT '',
			path VARCHAR(2048) NOT NULL DEFAULT '',
			anchor TEXT NOT NULL,
			anchor_kind VARCHAR(16) NOT NULL DEFAULT 'unknown',
			context TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY url_hash (url_hash),
			KEY url_norm_hash (url_norm_hash),
			KEY host (host),
			KEY domain (domain),
			KEY anchor_kind (anchor_kind),
			UNIQUE KEY post_url (post_id, url_hash)
		) $charset;";

		dbDelta( $sql );
		update_option( WSS_Config::LINKS_TABLE_VER_OPTION, WSS_Config::LINKS_TABLE_VER, true );
	}

	/**
	 * Actual schema of the index table compared with the expected one.
	 *
	 * A site that ran an older version can carry a table without the
	 * anchor_kind column; the version option may even say otherwise. The state
	 * tells the operator which of the three repair paths applies:
	 *
	 *  - missing  — no table at all;
	 *  - outdated — all columns exist, the stored schema version is old;
	 *  - broken   — the table exists but required columns are absent;
	 *  - ok       — table, columns and version match.
	 *
	 * @return array{state:string,exists:bool,rows:int,stored_version:string,expected_version:string,columns:string[],missing_columns:string[]}
	 */
	public static function schema_info(): array {
		$info = [
			'state'            => 'missing',
			'exists'           => false,
			'rows'             => 0,
			'stored_version'   => (string) get_option( WSS_Config::LINKS_TABLE_VER_OPTION, '' ),
			'expected_version' => WSS_Config::LINKS_TABLE_VER,
			'columns'          => [],
			'missing_columns'  => [],
		];

		if ( ! self::table_exists() ) {
			return $info;
		}

		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ); // phpcs:ignore -- a table name cannot be a prepare() placeholder.

		$columns = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( isset( $row['Field'] ) ) {
					$columns[] = (string) $row['Field'];
				}
			}
		}

		$missing = array_values( array_diff( self::REQUIRED_COLUMNS, $columns ) );

		$info['exists']          = true;
		$info['rows']            = self::count_rows();
		$info['columns']         = $columns;
		$info['missing_columns'] = $missing;

		if ( ! empty( $missing ) ) {
			$info['state'] = 'broken';
		} elseif ( $info['stored_version'] !== WSS_Config::LINKS_TABLE_VER ) {
			$info['state'] = 'outdated';
		} else {
			$info['state'] = 'ok';
		}

		return $info;
	}

	/**
	 * Drops the index table and creates it again with the current schema.
	 *
	 * Destructive by design: rows of an outdated or broken table cannot be
	 * trusted, and the admin asked for a clean start. The schema SQL lives in
	 * install_table() only — this method never duplicates it. The rebuild is
	 * not started here; the operator decides when.
	 *
	 * @return array{ok:bool,old_version:string,new_version:string,rows_dropped:int,state:string}
	 */
	public static function recreate(): array {
		global $wpdb;

		$old_version  = (string) get_option( WSS_Config::LINKS_TABLE_VER_OPTION, '' );
		$rows_dropped = self::count_rows();
		$table        = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore -- a table name cannot be a prepare() placeholder.

		self::install_table();

		$info = self::schema_info();

		$result = [
			'ok'           => 'ok' === $info['state'],
			'old_version'  => $old_version,
			'new_version'  => WSS_Config::LINKS_TABLE_VER,
			'rows_dropped' => $rows_dropped,
			'state'        => $info['state'],
		];

		// One line for the site log: what was replaced and how much was lost.
		error_log(
			sprintf(
				'WSS: links index recreated: schema %1$s -> %2$s, rows dropped: %3$d, state: %4$s',
				$old_version !== '' ? $old_version : '(none)',
				$result['new_version'],
				$rows_dropped,
				$result['state']
			)
		);

		return $result;
	}

	/**
	 * Runs dbDelta against the current schema, keeping the rows.
	 *
	 * dbDelta can add a missing column or index, so a table that only lacks
	 * anchor_kind is repaired without losing data. It cannot drop columns.
	 *
	 * @return array{ok:bool,before:string,after:string,missing_before:string[],missing_after:string[]}
	 */
	public static function repair(): array {
		$before = self::schema_info();

		self::install_table();

		$after = self::schema_info();

		return [
			'ok'             => 'ok' === $after['state'],
			'before'         => $before['state'],
			'after'          => $after['state'],
			'missing_before' => $before['missing_columns'],
			'missing_after'  => $after['missing_columns'],
		];
	}

	/**
	 * Table name including the site prefix.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . WSS_Config::LINKS_TABLE;
	}

	/**
	 * Whether the index table really exists.
	 *
	 * The version option alone is not proof: a table can be dropped by a
	 * migration, an uninstall or a database restore while the option stays
	 * behind. Without this check the plugin silently answers "no links".
	 */
	public static function table_exists(): bool {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return is_string( $found ) && $found === $table;
	}

	/**
	 * Rows in the index.
	 */
	public static function count_rows(): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore -- a table name cannot be a placeholder.
	}

	/**
	 * Posts that belong in the index: every status the search can return.
	 * Visibility is enforced at query time, so indexing private posts too
	 * keeps the index consistent with what save_post writes.
	 *
	 * A post without external links gets no rows in the links table: it is
	 * still indexed, it just has nothing to store. Progress therefore counts
	 * posts, not rows (see WSS_Index_Backfill::report()).
	 *
	 * @param int $max_id Only posts with ID <= this value (0 = all).
	 */
	public static function count_posts( int $max_id = 0 ): int {
		global $wpdb;

		$statuses = WSS_Config::ALLOWED_STATUSES;
		$ph       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql    = "SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = 'post' AND post_status IN ({$ph})";
		$params = array_values( $statuses );

		if ( $max_id > 0 ) {
			$sql     .= ' AND ID <= %d';
			$params[] = $max_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Posts that have at least one row in the index.
	 */
	public static function count_indexed_posts(): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table}" ); // phpcs:ignore -- a table name cannot be a placeholder.
	}

	/**
	 * Installs or repairs the table and records the schema version.
	 *
	 * The existence check is cached for an hour: it costs one query per
	 * hour per site instead of one per request, while a dropped table is
	 * still repaired within the hour (and immediately on activation).
	 */
	public function maybe_install(): void {
		$current = (string) get_option( WSS_Config::LINKS_TABLE_VER_OPTION, '' );

		if ( $current === WSS_Config::LINKS_TABLE_VER ) {
			$checked = (int) get_option( WSS_Config::LINKS_TABLE_CHECK_OPTION, 0 );
			if ( $checked > ( time() - WSS_Config::LINKS_TABLE_CHECK_TTL ) ) {
				return;
			}
			if ( self::table_exists() ) {
				update_option( WSS_Config::LINKS_TABLE_CHECK_OPTION, time(), true );
				return;
			}
		}

		self::install_table();
		update_option( WSS_Config::LINKS_TABLE_CHECK_OPTION, time(), true );

		// The table was missing or had another schema: whatever rows it had
		// are not trustworthy, so queue a rebuild.
		if ( class_exists( 'WSS_Index_Backfill' ) ) {
			WSS_Index_Backfill::start();
		}
	}

	/* ============================================================
	 *  Hooks
	 * ============================================================ */

	public function on_save_post( $post_id, $post = null, $update = null ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || isset( $this->indexed[ $post_id ] ) ) {
			return;
		}
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post->post_type !== 'post' ) {
			return;
		}
		$this->indexed[ $post_id ] = true;
		$this->reindex( $post );
	}

	public function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || $post->post_type !== 'post' ) {
			return;
		}
		$post_id = (int) $post->ID;
		if ( isset( $this->indexed[ $post_id ] ) || isset( $this->deleted[ $post_id ] ) ) {
			return;
		}
		if ( in_array( $new_status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
			// Deletion/trash is handled by a separate hook (deleted_post).
			return;
		}
		$this->indexed[ $post_id ] = true;
		$this->reindex( $post );
	}

	public function on_delete( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || isset( $this->deleted[ $post_id ] ) ) {
			return;
		}
		$this->deleted[ $post_id ] = true;
		$this->delete_post( $post_id );
	}

	/* ============================================================
	 *  Index / delete
	 * ============================================================ */

	/**
	 * Rewrites the index rows of a single post.
	 *
	 * The operation is a diff, not wipe-and-insert: rows for links that
	 * disappeared are deleted, links that are still there are upserted, so
	 * a crash half way through never leaves the post without links and a
	 * repeated run produces the same result (idempotent).
	 *
	 * @return bool False when a database write failed.
	 */
	public function reindex( WP_Post $post ): bool {
		return $this->reindex_count( $post ) >= 0;
	}

	/**
	 * Same as reindex(), but reports how many link rows the post produced.
	 *
	 * A post without external links legitimately produces 0 — the rebuild
	 * needs that number to tell "indexed, nothing to store" from "the indexer
	 * writes nothing at all", which is a symptom.
	 *
	 * @return int Rows written, or -1 when a database write failed.
	 */
	public function reindex_count( WP_Post $post ): int {
		global $wpdb;
		$table = $this->table();

		$rows = $this->build_rows( $post );

		/* ---------- 1. drop links that are no longer in the content ---------- */

		$hashes = array_keys( $rows );

		if ( empty( $hashes ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $table, [ 'post_id' => $post->ID ], [ '%d' ] );
		} else {
			foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
				$ph     = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$params = array_merge( [ $post->ID ], $chunk );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE post_id = %d AND url_hash NOT IN ({$ph})",
						$params
					)
				);
			}
		}

		if ( empty( $rows ) ) {
			return $wpdb->last_error === '' ? 0 : -1;
		}

		/* ---------- 2. upsert current links ---------- */

		$ok = true;

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
						(post_id, url, url_hash, url_norm, url_norm_hash, scheme, host, domain, path, anchor, anchor_kind, context, created_at)
					VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE
						url = VALUES(url),
						url_norm = VALUES(url_norm),
						url_norm_hash = VALUES(url_norm_hash),
						scheme = VALUES(scheme),
						host = VALUES(host),
						domain = VALUES(domain),
						path = VALUES(path),
						anchor = VALUES(anchor),
						anchor_kind = VALUES(anchor_kind),
						context = VALUES(context)",
					array_merge( $row['values'], [ current_time( 'mysql', true ) ] )
				)
			);

			if ( false === $result ) {
				$ok = false;
			}
		}

		return $ok ? count( $rows ) : -1;
	}

	/**
	 * Normalised rows for the links of a post, keyed by url_hash.
	 *
	 * Row values follow the INSERT column order in reindex():
	 * post_id, url, url_hash, url_norm, url_norm_hash, scheme, host, domain,
	 * path, anchor, anchor_kind, context.
	 *
	 * @return array<string,array{kind:string,values:array<int,mixed>}>
	 */
	private function build_rows( WP_Post $post ): array {
		$content = (string) $post->post_content;
		if ( $content === '' || stripos( $content, '<a ' ) === false ) {
			return [];
		}

		$links = $this->extract_links( $content );
		if ( empty( $links ) ) {
			return [];
		}

		$rows = [];

		foreach ( $links as $link ) {
			$raw  = (string) $link['url'];
			$hash = md5( $raw );

			$parts = wp_parse_url( $raw );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}

			$anchor = (string) $link['anchor'];
			$kind   = self::anchor_kind( $anchor, $raw );

			// The same URL twice: keep the most useful anchor (phrase over
			// url over empty) instead of the first occurrence, otherwise a
			// pasted address can hide the phrase that points at the same page.
			if ( isset( $rows[ $hash ] ) && self::anchor_rank( $kind ) <= self::anchor_rank( $rows[ $hash ]['kind'] ) ) {
				continue;
			}

			$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
			$host   = strtolower( (string) $parts['host'] );
			$host   = preg_replace( '/^www\./i', '', $host );
			$domain = self::registrable_domain( $host );
			$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';

			$norm      = WSS_Refang::normalize_url( $raw );
			$norm_hash = $norm !== '' ? md5( $norm ) : '';

			$rows[ $hash ] = [
				'kind'   => $kind,
				'values' => [
					(int) $post->ID,
					$raw,
					$hash,
					$norm,
					$norm_hash,
					$scheme,
					$host,
					$domain,
					$path,
					$anchor,
					$kind,
					(string) $link['context'],
				],
			];
		}

		return $rows;
	}

	public function delete_post( int $post_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . WSS_Config::LINKS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );
	}

	/* ============================================================
	 *  Parsing <a href> from HTML
	 * ============================================================ */

	/**
	 * @return array<int,array{url:string,anchor:string,context:string}>
	 */
	private function extract_links( string $html ): array {
		if ( $html === '' || stripos( $html, '<a ' ) === false ) {
			return [];
		}

		// Opening tags only: a nested <a> (broken markup in old posts) used to
		// be swallowed by the outer link, so the inner URL never reached the
		// index. The anchor text is read up to the first </a> after each one.
		if ( ! preg_match_all(
			'/<a\b[^>]*?\bhref\s*=\s*(["\'])(.+?)\1[^>]*>/is',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			return [];
		}

		$out  = [];
		$len  = strlen( $html );
		$seen = [];

		foreach ( $matches[2] as $i => $href_match ) {
			$href = html_entity_decode( trim( (string) $href_match[0] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $href === '' || $href[0] === '#' || stripos( $href, 'javascript:' ) === 0 ) {
				continue;
			}
			if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $href ) ) {
				continue; // absolute URLs only
			}

			$offset = (int) ( $matches[0][ $i ][1] ?? 0 );
			$length = strlen( (string) ( $matches[0][ $i ][0] ?? '' ) );

			$rest        = substr( $html, $offset + $length );
			$close       = stripos( $rest, '</a>' );
			$anchor_html = ( false === $close ) ? '' : substr( $rest, 0, $close );

			$anchor = trim( wp_strip_all_tags( $anchor_html ) );
			$anchor = html_entity_decode( $anchor, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$anchor = trim( preg_replace( '/\s+/u', ' ', $anchor ) );
			if ( mb_strlen( $anchor ) > WSS_Config::LINK_ANCHOR_MAX ) {
				$anchor = mb_substr( $anchor, 0, WSS_Config::LINK_ANCHOR_MAX );
			}

			$before = max( 0, $offset - WSS_Config::LINK_CONTEXT_CHARS );
			$after  = min( $len, $offset + $length + WSS_Config::LINK_CONTEXT_CHARS );
			$raw_ctx = substr( $html, $before, $after - $before );

			$context = trim( wp_strip_all_tags( $raw_ctx ) );
			$context = html_entity_decode( $context, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$context = trim( preg_replace( '/\s+/u', ' ', $context ) );
			if ( mb_strlen( $context ) > WSS_Config::LINK_CONTEXT_MAX ) {
				$context = mb_substr( $context, 0, WSS_Config::LINK_CONTEXT_MAX );
			}

			$dedup_key = $href . "\x00" . $anchor;
			if ( isset( $seen[ $dedup_key ] ) ) {
				continue;
			}
			$seen[ $dedup_key ] = true;

			$out[] = [
				'url'     => $href,
				'anchor'  => $anchor,
				'context' => $context,
			];
		}

		return $out;
	}

	/* ============================================================
	 *  Anchor kind
	 * ============================================================ */

	/**
	 * Kind of anchor text: phrase, url or empty.
	 *
	 * Old posts often use the address itself as the anchor
	 * (`<a href="https://x/y">https://x/y</a>`). Such a link is indexed like
	 * any other — the kind only makes it findable (anchor_kind=url) and lets a
	 * report tell a real phrase from a pasted address.
	 *
	 * @param string $anchor Anchor text as stored.
	 * @param string $url    Target URL of the link.
	 */
	public static function anchor_kind( string $anchor, string $url ): string {
		$anchor = trim( $anchor );

		if ( $anchor === '' ) {
			return 'empty';
		}

		if ( ! self::looks_like_url( $anchor ) ) {
			return 'phrase';
		}

		$anchor_norm = self::url_for_compare( $anchor );
		$url_norm    = self::url_for_compare( $url );

		if ( $anchor_norm === '' || $url_norm === '' ) {
			return 'phrase';
		}

		if ( $anchor_norm === $url_norm
			|| strpos( $url_norm, $anchor_norm ) === 0
			|| strpos( $anchor_norm, $url_norm ) === 0 ) {
			return 'url';
		}

		return 'phrase';
	}

	/**
	 * Whether the value looks like an address rather than a phrase.
	 */
	private static function looks_like_url( string $value ): bool {
		$value = trim( $value );

		if ( $value === '' ) {
			return false;
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			return true;
		}

		// Bare host, optionally with a path: "pwn.ai/upload2shell".
		return (bool) preg_match( '#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $value );
	}

	/**
	 * Comparison form of a URL or an address-like anchor.
	 */
	private static function url_for_compare( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $value );
		$value = (string) preg_replace( '/^www\./i', '', $value );
		$value = (string) preg_replace( '#/+#', '/', $value );

		return rtrim( $value, '/' );
	}

	/**
	 * Preference of an anchor kind when the same URL is linked twice.
	 */
	private static function anchor_rank( string $kind ): int {
		switch ( $kind ) {
			case 'phrase':
				return 2;
			case 'url':
				return 1;
			default:
				return 0;
		}
	}

	/* ============================================================
	 *  Registrable domain (eTLD+1 heuristic)
	 * ============================================================ */

	public static function registrable_domain( string $host ): string {
		$host = strtolower( trim( $host, '.' ) );
		if ( $host === '' ) {
			return '';
		}
		$host = preg_replace( '/^www\./i', '', $host );

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}

		$parts = explode( '.', $host );
		$n     = count( $parts );
		if ( $n <= 2 ) {
			return $host;
		}

		// Known second-level TLDs (not a full PSL, but covers common cases).
		$known_sld = [ 'co', 'com', 'org', 'net', 'gov', 'edu', 'ac', 'sch' ];
		$last = $parts[ $n - 1 ];
		$prev = $parts[ $n - 2 ];

		if ( strlen( $last ) === 2 && in_array( $prev, $known_sld, true ) && $n >= 3 ) {
			return implode( '.', array_slice( $parts, -3 ) );
		}

		return implode( '.', array_slice( $parts, -2 ) );
	}
}
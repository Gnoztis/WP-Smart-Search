<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Links_Indexer {

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
	 *  Установка / обновление таблицы
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
			context TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY url_hash (url_hash),
			KEY url_norm_hash (url_norm_hash),
			KEY host (host),
			KEY domain (domain),
			UNIQUE KEY post_url (post_id, url_hash)
		) $charset;";

		dbDelta( $sql );
		update_option( WSS_Config::LINKS_TABLE_VER_OPTION, WSS_Config::LINKS_TABLE_VER, true );
	}

	public function maybe_install(): void {
		$current = (string) get_option( WSS_Config::LINKS_TABLE_VER_OPTION, '' );
		if ( $current === WSS_Config::LINKS_TABLE_VER ) {
			return;
		}
		self::install_table();
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
			// На удаление/мусор реагируем отдельным hook-ом (deleted_post).
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

	public function reindex( WP_Post $post ): void {
		global $wpdb;
		$table = $wpdb->prefix . WSS_Config::LINKS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, [ 'post_id' => $post->ID ], [ '%d' ] );

		$content = (string) $post->post_content;
		if ( $content === '' || stripos( $content, '<a ' ) === false ) {
			return;
		}

		$links = $this->extract_links( $content );
		if ( empty( $links ) ) {
			return;
		}

		$seen = [];
		foreach ( $links as $link ) {
			$raw = $link['url'];
			$hash = md5( $raw );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;

			$parts = wp_parse_url( $raw );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}

			$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
			$host   = strtolower( (string) $parts['host'] );
			$host   = preg_replace( '/^www\./i', '', $host );
			$domain = self::registrable_domain( $host );
			$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';

			$norm      = WSS_Refang::normalize_url( $raw );
			$norm_hash = $norm !== '' ? md5( $norm ) : '';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, [
				'post_id'       => $post->ID,
				'url'           => $raw,
				'url_hash'      => $hash,
				'url_norm'      => $norm,
				'url_norm_hash' => $norm_hash,
				'scheme'        => $scheme,
				'host'          => $host,
				'domain'        => $domain,
				'path'          => $path,
				'anchor'        => $link['anchor'],
				'context'       => $link['context'],
				'created_at'    => current_time( 'mysql', true ),
			], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
		}
	}

	public function delete_post( int $post_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . WSS_Config::LINKS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );
	}

	/* ============================================================
	 *  Парсинг <a href> из HTML
	 * ============================================================ */

	/**
	 * @return array<int,array{url:string,anchor:string,context:string}>
	 */
	private function extract_links( string $html ): array {
		if ( $html === '' || stripos( $html, '<a ' ) === false ) {
			return [];
		}

		if ( ! preg_match_all(
			'/<a\b[^>]*?\bhref\s*=\s*(["\'])(.+?)\1[^>]*>(.*?)<\/a>/is',
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
				continue; // только абсолютные
			}

			$anchor_html = (string) ( $matches[3][ $i ][0] ?? '' );
			$anchor = trim( wp_strip_all_tags( $anchor_html ) );
			$anchor = html_entity_decode( $anchor, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$anchor = trim( preg_replace( '/\s+/u', ' ', $anchor ) );
			if ( mb_strlen( $anchor ) > WSS_Config::LINK_ANCHOR_MAX ) {
				$anchor = mb_substr( $anchor, 0, WSS_Config::LINK_ANCHOR_MAX );
			}

			$offset = (int) ( $matches[0][ $i ][1] ?? 0 );
			$length = strlen( (string) ( $matches[0][ $i ][0] ?? '' ) );
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
	 *  Registrable domain (eTLD+1 эвристика)
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

		// Известные second-level TLD (не полный PSL, но покрывает частые случаи).
		$known_sld = [ 'co', 'com', 'org', 'net', 'gov', 'edu', 'ac', 'sch' ];
		$last = $parts[ $n - 1 ];
		$prev = $parts[ $n - 2 ];

		if ( strlen( $last ) === 2 && in_array( $prev, $known_sld, true ) && $n >= 3 ) {
			return implode( '.', array_slice( $parts, -3 ) );
		}

		return implode( '.', array_slice( $parts, -2 ) );
	}
}
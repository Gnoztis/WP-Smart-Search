<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Refang {

	/**
	 * Deobfuscation: hXXp → http, [.] → ., [:] → :, [@] → @, [://] → ://, (.) → .
	 * Order: long composites first, then single tokens. All operations are plain str_replace/preg_replace.
	 */
	public static function refang_query( string $q ): string {
		if ( $q === '' ) {
			return '';
		}

		$q = str_replace( '[://]', '://', $q );
		$q = str_replace( '[.]',   '.',   $q );
		$q = str_replace( '[:]',   ':',   $q );
		$q = str_replace( '[@]',   '@',   $q );
		$q = str_replace( '(.)',   '.',   $q );

		$q = (string) preg_replace( '/hxxp/i', 'http', $q );

		return (string) apply_filters( 'wss_refang_query', $q );
	}

	/**
	 * URL normalisation for exact match:
	 *  - hxxp/[.]/(.) → http/./.;
	 *  - the #… fragment is removed;
	 *  - https → http (single scheme);
	 *  - www is removed, host is lower-cased;
	 *  - trailing slash removed, empty path → '/';
	 *  - UTM and ad parameters are stripped;
	 *  - ports 80/443 are dropped.
	 *
	 * For ?p=ID and ?page_id=ID the comparison is by ID (see find_exact_post),
	 * so normalize_url returns the regular URL form; the ID branch is handled separately.
	 *
	 * Path and slug are not lower-cased.
	 * Empty string when the value is not a URL (no host).
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}

		// Refang before parse_url (hxxp is not recognised by wp_parse_url).
		$url = self::refang_query( $url );

		// Strip the fragment.
		$hash_pos = strpos( $url, '#' );
		if ( $hash_pos !== false ) {
			$url = substr( $url, 0, $hash_pos );
		}
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}

		// No scheme but the value looks like a domain — prepend http://.
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$first_seg = explode( '/', $url, 2 )[0];
			if ( strpos( $first_seg, '.' ) !== false && ! preg_match( '/\s/', $first_seg ) ) {
				$url = 'http://' . $url;
			}
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$host = preg_replace( '/^www\./i', '', $host );

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'http';
		if ( $scheme === 'https' ) {
			$scheme = 'http';
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$path = preg_replace( '#/+#', '/', $path );
		$path = rtrim( $path, '/' );
		if ( $path === '' ) {
			$path = '/';
		}

		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $args );
			foreach ( WSS_Config::TRACKING_PARAMS as $tp ) {
				unset( $args[ $tp ] );
			}
			if ( ! empty( $args ) ) {
				$query = '?' . http_build_query( $args );
			}
		}

		$port = '';
		if ( ! empty( $parts['port'] ) && ! in_array( (int) $parts['port'], [ 80, 443 ], true ) ) {
			$port = ':' . (int) $parts['port'];
		}

		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * Extracts a numeric ID from ?p=123 or ?page_id=123 URLs.
	 * Used for exact matching by ID.
	 */
	public static function extract_id( string $url ): int {
		$url = self::refang_query( $url );
		if ( preg_match( '/[?&](?:p|page_id)=(\d+)/', $url, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Extracts the slug (last path segment) from a URL.
	 * Returns an empty string when it cannot.
	 */
	public static function extract_slug( string $url ): string {
		$url = self::refang_query( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}
		$path = trim( (string) $parts['path'], '/' );
		if ( $path === '' ) {
			return '';
		}
		$segments = explode( '/', $path );
		$last     = end( $segments );
		return is_string( $last ) ? $last : '';
	}
}
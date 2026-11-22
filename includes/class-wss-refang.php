<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Refang {

	/**
	 * Деобфускация: hXXp → http, [.] → ., [:] → :, [@] → @, [://] → ://, (.) → .
	 * Порядок: длинные составные → одиночные. Все операции — безопасные str_replace/preg_replace.
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
	 * Нормализация URL для exact-match (ТЗ п.6.2):
	 *  - hxxp/[.]/(.) → http/./.;
	 *  - удаляется фрагмент #…;
	 *  - https → http (единый scheme);
	 *  - www удаляется, host — нижний регистр;
	 *  - trailing slash убирается, пустой path → '/';
	 *  - UTM и рекламные параметры вырезаются;
	 *  - порт 80/443 отбрасывается.
	 *
	 * Для ?p=ID и ?page_id=ID сравнение идёт по ID (см. find_exact_post),
	 * поэтому normalize_url возвращает обычную URL-форму; ID-ветка обрабатывается отдельно.
	 *
	 * Путь и slug не приводятся к нижнему регистру (ТЗ п.6.2).
	 * Пустая строка — если это не URL (нет host).
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}

		// Refang до parse_url (hxxp не распознаётся wp_parse_url).
		$url = self::refang_query( $url );

		// Убираем фрагмент.
		$hash_pos = strpos( $url, '#' );
		if ( $hash_pos !== false ) {
			$url = substr( $url, 0, $hash_pos );
		}
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}

		// Если схема отсутствует, но строка похожа на домен — добавляем http://.
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
	 * Извлекает числовой ID из URL вида ?p=123 или ?page_id=123.
	 * Используется для exact-match по ID (ТЗ п.6.2).
	 */
	public static function extract_id( string $url ): int {
		$url = self::refang_query( $url );
		if ( preg_match( '/[?&](?:p|page_id)=(\d+)/', $url, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Извлекает slug (последний сегмент пути) из URL.
	 * Возвращает пустую строку, если не удаётся.
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
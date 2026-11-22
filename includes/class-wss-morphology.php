<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight morphology for search.
 *
 * The goal is not linguistic accuracy but matching word forms:
 * the query "sandbox bypass" must also find "bypass of the sandbox"
 * and so on. Every query word is therefore reduced to its stem and
 * text is matched by stem prefix.
 *
 * The SQL filter is always broader than the PHP filter (LIKE '%stem%'),
 * and the regex in the engine makes the final decision.
 */
final class WSS_Morphology {

	/** Endings stripped first (longest first). These are data, not comments. */
	private const RU_ENDINGS = [
		'иями', 'ями', 'ами', 'ыми', 'ими',
		'ность', 'ности', 'ость', 'ости',
		'ение', 'ения', 'ении', 'ений',
		'ание', 'ания', 'ании', 'аний',
		'ого', 'ому', 'его', 'ему',
		'ых', 'им', 'ым', 'ой', 'ый', 'ий', 'ая', 'ое', 'ые', 'ую', 'юю',
		'ом', 'ем', 'ам', 'ям', 'ах', 'ях', 'ов', 'ев',
		'ия', 'ию', 'ии', 'ие', 'ью', 'ья', 'ье',
		'ть', 'тся', 'ся', 'сь',
		'а', 'я', 'о', 'е', 'и', 'ы', 'у', 'ю', 'ь', 'й',
	];

	private const EN_ENDINGS = [ 'ing', 'edly', 'ed', 'es', 's' ];

	/**
	 * Minimum term length that enables prefix matching.
	 */
	private static function prefix_min_len(): int {
		return WSS_Config::STEM_MIN_LEN;
	}

	/**
	 * Word stem. Short and non-letter words (URLs, e-mails, numbers)
	 * are returned lower-cased and unchanged.
	 */
	public static function stem( string $word ): string {
		$word = trim( $word );
		if ( $word === '' ) {
			return '';
		}

		$lower = mb_strtolower( $word, 'UTF-8' );

		if ( preg_match( '/^[\p{Cyrillic}]+$/u', $lower ) ) {
			return self::stem_ru( $lower );
		}
		if ( preg_match( '/^[a-z]+$/i', $lower ) ) {
			return self::stem_en( $lower );
		}
		return $lower;
	}

	/**
	 * String for LIKE '%...%'.
	 */
	public static function search_term( string $word ): string {
		$stem = self::stem( $word );
		return $stem !== '' ? $stem : mb_strtolower( trim( $word ), 'UTF-8' );
	}

	/**
	 * Regular expression to test a match in text.
	 *
	 * Accepts an already normalised term (see terms()); no second
	 * stemming pass happens here.
	 *
	 * A long enough term is matched by prefix
	 * (stem + any letters), otherwise as a whole word.
	 */
	public static function pattern( string $term ): string {
		$term = mb_strtolower( trim( $term ), 'UTF-8' );
		if ( $term === '' ) {
			return '/$^/u';
		}

		$quoted = preg_quote( $term, '/' );

		if ( mb_strlen( $term ) >= self::prefix_min_len() ) {
			return '/(?<![\p{L}\p{N}_])' . $quoted . '/iu';
		}

		return '/(?<![\p{L}\p{N}_])' . $quoted . '(?![\p{L}\p{N}_])/iu';
	}

	/**
	 * Normalised query terms without duplicates (order preserved).
	 *
	 * @param string[] $words
	 * @return string[]
	 */
	public static function terms( array $words ): array {
		$out  = [];
		$seen = [];
		foreach ( $words as $word ) {
			$term = self::search_term( (string) $word );
			if ( $term === '' || isset( $seen[ $term ] ) ) {
				continue;
			}
			$seen[ $term ] = true;
			$out[]         = $term;
		}
		return $out;
	}

	/* ============================================================
	 *  Stemmers
	 * ============================================================ */

	private static function stem_ru( string $w ): string {
		foreach ( self::RU_ENDINGS as $ending ) {
			$len = mb_strlen( $ending );
			if ( mb_strlen( $w ) > $len + 2 && mb_substr( $w, -$len ) === $ending ) {
				$stem = mb_substr( $w, 0, -$len );
				return mb_strlen( $stem ) >= 3 ? $stem : $w;
			}
		}
		return $w;
	}

	private static function stem_en( string $w ): string {
		foreach ( self::EN_ENDINGS as $ending ) {
			$len = strlen( $ending );
			if ( strlen( $w ) > $len + 3 && substr( $w, -$len ) === $ending ) {
				$stem = substr( $w, 0, -$len );
				// running → runn → run
				$stem = preg_replace( '/([bdfglmnprt])\1$/', '$1', $stem );
				return strlen( (string) $stem ) >= 3 ? (string) $stem : $w;
			}
		}
		return $w;
	}
}

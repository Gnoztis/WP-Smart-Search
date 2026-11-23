<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Rate_Limiter {

	private WSS_Cache $cache;

	public function __construct( WSS_Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Counts the request and reports the budget.
	 *
	 * @param int    $max    Requests allowed per window.
	 * @param string $bucket Separate counter (the tags endpoint has its own).
	 * @return array{allowed:bool,limit:int,remaining:int,reset:int}
	 */
	public function check( int $max = WSS_Config::RATE_LIMIT_MAX, string $bucket = 'rl' ): array {
		$uid = get_current_user_id();

		$out = [
			'allowed'   => false,
			'limit'     => $max,
			'remaining' => 0,
			'reset'     => time() + WSS_Config::RATE_LIMIT_WIN,
		];

		if ( $uid <= 0 ) {
			return $out;
		}

		$res = $this->cache->rate_limit_incr( $bucket . ':' . $uid, WSS_Config::RATE_LIMIT_WIN );

		$out['allowed']   = $res['count'] <= $max;
		$out['remaining'] = max( 0, $max - $res['count'] );
		$out['reset']     = time() + (int) $res['ttl'];

		return $out;
	}

	public function send_headers( array $rl ): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-RateLimit-Limit: ' . (int) $rl['limit'] );
		header( 'X-RateLimit-Remaining: ' . (int) $rl['remaining'] );
		header( 'X-RateLimit-Reset: ' . (int) $rl['reset'] );
		if ( empty( $rl['allowed'] ) ) {
			header( 'Retry-After: ' . max( 1, (int) ( $rl['reset'] - time() ) ) );
		}
	}
}
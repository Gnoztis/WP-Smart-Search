<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Rate_Limiter {

	private WSS_Cache $cache;

	public function __construct( WSS_Cache $cache ) {
		$this->cache = $cache;
	}

	public function check(): array {
		$uid = get_current_user_id();

		$out = [
			'allowed'   => false,
			'limit'     => WSS_Config::RATE_LIMIT_MAX,
			'remaining' => 0,
			'reset'     => time() + WSS_Config::RATE_LIMIT_WIN,
		];

		if ( $uid <= 0 ) {
			return $out;
		}

		$res = $this->cache->rate_limit_incr( 'rl:' . $uid, WSS_Config::RATE_LIMIT_WIN );

		$out['allowed']   = $res['count'] <= WSS_Config::RATE_LIMIT_MAX;
		$out['remaining'] = max( 0, WSS_Config::RATE_LIMIT_MAX - $res['count'] );
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
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Cache {

	private static ?bool   $redis_ready = null;
	private static ?Redis  $redis       = null;
	private static ?string $ns          = null;
	private static bool    $purge_done  = false;

	public function register_hooks(): void {
		add_action( 'save_post',        [ $this, 'maybe_bump_after_post_save' ], 10, 3 );
		add_action( 'deleted_post',     [ $this, 'bump_generation' ] );
		add_action( 'set_object_terms', [ $this, 'bump_generation' ] );
		add_action( 'created_term',     [ $this, 'bump_generation' ] );
		add_action( 'edited_term',      [ $this, 'bump_generation' ] );
		add_action( 'delete_term',      [ $this, 'bump_generation' ] );
		add_action( 'transition_post_status', [ $this, 'handle_status_transition' ], 10, 3 );
	}

	public function generation(): int {
		return (int) get_option( WSS_Config::CACHE_GEN_OPTION, 0 );
	}

	public function bump_generation(): void {
		$v = (int) get_option( WSS_Config::CACHE_GEN_OPTION, 0 );
		update_option( WSS_Config::CACHE_GEN_OPTION, $v + 1, true );
	}

	public function maybe_bump_after_post_save( $post_id, $post = null, $update = null ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post === null ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof WP_Post || $post->post_type !== 'post' ) {
			return;
		}
		$this->bump_generation();
	}

	public function handle_status_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || $post->post_type !== 'post' ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( in_array( $new_status, [ 'auto-draft', 'inherit' ], true ) ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}
		$affects = in_array( $new_status, WSS_Config::PURGE_STATUSES, true )
			|| in_array( $old_status, WSS_Config::PURGE_STATUSES, true );
		if ( ! $affects ) {
			return;
		}
		if ( ! apply_filters( 'wss_purge_cache_on_transition', true, $new_status, $old_status, $post ) ) {
			return;
		}
		$this->purge_all();
	}

	private function purge_all(): void {
		if ( self::$purge_done ) {
			return;
		}
		self::$purge_done = true;
		$this->purge_redis();
		$this->purge_transients();
		$this->bump_generation();
	}

	private function purge_redis(): void {
		if ( ! $this->redis_available() || self::$redis === null ) {
			return;
		}
		$pattern = $this->redis_key( 'wss_*' );
		try {
			$iterator = null;
			do {
				$keys = self::$redis->scan( $iterator, $pattern, 500 );
				if ( is_array( $keys ) && ! empty( $keys ) ) {
					self::$redis->del( $keys );
				}
			} while ( $iterator > 0 );
		} catch ( Throwable $e ) {
			// TTL.
		}
	}

	private function purge_transients(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_wss_' ) . '%'
			)
		);
		if ( ! is_array( $names ) || empty( $names ) ) {
			return;
		}
		$prefix = '_transient_';
		$plen   = strlen( $prefix );
		foreach ( $names as $name ) {
			if ( strpos( $name, $prefix ) !== 0 ) {
				continue;
			}
			$t = substr( $name, $plen );
			if ( $t !== '' ) {
				delete_transient( $t );
			}
		}
	}

	private function redis_available(): bool {
		if ( self::$redis_ready !== null ) {
			return self::$redis_ready;
		}
		self::$redis_ready = false;
		if ( ! extension_loaded( 'redis' ) || ! class_exists( 'Redis' ) ) {
			return false;
		}
		$cfg = $this->redis_config();
		try {
			$client = new Redis();
			$ok     = @$client->connect( $cfg['host'], $cfg['port'], $cfg['timeout'] );
			if ( ! $ok ) {
				return false;
			}
			if ( $cfg['password'] !== '' && @$client->auth( $cfg['password'] ) !== true ) {
				return false;
			}
			if ( $cfg['database'] > 0 && ! @$client->select( $cfg['database'] ) ) {
				return false;
			}
			self::$redis       = $client;
			self::$redis_ready = true;
			return true;
		} catch ( Throwable $e ) {
			self::$redis = null;
			return false;
		}
	}

	private function redis_config(): array {
		$cfg = [
			'host'     => '127.0.0.1',
			'port'     => 6379,
			'timeout'  => 0.1,
			'password' => '',
			'database' => 0,
		];
		if ( defined( 'WSS_REDIS_HOST' ) )     { $cfg['host']     = (string) WSS_REDIS_HOST; }
		if ( defined( 'WSS_REDIS_PORT' ) )     { $cfg['port']     = (int)    WSS_REDIS_PORT; }
		if ( defined( 'WSS_REDIS_TIMEOUT' ) )  { $cfg['timeout']  = (float)  WSS_REDIS_TIMEOUT; }
		if ( defined( 'WSS_REDIS_PASSWORD' ) ) { $cfg['password'] = (string) WSS_REDIS_PASSWORD; }
		if ( defined( 'WSS_REDIS_DATABASE' ) ) { $cfg['database'] = (int)    WSS_REDIS_DATABASE; }
		return (array) apply_filters( 'wss_redis_config', $cfg );
	}

	public function redis_key( string $key ): string {
		if ( self::$ns === null ) {
			self::$ns = substr( md5( home_url( '/' ) ), 0, 12 );
		}
		return 'wss:' . self::$ns . ':' . $key;
	}

	public function get( string $key ): ?array {
		if ( $this->redis_available() && self::$redis !== null ) {
			try {
				$raw = self::$redis->get( $this->redis_key( $key ) );
				if ( is_string( $raw ) && $raw !== '' ) {
					$data = @unserialize( $raw, [ 'allowed_classes' => false ] );
					if ( is_array( $data ) ) {
						return $data;
					}
				}
				return null;
			} catch ( Throwable $e ) {
				// fallback
			}
		}
		$val = get_transient( $key );
		return is_array( $val ) ? $val : null;
	}

	public function set( string $key, array $value, int $ttl ): void {
		$ttl = max( 1, $ttl );
		if ( $this->redis_available() && self::$redis !== null ) {
			try {
				$ok = self::$redis->setEx(
					$this->redis_key( $key ),
					$ttl,
					serialize( $value )
				);
				if ( $ok ) {
					return;
				}
			} catch ( Throwable $e ) {
				// fallback
			}
		}
		set_transient( $key, $value, $ttl );
	}

	public function rate_limit_incr( string $key, int $ttl ): array {
		if ( $this->redis_available() && self::$redis !== null ) {
			try {
				$rkey  = $this->redis_key( $key );
				$count = (int) self::$redis->incr( $rkey );
				if ( $count === 1 ) {
					self::$redis->expire( $rkey, $ttl );
				}
				$t = (int) self::$redis->ttl( $rkey );
				if ( $t < 0 ) {
					$t = $ttl;
				}
				return [ 'count' => $count, 'ttl' => $t ];
			} catch ( Throwable $e ) {
				// fallback
			}
		}
		$count = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, $ttl );
		return [ 'count' => $count, 'ttl' => $ttl ];
	}
}
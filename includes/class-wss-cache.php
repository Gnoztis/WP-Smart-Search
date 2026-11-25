<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache storage for search results.
 *
 * The only backend is the PHP redis extension (phpredis) reached over a
 * direct connection: no other plugin, drop-in or WordPress object cache is
 * required or relied upon.
 *
 * Resolution, lazily on the first cache operation:
 *
 *  1. redis — extension_loaded( 'redis' ) is true and the server from
 *     WSS_REDIS_* / the wss_redis_config filter answers.
 *  2. none — the extension is missing or Redis does not answer. The search
 *     works without cache and never writes cache entries into wp_options.
 *
 * The probe happens at most once per request and is skipped for RETRY_TTL
 * seconds after a failure, so a dead Redis never slows requests down with
 * connection timeouts.
 *
 * Transients are not used as a cache fallback on purpose: they live in the
 * options table, which costs more than the query they are supposed to save.
 * They are still used for rate limit counters when Redis is unavailable,
 * because a limiter without storage would silently disappear.
 */
final class WSS_Cache {

	/** Autoloaded option: unix time until which Redis is not probed. */
	private const DOWN_OPTION = 'wss_redis_down_until';

	/** Seconds to skip Redis after a failed connection attempt. */
	private const RETRY_TTL = 60;

	/** Upper bound for a single connect() call, seconds. */
	private const CONNECT_TIMEOUT = 0.2;

	private static ?Redis  $redis      = null;
	private static ?string $backend    = null;
	private static ?string $ns         = null;
	private static bool    $purge_done = false;

	public function register_hooks(): void {
		add_action( 'save_post',        [ $this, 'maybe_bump_after_post_save' ], 10, 3 );
		add_action( 'deleted_post',     [ $this, 'bump_generation' ] );
		add_action( 'set_object_terms', [ $this, 'bump_generation' ] );
		add_action( 'created_term',     [ $this, 'bump_generation' ] );
		add_action( 'edited_term',      [ $this, 'bump_generation' ] );
		add_action( 'delete_term',      [ $this, 'bump_generation' ] );
		add_action( 'transition_post_status', [ $this, 'handle_status_transition' ], 10, 3 );
	}

	/* ============================================================
	 *  Backend resolution
	 * ============================================================ */

	/**
	 * Active backend: redis or none.
	 */
	public static function backend(): string {
		if ( self::$backend !== null ) {
			return self::$backend;
		}

		if ( self::redis_client() instanceof Redis ) {
			self::$backend = 'redis';
			return self::$backend;
		}

		self::$backend = 'none';
		return self::$backend;
	}

	public static function is_available(): bool {
		return 'none' !== self::backend();
	}

	/**
	 * Lazily connects to Redis, honouring the failure backoff.
	 */
	private static function redis_client(): ?Redis {
		if ( self::$redis instanceof Redis ) {
			return self::$redis;
		}

		if ( ! extension_loaded( 'redis' ) || ! class_exists( 'Redis' ) ) {
			return null;
		}

		$down_until = (int) get_option( self::DOWN_OPTION, 0 );
		if ( $down_until > time() ) {
			return null;
		}

		$config = self::redis_config();

		try {
			$client = new Redis();
			if ( ! $client->connect( (string) $config['host'], (int) $config['port'], (float) $config['timeout'] ) ) {
				self::mark_down( 'connect() returned false' );
				return null;
			}
			if ( $config['password'] !== '' && ! $client->auth( (string) $config['password'] ) ) {
				self::mark_down( 'AUTH failed' );
				return null;
			}
			if ( (int) $config['database'] > 0 && ! $client->select( (int) $config['database'] ) ) {
				self::mark_down( 'SELECT failed' );
				return null;
			}

			self::$redis = $client;
			return $client;
		} catch ( Throwable $e ) {
			self::mark_down( $e->getMessage() );
			return null;
		}
	}

	/**
	 * Records a failed Redis operation and stops probing for RETRY_TTL.
	 */
	private static function mark_down( string $reason = '' ): void {
		self::$redis   = null;
		self::$backend = 'none';

		update_option( self::DOWN_OPTION, time() + self::RETRY_TTL, true );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $reason !== '' ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( '[wp-smart-search] cache backend unavailable: ' . $reason );
		}
	}

	private static function redis_config(): array {
		$config = [
			'host'     => '127.0.0.1',
			'port'     => 6379,
			'timeout'  => self::CONNECT_TIMEOUT,
			'password' => '',
			'database' => 0,
		];
		if ( defined( 'WSS_REDIS_HOST' ) )     { $config['host']     = (string) WSS_REDIS_HOST; }
		if ( defined( 'WSS_REDIS_PORT' ) )     { $config['port']     = (int)    WSS_REDIS_PORT; }
		if ( defined( 'WSS_REDIS_TIMEOUT' ) )  { $config['timeout']  = (float)  WSS_REDIS_TIMEOUT; }
		if ( defined( 'WSS_REDIS_PASSWORD' ) ) { $config['password'] = (string) WSS_REDIS_PASSWORD; }
		if ( defined( 'WSS_REDIS_DATABASE' ) ) { $config['database'] = (int)    WSS_REDIS_DATABASE; }

		return (array) apply_filters( 'wss_redis_config', $config );
	}

	/* ============================================================
	 *  Invalidation
	 * ============================================================ */

	public function generation(): int {
		return (int) get_option( WSS_Config::CACHE_GEN_OPTION, 0 );
	}

	public function bump_generation(): void {
		$value = (int) get_option( WSS_Config::CACHE_GEN_OPTION, 0 );
		update_option( WSS_Config::CACHE_GEN_OPTION, $value + 1, true );

		// A saved/deleted post changes the links index too, so the cached
		// status snapshot must not outlive it.
		if ( class_exists( 'WSS_Index_Backfill' ) ) {
			WSS_Index_Backfill::flush_status();
		}
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

	/**
	 * Invalidation is generation based: every key carries the generation,
	 * so bumping it makes all existing entries unreachable. Entries also
	 * carry a TTL, so Redis does not accumulate dead keys indefinitely.
	 *
	 * A SCAN + DEL sweep is deliberately not performed here: it is more
	 * expensive than letting the TTL expire and adds no correctness.
	 */
	private function purge_all(): void {
		if ( self::$purge_done ) {
			return;
		}
		self::$purge_done = true;

		$this->bump_generation();
	}

	/* ============================================================
	 *  Read / write
	 * ============================================================ */

	private static function redis_key( string $key ): string {
		if ( self::$ns === null ) {
			global $wpdb;

			// Site isolation: the namespace must differ for every install
			// that reaches the same Redis server and database. home_url()
			// alone is not enough — a staging clone keeps the production
			// URL — so the database name and the table prefix are part of
			// the hash as well. Cached search results differ per user
			// (drafts, private posts), so a shared namespace would serve
			// one site's rows to the other.
			$site = home_url( '/' )
				. '|' . ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' )
				. '|' . (string) $wpdb->prefix;

			self::$ns = substr( md5( $site ), 0, 12 );
		}
		return 'wss:' . self::$ns . ':' . $key;
	}

	/**
	 * Cached array or null on miss. Never throws: a cache failure must not
	 * break the search request.
	 */
	public function get( string $key ): ?array {
		if ( 'redis' !== self::backend() ) {
			return null;
		}

		$client = self::$redis;
		if ( ! $client instanceof Redis ) {
			return null;
		}

		try {
			$raw = $client->get( self::redis_key( $key ) );
			if ( ! is_string( $raw ) || $raw === '' ) {
				return null;
			}
			$data = unserialize( $raw, [ 'allowed_classes' => false ] );
			return is_array( $data ) ? $data : null;
		} catch ( Throwable $e ) {
			self::mark_down( $e->getMessage() );
			return null;
		}
	}

	/**
	 * Stores an array. Returns true when the value actually reached Redis;
	 * a false result is not an error — caching is optional.
	 */
	public function set( string $key, array $value, int $ttl ): bool {
		$ttl = max( 1, $ttl );

		if ( 'redis' !== self::backend() ) {
			return false;
		}

		$client = self::$redis;
		if ( ! $client instanceof Redis ) {
			return false;
		}

		try {
			return (bool) $client->setEx( self::redis_key( $key ), $ttl, serialize( $value ) );
		} catch ( Throwable $e ) {
			self::mark_down( $e->getMessage() );
			return false;
		}
	}

	/* ============================================================
	 *  Rate limiting
	 * ============================================================ */

	/**
	 * Increments a counter and returns [count, ttl].
	 *
	 * Uses Redis when it is available and transients otherwise — a limiter
	 * without storage would let requests through unchecked.
	 *
	 * @return array{count:int,ttl:int}
	 */
	public function rate_limit_incr( string $key, int $ttl ): array {
		$ttl = max( 1, $ttl );

		if ( 'redis' === self::backend() && self::$redis instanceof Redis ) {
			try {
				$redis_key = self::redis_key( $key );
				$count     = (int) self::$redis->incr( $redis_key );
				if ( $count === 1 ) {
					self::$redis->expire( $redis_key, $ttl );
				}
				$remaining = (int) self::$redis->ttl( $redis_key );
				return [
					'count' => $count,
					'ttl'   => $remaining > 0 ? $remaining : $ttl,
				];
			} catch ( Throwable $e ) {
				self::mark_down( $e->getMessage() );
			}
		}

		// Transients live in wp_options: keep the counter in the plugin
		// namespace, otherwise a generic name like "rl:5" collides with
		// another plugin's transient and uninstall.php (which sweeps
		// _transient_wss_*) would leave it behind.
		$transient = 'wss_' . $key;

		$count = (int) get_transient( $transient );
		$count++;
		set_transient( $transient, $count, $ttl );

		return [ 'count' => $count, 'ttl' => $ttl ];
	}
}

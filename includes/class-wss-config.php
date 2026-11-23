<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Config {

	public const NONCE_ACTION   = 'wss_search_action';
	public const NONCE_FIELD    = 'nonce';
	public const AJAX_ACTION    = 'wss_search';
	public const REST_NAMESPACE = 'wss/v1';
	public const PAGE_SLUG      = 'wss-smart-search';

	/** One step of a browser-driven links index rebuild. */
	public const AJAX_INDEX_STEP  = 'wss_index_step';
	public const NONCE_INDEX_STEP = 'wss_index_step';

	/** Releases the browser marker so WP-Cron may run again. */
	public const AJAX_INDEX_STOP  = 'wss_index_stop';
	public const NONCE_INDEX_STOP = 'wss_index_stop';

	public const ASSETS_DIR = 'assets';
	public const CSS_FILE   = 'admin.css';
	public const JS_FILE    = 'admin.js';

	public const CAPABILITY = 'publish_posts';

	public const MAX_WORDS          = 5;
	public const MIN_LEN            = 2;
	public const MAX_QUERY_LEN      = 200;
	public const POSTS_PER_PAGE     = 10;
	public const POSTS_PER_PAGE_MAX = 50;
	public const TAGS_SCAN          = 100;
	public const TAGS_OUT           = 30;

	/** Words shorter than this are not truncated to a stem — too broad otherwise. */
	public const STEM_MIN_LEN = 4;

	/** Max IDs in exclude/exclude_id — keeps the NOT IN (...) list bounded. */
	public const MAX_EXCLUDE = 200;

	/**
	 * Max matched posts of one query. A two-letter query matches almost
	 * every post, and the full list used to be built in memory; past this
	 * cap the list is cut (posts.truncated = true) and `total` is counted
	 * separately.
	 */
	public const MAX_MATCHED_POSTS = 10000;

	/** Rows read per query while the match list is built, one batch at a time. */
	public const MATCH_CHUNK_SIZE = 500;

	public const MATCH_MODES = [ 'auto', 'fuzzy', 'exact' ];

	/* ============================================================
	 *  Links: response fields and batch mode
	 * ============================================================ */

	public const LINK_FIELDS = [
		'post_id', 'title', 'permalink', 'status', 'post_type', 'url',
		'host', 'domain', 'anchor', 'anchor_kind', 'context', 'match_type',
	];

	/** Always returned, even when `fields` does not list them. */
	public const LINK_FIELDS_REQUIRED = [ 'post_id', 'match_type' ];

	/** Anchor matching modes of `anchor_match`. */
	public const ANCHOR_MATCH_MODES = [ 'words', 'exact', 'substring' ];

	/** Kinds of anchor text: phrase, the URL itself, empty, or not yet computed. */
	public const ANCHOR_KINDS = [ 'phrase', 'url', 'empty', 'unknown' ];

	/** Max post ids in the `post_id` link filter. */
	public const MAX_POST_ID_FILTER = 100;

	/** Max lookups per batch call. */
	public const BATCH_MAX = 50;

	public const TAGS_CACHE_TTL   = 86400;
	public const TAGS_CACHE_VER   = 'v3'; // v3: morphology (word stems)
	public const POSTS_CACHE_TTL  = 86400;
	public const POSTS_CACHE_VER  = 'v14'; // v14: truncated match list without post_content.
	public const CACHE_GEN_OPTION = 'wss_cache_gen';

	/* ---------- GET /wss/v1/tags (the pipeline's term check) ---------- */

	/** Slugs per batch: one SQL query, one response. */
	public const TAGS_MAX_SLUGS = 50;

	/** Shortest `search` substring (a shorter one is a 400). */
	public const TAGS_MIN_SEARCH = 3;

	/** Shortest `prefix` (a shorter one is a 400). */
	public const TAGS_MIN_PREFIX = 2;

	/** Terms per page for the endpoint, and its hard cap. */
	public const TAGS_PER_PAGE     = 20;
	public const TAGS_PER_PAGE_MAX = 100;

	/** Fields a client may ask for in `fields`. */
	public const TAGS_FIELDS = [ 'id', 'name', 'slug', 'count' ];

	/** Declared parameters of /wss/v1/tags; anything else is a 400. */
	public const TAGS_PARAMS = [
		'slug',
		'search',
		'prefix',
		'post_id',
		'taxonomy',
		'include_counts',
		'page',
		'per_page',
		'fields',
	];

	/**
	 * WordPress-internal parameters every REST route has to tolerate.
	 *
	 * _fields/_embed/_locale are applied by core to the response, _envelope
	 * wraps it, context and nonce are client conventions.
	 */
	public const TAGS_INTERNAL_PARAMS = [ '_fields', '_embed', '_locale', '_envelope', 'context', 'nonce' ];

	/** Taxonomies the endpoint exposes; anything else is a 400. */
	public const TAGS_TAXONOMIES = [ 'post_tag', 'category' ];

	/** Answers are cached for 60 seconds and dropped on every term change. */
	public const TAGS_ENDPOINT_CACHE_TTL = 60;
	public const TAGS_ENDPOINT_CACHE_VER = 'v1';

	/** Cache generation bumped by created_term / edited_term / delete_term. */
	public const TAGS_GEN_OPTION = 'wss_tags_gen';

	/** 60 requests per minute per user — a stricter bucket than /search. */
	/**
	 * 30 requests per minute per user: the plugin must answer 429 before an
	 * NGINX bucket in front of the site turns the same burst into an HTML 503.
	 * A site can lower it through the WSS_TAGS_RATE_LIMIT constant or the
	 * wss_tags_rate_limit filter; the effective value is reported by /me.
	 */
	public const TAGS_RATE_LIMIT = 30;
	public const TAGS_RATE_WIN   = 60;

	/**
	 * Effective limit of GET|POST /wss/v1/tags.
	 *
	 * Kept below the proxy limit on purpose: a 429 with Retry-After and JSON
	 * body is parseable, an HTML 503 from NGINX is not.
	 */
	public static function tags_rate_limit(): int {
		$default = self::TAGS_RATE_LIMIT;
		$limit   = defined( 'WSS_TAGS_RATE_LIMIT' ) ? (int) WSS_TAGS_RATE_LIMIT : $default;
		$limit   = (int) apply_filters( 'wss_tags_rate_limit', $limit );

		return max( 1, min( 1000, $limit ) );
	}

	/**
	 * Where the effective limit came from: filter, constant or the default.
	 */
	public static function tags_rate_limit_source(): string {
		$default = self::TAGS_RATE_LIMIT;
		$base    = defined( 'WSS_TAGS_RATE_LIMIT' ) ? (int) WSS_TAGS_RATE_LIMIT : $default;
		$limit   = (int) apply_filters( 'wss_tags_rate_limit', $base );

		if ( $limit !== $base ) {
			return 'filter';
		}

		return $base !== $default ? 'constant' : 'default';
	}

	public const RATE_LIMIT_MAX = 120;
	public const RATE_LIMIT_WIN = 60;

	/**
	 * Methods every REST route accepts.
	 *
	 * The site sits behind an NGINX cache that caches GET responses, so a
	 * client must not depend on one verb: a write that a proxy may repeat
	 * (GET) still has to be idempotent, and a read must work over POST too.
	 * Keeping the list here means a new route cannot be added single-method
	 * by accident.
	 */
	public const REST_METHODS = [ 'GET', 'POST' ];

	public const PURGE_STATUSES   = [ 'publish', 'future' ];
	public const ALLOWED_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	/**
	 * Statuses every user with the search capability may see, regardless of
	 * the author: duplicate checks run before publishing, so unpublished
	 * drafts and scheduled posts of colleagues must be searchable.
	 */
	public const SHARED_STATUSES = [ 'publish', 'future', 'draft', 'pending' ];

	/**
	 * Visibility model for posts of other authors.
	 *
	 * - shared (default): SHARED_STATUSES are visible to every user with
	 *   WSS_Config::CAPABILITY; private posts stay with their author and
	 *   with editors.
	 * - own: published posts plus the user's own posts only (the strict
	 *   model, useful when the search API is opened to a wider audience).
	 *
	 * Override with define( 'WSS_VISIBILITY_MODE', 'own' ) in wp-config.php
	 * or with the wss_visibility_mode filter.
	 */
	public static function visibility_mode(): string {
		$mode = defined( 'WSS_VISIBILITY_MODE' ) ? (string) WSS_VISIBILITY_MODE : 'shared';
		$mode = (string) apply_filters( 'wss_visibility_mode', $mode );

		return in_array( $mode, [ 'shared', 'own' ], true ) ? $mode : 'shared';
	}

	public const TOKEN_CHARS = '[\p{L}\p{N}._+\-@:\/?#=&%~\'!*]';

	public const TRACKING_PARAMS = [
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
		'fbclid', 'gclid', 'gclsrc', 'dclid', 'yclid', 'msclkid',
		'mc_cid', 'mc_eid', 'igshid', 'si', 'mkt_tok',
		'ref', 'ref_src', 'ref_url', 'source', 'src',
		'_ga', '_gl', '_hsenc', '_hsmi',
	];

	/* ============================================================
	 *  Links indexer
	 * ============================================================ */

	public const LINKS_TABLE            = 'wss_links';
	public const LINKS_TABLE_VER_OPTION = 'wss_links_table_ver';
	/** v2: added the anchor_kind column. */
	public const LINKS_TABLE_VER        = 'v2';

	/**
	 * Version of the row format produced by the indexer. A rebuild stamps it
	 * into the job state; an index built by an older format reports "stale".
	 */
	public const LINKS_INDEX_VERSION = 'v2';

	/** When the table existence was last verified (autoloaded option). */
	public const LINKS_TABLE_CHECK_OPTION = 'wss_links_table_check';

	/** How often the table existence is re-verified, seconds. */
	public const LINKS_TABLE_CHECK_TTL = 3600;

	public const LINK_CONTEXT_CHARS = 100;   // chars left/right of the tag
	public const LINK_ANCHOR_MAX    = 500;   // anchor truncation
	public const LINK_CONTEXT_MAX   = 400;   // context truncation

	/* ============================================================
	 *  Scope (search)
	 * ============================================================ */

	public const SCOPE_POSTS = 'posts';
	public const SCOPE_LINKS = 'links';
	public const SCOPE_ALL   = 'all';

	public const SCOPES = [ self::SCOPE_POSTS, self::SCOPE_LINKS, self::SCOPE_ALL ];

	/* ============================================================
	 *  GitHub updates
	 * ============================================================ */

	public const UPDATER_REPO      = 'Gnoztis/WP-Smart-Search';
	public const UPDATER_SLUG      = 'wp-smart-search';
	public const UPDATER_CACHE_TTL = 21600;  // 6 hours — successful check.
	public const UPDATER_ERROR_TTL = 900;    // 15 minutes — failed check.

	public static function user_can_search(): bool {
		return is_user_logged_in() && current_user_can( self::CAPABILITY );
	}

	/**
	 * Whether the current user may see posts of every author in every
	 * status, private included.
	 *
	 * edit_others_posts alone is not enough for private posts: core hands
	 * them over only to users with read_private_posts (the Editor and
	 * Administrator roles have both). A custom role may have only the
	 * first, and the search must not become a side channel around that.
	 */
	public static function can_search_all_statuses(): bool {
		return current_user_can( 'edit_others_posts' ) && current_user_can( 'read_private_posts' );
	}

	/** Default capability for a manual rebuild — see rebuild_capability(). */
	public const REBUILD_CAPABILITY = 'manage_options';

	/**
	 * Capability required to trigger a full links index rebuild by hand.
	 *
	 * A rebuild reads every post of the site and writes the whole index, so
	 * it is an administrator action, not something any author with the
	 * search capability may start. The filter hands it to a narrower or
	 * wider role without a code change:
	 *
	 *     add_filter( 'wss_rebuild_capability', fn() => 'edit_others_posts' );
	 *
	 * The automatic rebuild (activation, empty index detected) runs without
	 * a user and is not affected.
	 */
	public static function rebuild_capability(): string {
		$cap = (string) apply_filters( 'wss_rebuild_capability', self::REBUILD_CAPABILITY );

		return $cap !== '' ? $cap : self::REBUILD_CAPABILITY;
	}

	public static function user_can_rebuild(): bool {
		return is_user_logged_in() && current_user_can( self::rebuild_capability() );
	}

	/**
	 * SEC-001 A09: one line per security event, no secrets.
	 *
	 * Events: nonce_failed, cap_denied, object_denied, github_rate_limit,
	 * github_unauthorized, github_forbidden. No e-mail, IP or request content.
	 *
	 * @param string $event   Event name from the vocabulary above.
	 * @param string $context Short handler label, e.g. "ajax:index_step".
	 */
	public static function log_security_event( string $event, string $context = '' ): void {
		if ( ! function_exists( 'error_log' ) ) {
			return;
		}

		$line = '[wss] security: ' . $event;

		if ( $context !== '' ) {
			$line .= ' ' . str_replace( [ "\r", "\n" ], ' ', $context );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A09 security event, no secrets.
	}
}

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
		'post_id', 'title', 'permalink', 'status', 'url', 'host',
		'domain', 'anchor', 'context', 'match_type',
	];

	/** Always returned, even when `fields` does not list them. */
	public const LINK_FIELDS_REQUIRED = [ 'post_id', 'match_type' ];

	/** Max lookups per batch call. */
	public const BATCH_MAX = 50;

	public const TAGS_CACHE_TTL   = 86400;
	public const TAGS_CACHE_VER   = 'v3'; // v3: morphology (word stems)
	public const POSTS_CACHE_TTL  = 86400;
	public const POSTS_CACHE_VER  = 'v14'; // v14: truncated match list without post_content.
	public const CACHE_GEN_OPTION = 'wss_cache_gen';

	public const RATE_LIMIT_MAX = 120;
	public const RATE_LIMIT_WIN = 60;

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
	public const LINKS_TABLE_VER        = 'v1';

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
}

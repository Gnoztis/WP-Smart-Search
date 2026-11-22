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

	public const TAGS_CACHE_TTL   = 86400;
	public const TAGS_CACHE_VER   = 'v2';
	public const POSTS_CACHE_TTL  = 86400;
	public const POSTS_CACHE_VER  = 'v10'; // v10: post_type param, links scope
	public const CACHE_GEN_OPTION = 'wss_cache_gen';

	public const RATE_LIMIT_MAX = 120;
	public const RATE_LIMIT_WIN = 60;

	public const PURGE_STATUSES   = [ 'publish', 'future' ];
	public const ALLOWED_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

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

	public const LINK_CONTEXT_CHARS = 100;   // символов слева/справа от тега
	public const LINK_ANCHOR_MAX    = 500;   // обрезка anchor
	public const LINK_CONTEXT_MAX   = 400;   // обрезка context

	/* ============================================================
	 *  Scope (search)
	 * ============================================================ */

	public const SCOPE_POSTS = 'posts';
	public const SCOPE_LINKS = 'links';
	public const SCOPE_ALL   = 'all';

	public const SCOPES = [ self::SCOPE_POSTS, self::SCOPE_LINKS, self::SCOPE_ALL ];

	public static function user_can_search(): bool {
		return is_user_logged_in() && current_user_can( self::CAPABILITY );
	}
}
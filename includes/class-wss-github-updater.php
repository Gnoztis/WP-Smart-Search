<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updates through the native WordPress mechanism; source — GitHub
 * Releases and tags of WSS_Config::UPDATER_REPO.
 *
 * The source of truth is the highest version of the GitHub Release and the
 * newest vX.Y.Z tag: WordPress sees the new version, shows it under Plugins
 * and updates from the package of the winning source.
 * No third-party libraries and no write requests to the repository.
 */
final class WSS_GitHub_Updater {

	private const TRANSIENT       = 'wss_github_release';
	private const ERROR_TRANSIENT = 'wss_github_release_error';

	private static ?array $header = null;

	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'rename_source' ], 10, 4 );
		add_action( 'upgrader_process_complete',             [ $this, 'after_upgrade' ], 10, 2 );
		add_action( 'load-plugins.php',                      [ $this, 'maybe_force_check' ] );
		add_filter( 'plugin_action_links_' . self::basename(), [ $this, 'action_links' ] );
	}

	/**
	 * Basename of the installed main plugin file, e.g.
	 * "wp-smart-search/wp-smart-search.php".
	 *
	 * Computed at runtime, never taken from a constant: the folder is
	 * whatever the site has. A GitHub zipball unpacks into
	 * Gnoztis-WP-Smart-Search-<sha>, a manual upload can use any name.
	 * WordPress keys update_plugins->response by this basename and counts
	 * every entry for the admin badge, but lists only entries whose key
	 * matches a file from get_plugins() — so a hard-coded key shows an
	 * "update available" badge with an empty update list.
	 */
	public static function basename(): string {
		$basename = plugin_basename( WSS_PLUGIN_FILE );

		if ( $basename === '' ) {
			// plugin_basename() returns the path relative to the plugin
			// directory; an empty result means the main file was not inside
			// it, so fall back to the documented layout.
			$basename = WSS_Config::UPDATER_SLUG . '/' . wp_basename( WSS_PLUGIN_FILE );
		}

		return $basename;
	}

	/* ============================================================
	 *  Updates
	 * ============================================================ */

	/**
	 * Adds our entry to the update_plugins transient when GitHub has a
	 * release newer than the installed version.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		$basename = self::basename();

		// Runs before the early returns: a dangling entry is a leftover of
		// the previous key and must disappear even when GitHub is
		// unreachable right now.
		$this->purge_stale_entries( $transient, $basename );

		$release = $this->get_release();
		if ( $release === null ) {
			return $transient;
		}

		if ( version_compare( $release['version'], WSS_VERSION, '<=' ) ) {
			return $transient;
		}

		$transient->response[ $basename ] = (object) [
			'id'            => $basename,
			'slug'          => WSS_Config::UPDATER_SLUG,
			'plugin'        => $basename,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . WSS_Config::UPDATER_REPO,
			'package'       => $release['package'],
			'tested'        => $release['tested'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'icons'         => [],
			'banners'       => [],
			'compatibility' => new stdClass(),
		];

		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Drops update entries that point at our main file under a foreign key.
	 *
	 * Such entries are leftovers of the hard-coded basename this class used
	 * before, or of a folder that was renamed. They make the admin badge
	 * count an update that get_plugin_updates() cannot list, because the
	 * key does not match any installed plugin file.
	 *
	 * @param mixed  $transient
	 * @param string $basename Our real basename, kept as is.
	 */
	private function purge_stale_entries( $transient, string $basename ): void {
		$main = wp_basename( WSS_PLUGIN_FILE );

		foreach ( [ 'response', 'no_update' ] as $bucket ) {
			if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
				continue;
			}

			foreach ( $transient->$bucket as $key => $entry ) {
				if ( (string) $key === $basename || ! is_object( $entry ) ) {
					continue;
				}
				if ( empty( $entry->plugin ) || wp_basename( (string) $entry->plugin ) !== $main ) {
					continue;
				}

				unset( $transient->$bucket[ $key ] );
			}
		}
	}

	/**
	 * Data for the View plugin details modal.
	 *
	 * @param mixed $result
	 * @param string $action
	 * @param mixed $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		if ( WSS_Config::UPDATER_SLUG !== (string) $args->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( $release === null ) {
			return $result;
		}

		$header = $this->header_data();

		return (object) [
			'name'          => 'WP Smart Search',
			'slug'          => WSS_Config::UPDATER_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/Gnoztis">Gnoztis</a>',
			'homepage'      => 'https://github.com/' . WSS_Config::UPDATER_REPO,
			'requires'      => $header['requires'],
			'tested'        => $release['tested'],
			'requires_php'  => $header['requires_php'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'sections'      => [
				'description' => __( 'AJAX-поиск и REST API (Application Password) с индексацией ссылок, морфологией и batch-режимом.', 'wp-smart-search' ),
				'changelog'   => $release['body'] !== '' ? wpautop( $release['body'] ) : __( 'Описание изменений в релизе не заполнено.', 'wp-smart-search' ),
			],
		];
	}

	/**
	 * GitHub archives unpack into a directory like
	 * Gnoztis-WP-Smart-Search-1a2b3c4 — rename it to the directory the site
	 * actually uses (the folder of the installed main file), otherwise
	 * WordPress updates the wrong directory or adds a second copy.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return string
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) {
			return $source;
		}
		if ( self::basename() !== (string) $hook_extra['plugin'] ) {
			return $source;
		}
		if ( ! is_dir( $source ) ) {
			return $source;
		}
		if ( ! is_file( trailingslashit( $source ) . wp_basename( WSS_PLUGIN_FILE ) ) ) {
			return $source;
		}

		// Rename the unpacked directory to the folder the site actually
		// uses, not to the slug: the update has to replace the installed
		// directory, otherwise WordPress ends up with two copies of the
		// plugin (one of them inactive).
		$folder = dirname( self::basename() );
		if ( $folder === '.' || $folder === '' ) {
			// The main file sits directly in the plugins directory, so
			// there is no directory to rename.
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $folder;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Reset the update-check cache after an upgrade so we do not keep
	 * advertising an update for the version just installed.
	 */
	public function after_upgrade( $upgrader = null, $hook_extra = [] ): void {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			if ( ! in_array( self::basename(), $hook_extra['plugins'], true ) ) {
				return;
			}
		}
		$this->flush();
	}

	/**
	 * Update state for the /me diagnostics.
	 *
	 * Answers "why does the admin show an update badge with nothing to
	 * update?" in one request: the real basename, and the entry the
	 * update_plugins transient holds for our main file — including the key
	 * it sits under, which is where a mismatch shows up.
	 *
	 * @return array{plugin_basename:string,transient_entry:bool,transient_key:string,new_version:string,package:string}
	 */
	public static function diagnostic(): array {
		$basename = self::basename();

		$out = [
			'plugin_basename' => $basename,
			'transient_entry' => false,
			'transient_key'   => '',
			'new_version'     => '',
			'package'         => '',
		];

		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return $out;
		}

		$entry = null;
		$key   = '';

		if ( isset( $transient->response[ $basename ] ) && is_object( $transient->response[ $basename ] ) ) {
			$entry = $transient->response[ $basename ];
			$key   = $basename;
		} else {
			// No entry under our key: report a dangling one, if any, so the
			// badge/list mismatch is visible directly.
			$main = wp_basename( WSS_PLUGIN_FILE );
			foreach ( $transient->response as $candidate_key => $candidate ) {
				if ( ! is_object( $candidate ) || empty( $candidate->plugin ) ) {
					continue;
				}
				if ( wp_basename( (string) $candidate->plugin ) !== $main ) {
					continue;
				}
				$entry = $candidate;
				$key   = (string) $candidate_key;
				break;
			}
		}

		if ( ! is_object( $entry ) ) {
			return $out;
		}

		$out['transient_entry'] = true;
		$out['transient_key']   = $key;
		$out['new_version']     = isset( $entry->new_version ) ? (string) $entry->new_version : '';
		$out['package']         = isset( $entry->package ) ? (string) $entry->package : '';

		return $out;
	}

	/**
	 * Check for updates link in the plugin row.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return (array) $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'wss-force-check', '1', admin_url( 'plugins.php' ) ),
			'wss_force_check'
		);

		$links = (array) $links;
		$links['wss_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'wp-smart-search' ) . '</a>';

		return $links;
	}

	/**
	 * Handles the Check for updates click on plugins.php.
	 */
	public function maybe_force_check(): void {
		if ( ! isset( $_GET['wss-force-check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'wss_force_check' );

		$this->flush();

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	private function flush(): void {
		delete_transient( self::TRANSIENT );
		delete_transient( self::ERROR_TRANSIENT );
		delete_site_transient( 'update_plugins' );
	}

	/* ============================================================
	 *  GitHub API
	 * ============================================================ */

	/**
	 * Latest version data (6 hour cache, 15 minute error cache).
	 */
	private function get_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return $cached;
		}

		if ( get_transient( self::ERROR_TRANSIENT ) ) {
			return null;
		}

		$release = $this->fetch_release();

		if ( $release === null ) {
			set_transient( self::ERROR_TRANSIENT, 1, WSS_Config::UPDATER_ERROR_TTL );
			return null;
		}

		set_transient( self::TRANSIENT, $release, WSS_Config::UPDATER_CACHE_TTL );

		return $release;
	}

	/**
	 * Latest version data: the highest version of both sources.
	 *
	 * Both sources are always queried (the winner is cached as a whole).
	 * Taking the release and using the tag only when there is no release
	 * hides a fresh tag behind an older GitHub Release: the repository
	 * keeps release 1.6.3, the owner pushes tag 1.7.0, and the update is
	 * never offered until a release is created by hand.
	 *
	 * Equal versions: the release wins, it carries the changelog.
	 */
	private function fetch_release(): ?array {
		$release = $this->fetch_latest_release();
		$tag     = $this->fetch_latest_tag();

		if ( $release === null && $tag === null ) {
			return null;
		}
		if ( $release === null ) {
			return $tag;
		}
		if ( $tag === null ) {
			return $release;
		}

		return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
	}

	private function fetch_latest_release(): ?array {
		$body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', WSS_Config::UPDATER_REPO ) );
		if ( $body === null ) {
			return null;
		}

		$version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
		if ( $version === '' ) {
			return null;
		}

		$package = $this->find_package( $body );
		if ( $package === '' ) {
			return null;
		}

		return $this->build_release(
			$version,
			$package,
			isset( $body['body'] ) ? (string) $body['body'] : '',
			isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
		);
	}

	/**
	 * Highest semver tag of the repository. Competes with the release in
	 * fetch_release(); a tag without a GitHub Release is a normal case.
	 */
	private function fetch_latest_tag(): ?array {
		$tags = $this->api_get(
			sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', WSS_Config::UPDATER_REPO )
		);

		if ( ! is_array( $tags ) ) {
			return null;
		}

		$best_version = '';
		$best_tag     = '';

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
				continue;
			}
			$version = $this->normalize_version( (string) $tag['name'] );
			if ( $version === '' ) {
				continue;
			}
			if ( $best_version === '' || version_compare( $version, $best_version, '>' ) ) {
				$best_version = $version;
				$best_tag     = (string) $tag['name'];
			}
		}

		if ( $best_version === '' || $best_tag === '' ) {
			return null;
		}

		$package = $this->safe_package_url(
			sprintf(
				'https://api.github.com/repos/%s/zipball/%s',
				WSS_Config::UPDATER_REPO,
				rawurlencode( $best_tag )
			)
		);

		if ( $package === '' ) {
			return null;
		}

		return $this->build_release( $best_version, $package, '', '' );
	}

	/**
	 * @param string $version
	 * @param string $package
	 * @param string $body
	 * @param string $published_at
	 */
	private function build_release( string $version, string $package, string $body, string $published_at ): array {
		$header = $this->header_data();

		return [
			'version'      => $version,
			'package'      => $package,
			'body'         => $body,
			'published_at' => $published_at,
			'tested'       => $header['requires'],
			'requires'     => $header['requires'],
			'requires_php' => $header['requires_php'],
		];
	}

	/**
	 * GET to the GitHub API with our User-Agent and an optional token.
	 *
	 * @return array<string,mixed>|null
	 */
	private function api_get( string $url ): ?array {
		$args = [
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => [
				'Accept'       => 'application/vnd.github+json',
				'User-Agent'   => 'WP-Smart-Search/' . WSS_VERSION,
				'Content-Type' => 'application/json',
			],
		];

		$token = $this->token();
		if ( $token !== '' ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// The URL is built from the repository constant, not from user
		// input; scheme and host of the answer are validated separately.
		$response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from the repository constant; scheme and host are validated below.
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : null;
	}

	/**
	 * Archive URL: the release zip asset first, then the GitHub zipball.
	 *
	 * @param array $body
	 */
	private function find_package( array $body ): string {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( ! preg_match( '/\.zip$/i', $name ) ) {
					continue;
				}
				$url = $this->safe_package_url( (string) $asset['browser_download_url'] );
				if ( $url !== '' ) {
					return $url;
				}
			}
		}

		if ( ! empty( $body['zipball_url'] ) ) {
			return $this->safe_package_url( (string) $body['zipball_url'] );
		}

		return '';
	}

	/**
	 * HTTPS only and GitHub hosts only — the package must not come
	 * from a field a third-party service could tamper with.
	 */
	private function safe_package_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return '';
		}

		$allowed = [ 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' ];
		if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
			return '';
		}

		return $url;
	}

	private function normalize_version( string $tag ): string {
		$tag = trim( $tag );
		if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
			return '';
		}
		return $m[1];
	}

	private function token(): string {
		$token = defined( 'WSS_GITHUB_TOKEN' ) ? (string) WSS_GITHUB_TOKEN : '';
		$token = (string) apply_filters( 'wss_github_token', $token );
		return trim( $token );
	}

	/**
	 * Requires / Requires PHP from the plugin header.
	 *
	 * @return array{requires:string,requires_php:string}
	 */
	private function header_data(): array {
		if ( self::$header === null ) {
			$data = function_exists( 'get_file_data' )
				? get_file_data(
					WSS_PLUGIN_FILE,
					[
						'Requires at least' => 'Requires at least',
						'Requires PHP'      => 'Requires PHP',
					],
					'plugin'
				)
				: [];

			self::$header = [
				'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
				'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
			];
		}

		return self::$header;
	}
}

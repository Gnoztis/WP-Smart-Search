<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Admin_Page {

	public function register_hooks(): void {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'admin_body_class',      [ $this, 'add_body_class' ] );
		add_action( 'admin_post_wss_index_rebuild', [ $this, 'handle_index_rebuild' ] );
	}

	/**
	 * Queues a links index rebuild from the admin screen.
	 *
	 * A manual rebuild is a site-wide action: the search page stays open to
	 * authors, but starting the job requires the rebuild capability
	 * (manage_options by default, filterable via wss_rebuild_capability).
	 */
	public function handle_index_rebuild(): void {
		if ( ! WSS_Config::user_can_search() ) {
			wp_die( esc_html__( 'У вас нет доступа к этой странице.', 'wp-smart-search' ), 403 );
		}

		if ( ! WSS_Config::user_can_rebuild() ) {
			wp_die( esc_html__( 'Переиндексация доступна администратору сайта.', 'wp-smart-search' ), 403 );
		}

		check_admin_referer( 'wss_index_rebuild' );

		WSS_Index_Backfill::start();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'        => WSS_Config::PAGE_SLUG,
					'wss_reindex' => '1',
				],
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php',
			__( 'Умный поиск', 'wp-smart-search' ),
			__( 'Умный поиск', 'wp-smart-search' ),
			WSS_Config::CAPABILITY,
			WSS_Config::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function add_body_class( string $classes ): string {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $classes;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return $classes;
		}
		if ( strpos( (string) $screen->id, WSS_Config::PAGE_SLUG ) === false ) {
			return $classes;
		}
		return $classes . ' wss-dark-page';
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( $hook !== 'posts_page_' . WSS_Config::PAGE_SLUG ) {
			return;
		}
		if ( ! WSS_Config::user_can_search() ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		$css = $this->read_asset( WSS_Config::CSS_FILE );
		if ( $css !== null ) {
			wp_register_style( 'wss-admin', false, [], WSS_VERSION );
			wp_enqueue_style( 'wss-admin' );
			wp_add_inline_style( 'wss-admin', $css );
		}

		$js = $this->read_asset( WSS_Config::JS_FILE );
		if ( $js !== null ) {
			wp_register_script( 'wss-admin', false, [ 'jquery' ], WSS_VERSION, true );
			wp_enqueue_script( 'wss-admin' );

			$data = [
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( WSS_Config::NONCE_ACTION ),
				'action'          => WSS_Config::AJAX_ACTION,
				'indexStepAction' => WSS_Config::AJAX_INDEX_STEP,
				'indexStepNonce'  => wp_create_nonce( WSS_Config::NONCE_INDEX_STEP ),
				'minLen'          => WSS_Config::MIN_LEN,
				'initialQuery'    => $this->initial_query(),
				'pageUrl'         => admin_url( 'edit.php?page=' . WSS_Config::PAGE_SLUG ),
				'apiUrl'          => rest_url( WSS_Config::REST_NAMESPACE . '/search' ),
				'apiMeUrl'        => rest_url( WSS_Config::REST_NAMESPACE . '/me' ),
				'i18n'            => [
					'searching'     => __( 'Поиск…', 'wp-smart-search' ),
					'noResults'     => __( 'Ничего не найдено', 'wp-smart-search' ),
					'noTags'        => __( 'Теги не найдены', 'wp-smart-search' ),
					'noPosts'       => __( 'Записи не найдены', 'wp-smart-search' ),
					'error'         => __( 'Ошибка поиска', 'wp-smart-search' ),
					'tags'          => __( 'Теги', 'wp-smart-search' ),
					'posts'         => __( 'Записи', 'wp-smart-search' ),
					'catsLabel'     => __( 'Категории', 'wp-smart-search' ),
					'tagsLabel'     => __( 'Метки', 'wp-smart-search' ),
					'authorLabel'   => __( 'Автор', 'wp-smart-search' ),
					'minLenTpl'     => __( 'Введите не менее %d символов', 'wp-smart-search' ),
					'showAllTags'   => __( 'Показать все', 'wp-smart-search' ),
					'prevPage'      => __( 'Предыдущая страница', 'wp-smart-search' ),
					'nextPage'      => __( 'Следующая страница', 'wp-smart-search' ),
					'pageLabel'     => __( 'Страница %d', 'wp-smart-search' ),
					'emptyState'    => __( 'Введите запрос для поиска по записям и тегам', 'wp-smart-search' ),
					'indexRunning'  => __( 'Переиндексация…', 'wp-smart-search' ),
					'indexProgress' => __( 'Обработано %1$d из %2$d (%3$d%)', 'wp-smart-search' ),
					'indexDone'     => __( 'Готово.', 'wp-smart-search' ),
					'indexStopped'  => __( 'Остановлено. Прогресс сохранён — можно продолжить.', 'wp-smart-search' ),
					'indexBusy'     => __( 'Фоновая пересборка уже выполняется. Попробуйте позже.', 'wp-smart-search' ),
					'indexError'    => __( 'Ошибка запроса. Прогресс сохранён — можно продолжить.', 'wp-smart-search' ),
					'indexCounts'   => __( 'Ссылок в индексе: %1$d, записей: %2$d', 'wp-smart-search' ),
				],
			];

			wp_add_inline_script(
				'wss-admin',
				'window.WSS = ' . wp_json_encode( $data ) . ';',
				'before'
			);
			wp_add_inline_script( 'wss-admin', $js, 'after' );
		}
	}

	private function read_asset( string $filename ): ?string {
		$dir  = WSS_PLUGIN_DIR . WSS_Config::ASSETS_DIR . DIRECTORY_SEPARATOR . $filename;
		$real = realpath( $dir );
		$base = realpath( WSS_PLUGIN_DIR );

		if ( $real === false || $base === false ) {
			return null;
		}
		if ( strpos( $real, $base . DIRECTORY_SEPARATOR ) !== 0 ) {
			return null;
		}
		if ( ! is_file( $real ) || ! is_readable( $real ) || filesize( $real ) <= 0 ) {
			return null;
		}
		$content = file_get_contents( $real ); // phpcs:ignore -- realpath() and the plugin dir prefix were checked above.
		return ( is_string( $content ) && $content !== '' ) ? $content : null;
	}

	private function initial_query(): string {
		if ( ! isset( $_GET['q'] ) ) {
			return '';
		}
		$q = sanitize_text_field( wp_unslash( $_GET['q'] ) );
		if ( mb_strlen( $q ) > WSS_Config::MAX_QUERY_LEN ) {
			$q = mb_substr( $q, 0, WSS_Config::MAX_QUERY_LEN );
		}
		return $q;
	}

	private function initial_refang(): bool {
		if ( ! isset( $_GET['refang'] ) ) {
			return true;
		}
		$v = wp_unslash( $_GET['refang'] );
		if ( ! is_string( $v ) ) {
			return true;
		}
		$v = strtolower( trim( $v ) );
		return ! in_array( $v, [ '', '0', 'false', 'no', 'off' ], true );
	}

	/**
	 * Tab definitions: slug => label and panel id.
	 *
	 * The slug is the value of ?tab=; "search" is the default and keeps the
	 * plain page URL.
	 *
	 * @return array<string,array{label:string,panel:string}>
	 */
	private function tabs(): array {
		return [
			'search' => [
				'label' => __( 'Поиск', 'wp-smart-search' ),
				'panel' => 'wss-panel-search',
			],
			'api'    => [
				'label' => __( 'API', 'wp-smart-search' ),
				'panel' => 'wss-panel-api',
			],
			'index'  => [
				'label' => __( 'Индексирование', 'wp-smart-search' ),
				'panel' => 'wss-panel-index',
			],
		];
	}

	/**
	 * URL of a tab: the plain page URL for the default tab, ?tab= for
	 * the others, so the state survives a reload and works without JS.
	 */
	private function tab_url( string $slug, string $base_url ): string {
		return 'search' === $slug ? $base_url : add_query_arg( 'tab', $slug, $base_url );
	}

	/**
	 * Active tab from the URL.
	 *
	 * ?wss_reindex=1 (the redirect after starting a rebuild) opens the
	 * indexing tab, where the job status is shown.
	 */
	private function active_tab(): string {
		$raw = isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : '';
		$tab = is_string( $raw ) ? sanitize_key( $raw ) : '';

		if ( array_key_exists( $tab, $this->tabs() ) ) {
			return $tab;
		}

		if ( isset( $_GET['wss_reindex'] ) ) {
			return 'index';
		}

		return 'search';
	}

	public function render(): void {
		if ( ! WSS_Config::user_can_search() ) {
			wp_die( esc_html__( 'У вас нет доступа к этой странице.', 'wp-smart-search' ), 403 );
		}

		$initial_query  = $this->initial_query();
		$initial_refang = $this->initial_refang();

		$status_options = [
			'publish' => __( 'Опубликовано', 'wp-smart-search' ),
			'draft'   => __( 'Черновик', 'wp-smart-search' ),
			'pending' => __( 'На утверждении', 'wp-smart-search' ),
			'private' => __( 'Приватно', 'wp-smart-search' ),
			'future'  => __( 'Запланировано', 'wp-smart-search' ),
		];

		$base_url = admin_url( 'edit.php?page=' . WSS_Config::PAGE_SLUG );
		$tabs     = $this->tabs();
		$active   = $this->active_tab();

		$endpoint_search = rest_url( WSS_Config::REST_NAMESPACE . '/search' );
		$endpoint_batch  = rest_url( WSS_Config::REST_NAMESPACE . '/links/batch' );
		$endpoint_me     = rest_url( WSS_Config::REST_NAMESPACE . '/me' );

		// Rendered with implode() so the block starts exactly at the first
		// command: any indentation in the template would become part of the
		// text under white-space: pre.
		$cli_commands = [
			'wp wss index status',
			'wp wss index rebuild --batch-size=200',
			'wp wss index clear',
		];
		?>
		<div class="wrap wss-wrap">
			<div class="wss-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Разделы страницы', 'wp-smart-search' ); ?>">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<?php $tab_class = 'wss-tab' . ( $slug === $active ? ' is-active' : '' ); ?>
					<a class="<?php echo esc_attr( $tab_class ); ?>"
						id="wss-tab-<?php echo esc_attr( $slug ); ?>"
						href="<?php echo esc_url( $this->tab_url( $slug, $base_url ) ); ?>"
						role="tab"
						data-tab="<?php echo esc_attr( $slug ); ?>"
						aria-selected="<?php echo esc_attr( $slug === $active ? 'true' : 'false' ); ?>"
						aria-controls="<?php echo esc_attr( $tab['panel'] ); ?>"
						tabindex="<?php echo esc_attr( $slug === $active ? '0' : '-1' ); ?>"><?php echo esc_html( $tab['label'] ); ?></a>
				<?php endforeach; ?>
			</div>

			<section class="wss-panel" id="wss-panel-search" role="tabpanel" aria-labelledby="wss-tab-search" <?php echo esc_attr( 'search' === $active ? '' : 'hidden' ); ?>>
				<div id="wss-progress" class="wss-progress" aria-hidden="true">
					<div class="wss-progress-bar"></div>
				</div>

				<div class="wss-layout">
					<div class="wss-main">
						<div class="wss-search">
							<div class="wss-search-field">
								<input type="text" id="wss-input" class="wss-input" autocomplete="off"
									value="<?php echo esc_attr( $initial_query ); ?>"
									placeholder="<?php esc_attr_e( 'Поиск по записям и тегам…', 'wp-smart-search' ); ?>">
								<button type="button" id="wss-clear" class="wss-clear"
									aria-label="<?php esc_attr_e( 'Очистить', 'wp-smart-search' ); ?>">&times;</button>
							</div>
							<button type="button" id="wss-submit" class="wss-submit"
								aria-label="<?php esc_attr_e( 'Найти', 'wp-smart-search' ); ?>">
								<span class="dashicons dashicons-search" aria-hidden="true"></span>
							</button>
						</div>

						<div id="wss-status" class="wss-status" role="status" aria-live="polite"></div>

						<div id="wss-results" class="wss-results">
							<div class="wss-empty-state">
								<span class="dashicons dashicons-search wss-empty-state-icon" aria-hidden="true"></span>
								<p><?php esc_html_e( 'Введите запрос для поиска по записям и тегам', 'wp-smart-search' ); ?></p>
							</div>
						</div>
					</div>

					<aside class="wss-sidebar">
						<div class="wss-filter-card">
							<h3 class="wss-filter-title"><?php esc_html_e( 'Фильтры', 'wp-smart-search' ); ?></h3>

							<div class="wss-filter-section">
								<h4><?php esc_html_e( 'Опции поиска', 'wp-smart-search' ); ?></h4>
								<label class="wss-filter-checkbox">
									<input type="checkbox" id="wss-refang-cb" <?php checked( $initial_refang ); ?>>
									<span><?php esc_html_e( 'Авто-замена символов', 'wp-smart-search' ); ?></span>
								</label>
							</div>

							<div class="wss-filter-section">
								<h4><?php esc_html_e( 'Статус записи', 'wp-smart-search' ); ?></h4>
								<?php foreach ( $status_options as $value => $label ) : ?>
									<label class="wss-filter-checkbox">
										<input type="checkbox" class="wss-filter-status" value="<?php echo esc_attr( $value ); ?>" checked>
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>

							<div class="wss-filter-section">
								<h4><?php esc_html_e( 'Дата', 'wp-smart-search' ); ?></h4>
								<select id="wss-filter-date" class="wss-filter-select">
									<option value="any"><?php esc_html_e( 'Любая', 'wp-smart-search' ); ?></option>
									<option value="today"><?php esc_html_e( 'Сегодня', 'wp-smart-search' ); ?></option>
									<option value="week"><?php esc_html_e( 'За неделю', 'wp-smart-search' ); ?></option>
									<option value="month"><?php esc_html_e( 'За месяц', 'wp-smart-search' ); ?></option>
									<option value="year"><?php esc_html_e( 'За год', 'wp-smart-search' ); ?></option>
								</select>
							</div>

							<?php if ( current_user_can( 'edit_others_posts' ) ) : ?>
								<?php $authors = $this->authors_list(); ?>
								<?php if ( ! empty( $authors ) ) : ?>
									<div class="wss-filter-section">
										<h4><?php esc_html_e( 'Автор', 'wp-smart-search' ); ?></h4>
										<select id="wss-filter-author" class="wss-filter-select">
											<option value="0"><?php esc_html_e( 'Все авторы', 'wp-smart-search' ); ?></option>
											<?php foreach ( $authors as $author ) : ?>
												<option value="<?php echo esc_attr( $author['id'] ); ?>"><?php echo esc_html( $author['name'] ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								<?php endif; ?>
							<?php endif; ?>

							<div class="wss-filter-section">
								<h4><?php esc_html_e( 'Сортировка', 'wp-smart-search' ); ?></h4>
								<select id="wss-filter-orderby" class="wss-filter-select">
									<option value="date_desc"><?php esc_html_e( 'Сначала новые', 'wp-smart-search' ); ?></option>
									<option value="date_asc"><?php esc_html_e( 'Сначала старые', 'wp-smart-search' ); ?></option>
								</select>
							</div>

							<button type="button" id="wss-reset-filters" class="wss-reset-btn"><?php esc_html_e( 'Сбросить фильтры', 'wp-smart-search' ); ?></button>
						</div>
					</aside>
				</div>
			</section>

			<section class="wss-panel" id="wss-panel-api" role="tabpanel" aria-labelledby="wss-tab-api" <?php echo esc_attr( 'api' === $active ? '' : 'hidden' ); ?>>
				<div class="wss-panel-card">
					<h2 class="wss-panel-title"><?php esc_html_e( 'REST API', 'wp-smart-search' ); ?></h2>

					<p class="wss-panel-text">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: REST namespace, e.g. wss/v1 */
								__( 'Документация по методам. Базовый путь — %s; полные адреса ниже собраны из rest_url(), лимиты и пределы — из констант плагина.', 'wp-smart-search' ),
								WSS_Config::REST_NAMESPACE
							)
						);
						?>
					</p>

					<?php
					$this->render_api_panel(
						[
							'search' => $endpoint_search,
							'batch'  => $endpoint_batch,
							'me'     => $endpoint_me,
						]
					);
					?>
				</div>
			</section>

			<section class="wss-panel" id="wss-panel-index" role="tabpanel" aria-labelledby="wss-tab-index" <?php echo esc_attr( 'index' === $active ? '' : 'hidden' ); ?>>
				<?php $index_status = WSS_Index_Backfill::status(); ?>
				<div class="wss-panel-card">
					<h2 class="wss-panel-title"><?php esc_html_e( 'Индекс ссылок', 'wp-smart-search' ); ?></h2>

					<?php if ( isset( $_GET['wss_reindex'] ) ) : ?>
						<p class="wss-index-note"><?php esc_html_e( 'Переиндексация запущена в фоне.', 'wp-smart-search' ); ?></p>
					<?php endif; ?>

					<?php if ( isset( $index_status['links'] ) ) : ?>
						<p class="wss-index-line" id="wss-index-counts">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of indexed links, 2: number of posts */
									__( 'Ссылок в индексе: %1$d, записей: %2$d', 'wp-smart-search' ),
									(int) $index_status['links'],
									(int) $index_status['posts']
								)
							);
							?>
						</p>
					<?php endif; ?>

					<?php if ( ! $index_status['table'] ) : ?>
						<p class="wss-index-note"><?php esc_html_e( 'Таблица индекса отсутствует — она будет создана автоматически.', 'wp-smart-search' ); ?></p>
					<?php endif; ?>

					<?php if ( ! $index_status['done'] ) : ?>
						<p class="wss-index-line">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of processed posts */
									__( 'Переиндексация выполняется: обработано %d записей', 'wp-smart-search' ),
									(int) $index_status['processed']
								)
							);
							?>
						</p>
					<?php endif; ?>

					<?php if ( WSS_Config::user_can_rebuild() ) : ?>
						<div class="wss-index-actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'wss_index_rebuild' ); ?>
								<input type="hidden" name="action" value="wss_index_rebuild">
								<button type="submit" class="wss-reset-btn"><?php esc_html_e( 'Переиндексировать ссылки', 'wp-smart-search' ); ?></button>
							</form>

							<div class="wss-index-runner" id="wss-index-runner" hidden>
								<button type="button" id="wss-index-start" class="wss-reset-btn"><?php esc_html_e( 'Доиндексировать из браузера', 'wp-smart-search' ); ?></button>
								<button type="button" id="wss-index-stop" class="wss-reset-btn" hidden><?php esc_html_e( 'Остановить', 'wp-smart-search' ); ?></button>

								<div class="wss-index-track">
									<div id="wss-index-bar" class="wss-index-bar" role="progressbar"
										aria-label="<?php esc_attr_e( 'Прогресс переиндексации', 'wp-smart-search' ); ?>"
										aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
								</div>

								<p id="wss-index-progress-text" class="wss-index-line" role="status" aria-live="polite"></p>
							</div>
						</div>
					<?php endif; ?>

					<p class="wss-panel-subtitle"><?php esc_html_e( 'Из консоли', 'wp-smart-search' ); ?></p>
					<pre class="wss-api-code"><code><?php echo esc_html( implode( "\n", $cli_commands ) ); ?></code></pre>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * API tab: one source of data for every method, rendered here.
	 *
	 * @param array{search:string,batch:string,me:string} $endpoints Absolute URLs from rest_url().
	 */
	private function render_api_panel( array $endpoints ): void {
		$json_flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

		$search_curl = 'curl -s -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \\' . "\n"
			. '  "' . $endpoints['search'] . '?q=wordpress&per_page=10&scope=posts"';

		$search_json = (string) wp_json_encode(
			[
				'query' => 'wordpress',
				'scope' => 'posts',
				'meta'  => [
					'terms'          => [ 'wordpress' ],
					'tokens'         => 1,
					'dropped_tokens' => 0,
				],
				'posts' => [
					'items'       => [
						[
							'id'         => 42,
							'title'      => 'WordPress 7.1: обзор',
							'status'     => 'publish',
							'match_type' => 'title',
							'score'      => 90,
							'permalink'  => '…',
							'excerpt'    => '…',
						],
					],
					'total'       => 1,
					'truncated'   => false,
					'page'        => 1,
					'per_page'    => 10,
					'total_pages' => 1,
				],
			],
			$json_flags
		);

		$batch_body = (string) wp_json_encode(
			[
				'domains'  => [ 'example.com', 'example.org' ],
				'hosts'    => [],
				'urls'     => [],
				'anchors'  => [],
				'per_page' => 5,
				'statuses' => [ 'publish' ],
				'fields'   => 'post_id,url,anchor',
			],
			$json_flags
		);

		$batch_json = (string) wp_json_encode(
			[
				'results' => [
					[
						'lookup'   => [ 'domain' => 'example.com' ],
						'items'    => [
							[
								'post_id'    => 42,
								'url'        => 'https://example.com/report',
								'anchor'     => 'отчёт',
								'match_type' => 'domain',
							],
						],
						'has_more' => false,
					],
				],
				'meta'    => [
					'count'       => 2,
					'per_page'    => 5,
					'total_exact' => false,
					'truncated'   => false,
					'requested'   => 2,
				],
			],
			$json_flags
		);

		$me_json = (string) wp_json_encode(
			[
				'id'                   => 1,
				'can_search'           => true,
				'can_filter_by_author' => true,
				'visibility_mode'      => WSS_Config::visibility_mode(),
				'private_scope'        => 'all',
				'max_per_page'         => WSS_Config::POSTS_PER_PAGE_MAX,
				'max_matched_posts'    => WSS_Config::MAX_MATCHED_POSTS,
				'batch_limit'          => WSS_Config::BATCH_MAX,
				'plugin_version'       => WSS_VERSION,
				'cache_backend'        => 'redis',
				'index'                => [
					'table'     => true,
					'done'      => true,
					'cursor'    => 0,
					'processed' => 0,
				],
			],
			$json_flags
		);
		?>
		<details class="wss-doc" open>
			<summary><?php esc_html_e( 'GET|POST /search — поиск по записям и ссылкам', 'wp-smart-search' ); ?></summary>

			<p class="wss-api-line"><code>GET|POST <?php echo esc_html( $endpoints['search'] ); ?></code></p>

			<p class="wss-panel-text">
				<?php esc_html_e( 'Слова запроса ищутся по основам и объединяются по «И»: «обход песочницы» находит «обходу песочницу». Если совпадений больше предела, список обрезается и posts.truncated = true, а total остаётся точным.', 'wp-smart-search' ); ?>
			</p>

			<?php
			$this->render_doc_table(
				[
					__( 'Параметр', 'wp-smart-search' ),
					__( 'Тип', 'wp-smart-search' ),
					__( 'Значения / по умолчанию', 'wp-smart-search' ),
					__( 'Смысл', 'wp-smart-search' ),
				],
				$this->search_param_docs()
			);
			?>

			<p class="wss-panel-subtitle"><?php esc_html_e( 'Пример запроса', 'wp-smart-search' ); ?></p>
			<pre class="wss-api-code"><code><?php echo esc_html( $search_curl ); ?></code></pre>

			<p class="wss-panel-subtitle"><?php esc_html_e( 'Пример ответа (сокращённо)', 'wp-smart-search' ); ?></p>
			<pre class="wss-api-code"><code><?php echo esc_html( $search_json ); ?></code></pre>
		</details>

		<details class="wss-doc">
			<summary><?php esc_html_e( 'POST /links/batch — пакетный поиск по ссылкам', 'wp-smart-search' ); ?></summary>

			<p class="wss-api-line"><code>POST <?php echo esc_html( $endpoints['batch'] ); ?></code></p>

			<p class="wss-panel-text">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: maximum number of lookups per call */
						__( 'До %d запросов за один вызов: домены, хосты, точные URL или подстроки анкора. В batch-режиме total не считается.', 'wp-smart-search' ),
						(int) WSS_Config::BATCH_MAX
					)
				);
				?>
			</p>

			<?php
			$this->render_doc_table(
				[
					__( 'Поле', 'wp-smart-search' ),
					__( 'Тип', 'wp-smart-search' ),
					__( 'Значения / по умолчанию', 'wp-smart-search' ),
					__( 'Смысл', 'wp-smart-search' ),
				],
				$this->batch_body_docs()
			);
			?>

			<p class="wss-panel-subtitle"><?php esc_html_e( 'Тело запроса', 'wp-smart-search' ); ?></p>
			<pre class="wss-api-code"><code><?php echo esc_html( $batch_body ); ?></code></pre>

			<p class="wss-panel-subtitle"><?php esc_html_e( 'Ответ', 'wp-smart-search' ); ?></p>
			<pre class="wss-api-code"><code><?php echo esc_html( $batch_json ); ?></code></pre>
		</details>

		<details class="wss-doc">
			<summary><?php esc_html_e( 'GET|POST /me — права, лимиты и состояние индекса', 'wp-smart-search' ); ?></summary>

			<p class="wss-api-line"><code>GET|POST <?php echo esc_html( $endpoints['me'] ); ?></code></p>

			<p class="wss-panel-text">
				<?php esc_html_e( 'Проверка доступа и пределы API для текущего пользователя. visibility_mode = shared означает, что пользователь с правом публикации видит черновики всех авторов (нужно для проверки дубликатов); own — только опубликованное и своё. private_scope = own ограничивает приватные записи автором.', 'wp-smart-search' ); ?>
			</p>

			<?php
			$this->render_doc_table(
				[
					__( 'Поле', 'wp-smart-search' ),
					__( 'Тип', 'wp-smart-search' ),
					__( 'Что означает', 'wp-smart-search' ),
				],
				$this->me_field_docs()
			);
			?>

			<p class="wss-panel-subtitle"><?php esc_html_e( 'Пример ответа (сокращённо)', 'wp-smart-search' ); ?></p>
			<pre class="wss-api-code"><code><?php echo esc_html( $me_json ); ?></code></pre>
		</details>

		<details class="wss-doc">
			<summary><?php esc_html_e( 'Аутентификация и лимиты', 'wp-smart-search' ); ?></summary>

			<p class="wss-panel-text">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: capability name */
						__( 'Только заголовок Authorization: Basic с Application Password из профиля пользователя. Cookie-аутентификация на этих маршрутах не поддерживается; минимальное право — %s.', 'wp-smart-search' ),
						WSS_Config::CAPABILITY
					)
				);
				?>
			</p>

			<p class="wss-panel-text">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: requests in the window, 2: window length in seconds */
						__( 'Лимит: %1$d запросов за %2$d секунд на пользователя. Заголовки ответа: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset; при превышении — 429 и Retry-After.', 'wp-smart-search' ),
						(int) WSS_Config::RATE_LIMIT_MAX,
						(int) WSS_Config::RATE_LIMIT_WIN
					)
				);
				?>
			</p>

			<p class="wss-panel-text">
				<?php esc_html_e( 'Ответы не кэшируются (Cache-Control: no-store, Vary: Authorization): выдача зависит от пользователя.', 'wp-smart-search' ); ?>
			</p>
		</details>

		<details class="wss-doc">
			<summary><?php esc_html_e( 'Коды ошибок', 'wp-smart-search' ); ?></summary>

			<?php
			$this->render_doc_table(
				[
					__( 'Код', 'wp-smart-search' ),
					__( 'HTTP', 'wp-smart-search' ),
					__( 'Когда возникает', 'wp-smart-search' ),
				],
				$this->error_doc_rows()
			);
			?>
		</details>
		<?php
	}

	/**
	 * Compact documentation table.
	 *
	 * @param string[]            $head Column titles.
	 * @param array<int,string[]> $rows Cells; the first cell becomes a row header.
	 */
	private function render_doc_table( array $head, array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		echo '<div class="wss-doc-table-wrap"><table class="wss-doc-table"><thead><tr>';
		foreach ( $head as $cell ) {
			echo '<th scope="col">' . esc_html( (string) $cell ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array_values( (array) $row ) as $index => $cell ) {
				if ( 0 === $index ) {
					echo '<th scope="row">' . esc_html( (string) $cell ) . '</th>';
				} else {
					echo '<td>' . esc_html( (string) $cell ) . '</td>';
				}
			}
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Parameters of GET|POST /search.
	 *
	 * @return array<int,array{0:string,1:string,2:string,3:string}>
	 */
	private function search_param_docs(): array {
		return [
			[
				'q',
				'string',
				sprintf(
					/* translators: 1: minimum length, 2: maximum number of words */
					__( 'от %1$d символов, до %2$d слов', 'wp-smart-search' ),
					(int) WSS_Config::MIN_LEN,
					(int) WSS_Config::MAX_WORDS
				),
				__( 'Поисковый запрос; слова ищутся по основам и объединяются по «И». Для scope=links можно не передавать, если задан url, domain, host или anchor.', 'wp-smart-search' ),
			],
			[
				'scope',
				'string',
				implode( ', ', WSS_Config::SCOPES ) . __( ' (по умолчанию posts)', 'wp-smart-search' ),
				__( 'Что искать: записи, индексированные ссылки или и то и другое.', 'wp-smart-search' ),
			],
			[
				'type',
				'string',
				__( 'all, tags, posts (по умолчанию posts)', 'wp-smart-search' ),
				__( 'Разделы выдачи для scope=posts или all.', 'wp-smart-search' ),
			],
			[
				'match',
				'string',
				implode( ', ', WSS_Config::MATCH_MODES ) . __( ' (по умолчанию auto)', 'wp-smart-search' ),
				__( 'auto — дополнительно ищет точное совпадение по URL или ID записи; fuzzy — только по словам; exact — только точное совпадение.', 'wp-smart-search' ),
			],
			[
				'page',
				'integer',
				__( '≥ 1 (по умолчанию 1)', 'wp-smart-search' ),
				__( 'Номер страницы выдачи.', 'wp-smart-search' ),
			],
			[
				'per_page',
				'integer',
				sprintf(
					/* translators: 1: maximum per page, 2: default per page */
					__( '1…%1$d (по умолчанию %2$d)', 'wp-smart-search' ),
					(int) WSS_Config::POSTS_PER_PAGE_MAX,
					(int) WSS_Config::POSTS_PER_PAGE
				),
				__( 'Элементов на страницу.', 'wp-smart-search' ),
			],
			[
				'statuses',
				'array|string',
				sprintf(
					/* translators: %s: list of statuses */
					__( '%s (по умолчанию все)', 'wp-smart-search' ),
					implode( ', ', WSS_Config::ALLOWED_STATUSES )
				),
				__( 'Статусы записей. Неизвестный статус — 400. Права по-прежнему ограничивают выдачу: чужие приватные записи недоступны.', 'wp-smart-search' ),
			],
			[
				'date_range',
				'string',
				__( 'any, today, week, month, year (по умолчанию any)', 'wp-smart-search' ),
				__( 'Готовый диапазон по дате публикации.', 'wp-smart-search' ),
			],
			[
				'date_from',
				'string',
				__( 'ISO-дата (по умолчанию пусто)', 'wp-smart-search' ),
				__( 'Нижняя граница post_date включительно.', 'wp-smart-search' ),
			],
			[
				'date_to',
				'string',
				__( 'ISO-дата (по умолчанию пусто)', 'wp-smart-search' ),
				__( 'Верхняя граница включительно; без времени — до 23:59:59.', 'wp-smart-search' ),
			],
			[
				'author',
				'integer',
				__( 'ID автора (по умолчанию 0 — все)', 'wp-smart-search' ),
				__( 'Фильтр по автору. Только сужает выдачу, прав не расширяет.', 'wp-smart-search' ),
			],
			[
				'post_type',
				'string',
				__( 'post, page, … (по умолчанию post)', 'wp-smart-search' ),
				__( 'Тип записи. В API неизвестный тип — 400.', 'wp-smart-search' ),
			],
			[
				'orderby',
				'string',
				__( 'date_desc, date_asc (по умолчанию date_desc)', 'wp-smart-search' ),
				__( 'Сортировка по дате.', 'wp-smart-search' ),
			],
			[
				'exclude',
				'array|string',
				sprintf(
					/* translators: %d: maximum number of IDs */
					__( 'до %d ID (по умолчанию пусто)', 'wp-smart-search' ),
					(int) WSS_Config::MAX_EXCLUDE
				),
				__( 'ID записей, которые не должны попасть в выдачу.', 'wp-smart-search' ),
			],
			[
				'exclude_id',
				'integer|array',
				__( 'ID или массив ID', 'wp-smart-search' ),
				__( 'То же, что exclude.', 'wp-smart-search' ),
			],
			[
				'url',
				'string',
				__( 'URL (по умолчанию пусто)', 'wp-smart-search' ),
				__( 'Для scope=links: точное совпадение после нормализации (схема, www, UTM-метки, слэш).', 'wp-smart-search' ),
			],
			[
				'domain',
				'string',
				__( 'example.com', 'wp-smart-search' ),
				__( 'Для scope=links: registrable domain, включая поддомены.', 'wp-smart-search' ),
			],
			[
				'host',
				'string',
				__( 'www.example.com', 'wp-smart-search' ),
				__( 'Для scope=links: точный хост без поддоменов.', 'wp-smart-search' ),
			],
			[
				'anchor',
				'string',
				__( 'подстрока', 'wp-smart-search' ),
				__( 'Для scope=links: подстрока в тексте анкора.', 'wp-smart-search' ),
			],
			[
				'fields',
				'array|string',
				sprintf(
					/* translators: 1: available fields, 2: always returned fields */
					__( '%1$s; всегда возвращаются %2$s', 'wp-smart-search' ),
					implode( ', ', WSS_Config::LINK_FIELDS ),
					implode( ', ', WSS_Config::LINK_FIELDS_REQUIRED )
				),
				__( 'Проекция полей links.items. Неизвестное поле — 400.', 'wp-smart-search' ),
			],
			[
				'exact_total',
				'boolean',
				__( 'по умолчанию true', 'wp-smart-search' ),
				__( 'false убирает COUNT и поле total у links (экономит запрос).', 'wp-smart-search' ),
			],
		];
	}

	/**
	 * Body fields of POST /links/batch.
	 *
	 * @return array<int,array{0:string,1:string,2:string,3:string}>
	 */
	private function batch_body_docs(): array {
		return [
			[
				'domains',
				'array<string>',
				sprintf(
					/* translators: %d: maximum lookups per call */
					__( 'до %d значений', 'wp-smart-search' ),
					(int) WSS_Config::BATCH_MAX
				),
				__( 'Registrable domain: вернёт ссылки этого домена и его поддоменов.', 'wp-smart-search' ),
			],
			[
				'hosts',
				'array<string>',
				__( 'до BATCH_MAX значений', 'wp-smart-search' ),
				__( 'Точный хост без поддоменов.', 'wp-smart-search' ),
			],
			[
				'urls',
				'array<string>',
				__( 'до BATCH_MAX значений', 'wp-smart-search' ),
				__( 'Точный URL после нормализации.', 'wp-smart-search' ),
			],
			[
				'anchors',
				'array<string>',
				__( 'до BATCH_MAX значений', 'wp-smart-search' ),
				__( 'Подстрока в тексте анкора.', 'wp-smart-search' ),
			],
			[
				'per_page',
				'integer',
				sprintf(
					/* translators: 1: maximum, 2: default */
					__( '1…%1$d (по умолчанию %2$d)', 'wp-smart-search' ),
					(int) WSS_Config::POSTS_PER_PAGE_MAX,
					5
				),
				__( 'Элементов на каждый запрос в results[].', 'wp-smart-search' ),
			],
			[
				'statuses',
				'array|string',
				__( 'как у /search', 'wp-smart-search' ),
				__( 'Статусы записей; права ограничивают выдачу так же.', 'wp-smart-search' ),
			],
			[
				'fields',
				'array|string',
				__( 'как у /search', 'wp-smart-search' ),
				__( 'Проекция полей элементов.', 'wp-smart-search' ),
			],
			[
				'post_type',
				'string',
				__( 'post (по умолчанию)', 'wp-smart-search' ),
				__( 'Тип записи для поиска.', 'wp-smart-search' ),
			],
		];
	}

	/**
	 * Response fields of GET|POST /me.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	private function me_field_docs(): array {
		return [
			[ 'id, name, roles', 'integer, string, array', __( 'Кто вызвал метод.', 'wp-smart-search' ) ],
			[ 'can_search', 'boolean', __( 'Есть ли право на поиск (publish_posts).', 'wp-smart-search' ) ],
			[ 'can_filter_by_author', 'boolean', __( 'Можно ли фильтровать по автору (edit_others_posts).', 'wp-smart-search' ) ],
			[ 'allowed_statuses', 'array', __( 'Все статусы, которые вообще поддерживает API.', 'wp-smart-search' ) ],
			[ 'visibility_mode', 'string', __( 'shared — видны черновики всех авторов; own — только опубликованное и своё.', 'wp-smart-search' ) ],
			[ 'visible_statuses', 'array', __( 'Какие статусы реально попадут в выдачу этого пользователя.', 'wp-smart-search' ) ],
			[ 'private_scope', 'string', __( 'own — приватные записи только свои; all — все (нужны edit_others_posts и read_private_posts).', 'wp-smart-search' ) ],
			[ 'max_per_page, max_words, max_query_len', 'integer', __( 'Пределы параметров запроса.', 'wp-smart-search' ) ],
			[ 'max_matched_posts', 'integer', __( 'После какого числа совпадений список обрезается (posts.truncated).', 'wp-smart-search' ) ],
			[ 'batch_limit', 'integer', __( 'Сколько запросов принимает /links/batch за вызов.', 'wp-smart-search' ) ],
			[ 'link_fields, required_link_fields', 'array', __( 'Допустимые поля links.items и те, что возвращаются всегда.', 'wp-smart-search' ) ],
			[ 'cache_backend', 'string', __( 'redis или none — активный бэкенд кэша.', 'wp-smart-search' ) ],
			[ 'index', 'object', __( 'Состояние индекса ссылок: table, done, cursor, processed, errors (счётчики links/posts — только администратору).', 'wp-smart-search' ) ],
			[ 'update', 'object', __( 'Диагностика обновления: plugin_basename, transient_entry, transient_key, new_version, package.', 'wp-smart-search' ) ],
			[ 'match_modes, scopes', 'array', __( 'Допустимые значения параметров match и scope.', 'wp-smart-search' ) ],
			[ 'plugin_version, api_version', 'string', __( 'Версия плагина и версия API.', 'wp-smart-search' ) ],
		];
	}

	/**
	 * Error codes of the REST namespace.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	private function error_doc_rows(): array {
		return [
			[ 'wss_unauthorized', '401', __( 'Нет заголовка Authorization (или он пуст).', 'wp-smart-search' ) ],
			[ 'wss_invalid_credentials', '401', __( 'Неверный Application Password или пользователь.', 'wp-smart-search' ) ],
			[ 'wss_forbidden', '403', __( 'У пользователя нет права publish_posts.', 'wp-smart-search' ) ],
			[ 'wss_rate_limited', '429', __( 'Превышен лимит запросов; смотрите Retry-After.', 'wp-smart-search' ) ],
			[ 'wss_invalid_statuses', '400', __( 'Статус не входит в allowed_statuses.', 'wp-smart-search' ) ],
			[ 'wss_invalid_fields', '400', __( 'Неизвестное поле в fields или не строка в списке.', 'wp-smart-search' ) ],
			[ 'wss_invalid_post_type', '400', __( 'post_type не зарегистрирован.', 'wp-smart-search' ) ],
			[ 'wss_empty', '400', __( 'Пустой q при поиске по записям.', 'wp-smart-search' ) ],
			[ 'wss_too_short', '400', __( 'q короче минимальной длины и нет фильтра по ссылке.', 'wp-smart-search' ) ],
			[ 'wss_empty_batch', '400', __( 'В batch не передано ни одного значения.', 'wp-smart-search' ) ],
			[ 'wss_batch_value_too_long', '400', __( 'Значение в batch длиннее 2048 символов.', 'wp-smart-search' ) ],
			[ 'wss_internal_error', '500', __( 'Ответ получился не JSON (ошибка PHP или вывод другого плагина).', 'wp-smart-search' ) ],
		];
	}

	private function authors_list(): array {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return [];
		}
		$users = get_users( [
			'capability' => 'publish_posts',
			'fields'     => [ 'ID', 'display_name' ],
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		] );
		$out = [];
		foreach ( $users as $u ) {
			$out[] = [ 'id' => (int) $u->ID, 'name' => $u->display_name ];
		}
		return $out;
	}
}
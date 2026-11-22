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
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( WSS_Config::NONCE_ACTION ),
				'action'       => WSS_Config::AJAX_ACTION,
				'minLen'       => WSS_Config::MIN_LEN,
				'initialQuery' => $this->initial_query(),
				'pageUrl'      => admin_url( 'edit.php?page=' . WSS_Config::PAGE_SLUG ),
				'apiUrl'       => rest_url( WSS_Config::REST_NAMESPACE . '/search' ),
				'apiMeUrl'     => rest_url( WSS_Config::REST_NAMESPACE . '/me' ),
				'i18n'         => [
					'searching'   => __( 'Поиск…', 'wp-smart-search' ),
					'noResults'   => __( 'Ничего не найдено', 'wp-smart-search' ),
					'noTags'      => __( 'Теги не найдены', 'wp-smart-search' ),
					'noPosts'     => __( 'Записи не найдены', 'wp-smart-search' ),
					'error'       => __( 'Ошибка поиска', 'wp-smart-search' ),
					'tags'        => __( 'Теги', 'wp-smart-search' ),
					'posts'       => __( 'Записи', 'wp-smart-search' ),
					'catsLabel'   => __( 'Категории', 'wp-smart-search' ),
					'tagsLabel'   => __( 'Метки', 'wp-smart-search' ),
					'authorLabel' => __( 'Автор', 'wp-smart-search' ),
					'minLenTpl'   => __( 'Введите не менее %d символов', 'wp-smart-search' ),
					'showAllTags' => __( 'Показать все', 'wp-smart-search' ),
					'prevPage'    => __( 'Предыдущая страница', 'wp-smart-search' ),
					'nextPage'    => __( 'Следующая страница', 'wp-smart-search' ),
					'pageLabel'   => __( 'Страница %d', 'wp-smart-search' ),
					'emptyState'  => __( 'Введите запрос для поиска по записям и тегам', 'wp-smart-search' ),
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
		$curl_example    = 'curl -s -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \\' . "\n"
			. '  "' . $endpoint_search . '?q=wordpress&per_page=10"';
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
						<?php esc_html_e( 'Аутентификация — только заголовок Authorization с Application Password (Basic). Cookie-аутентификация на этих маршрутах не поддерживается; минимальная роль — с правом publish_posts.', 'wp-smart-search' ); ?>
					</p>

					<dl class="wss-api-list">
						<dt><?php esc_html_e( 'Поиск по записям и ссылкам', 'wp-smart-search' ); ?></dt>
						<dd>
							<code><?php echo esc_html( $endpoint_search ); ?></code>
							<span class="wss-api-method">GET, POST</span>
						</dd>
						<dt><?php esc_html_e( 'Пакетный поиск по ссылкам', 'wp-smart-search' ); ?></dt>
						<dd>
							<code><?php echo esc_html( $endpoint_batch ); ?></code>
							<span class="wss-api-method">POST</span>
						</dd>
						<dt><?php esc_html_e( 'Права, лимиты и состояние индекса', 'wp-smart-search' ); ?></dt>
						<dd>
							<code><?php echo esc_html( $endpoint_me ); ?></code>
							<span class="wss-api-method">GET, POST</span>
						</dd>
					</dl>

					<p class="wss-api-line">
						<strong><?php esc_html_e( 'Лимит запросов:', 'wp-smart-search' ); ?></strong>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: requests in the window, 2: window length in seconds */
								__( '%1$d запросов за %2$d секунд на пользователя; превышение — ответ 429 с заголовком Retry-After.', 'wp-smart-search' ),
								(int) WSS_Config::RATE_LIMIT_MAX,
								(int) WSS_Config::RATE_LIMIT_WIN
							)
						);
						?>
					</p>

					<p class="wss-panel-subtitle"><?php esc_html_e( 'Пример', 'wp-smart-search' ); ?></p>
					<pre class="wss-api-code"><code><?php echo esc_html( $curl_example ); ?></code></pre>

					<p class="wss-panel-text">
						<?php esc_html_e( 'Параметры запроса (q, scope, statuses, date_range, fields, per_page) и формат ответа описаны в README плагина.', 'wp-smart-search' ); ?>
					</p>
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
						<p class="wss-index-line">
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
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'wss_index_rebuild' ); ?>
							<input type="hidden" name="action" value="wss_index_rebuild">
							<button type="submit" class="wss-reset-btn"><?php esc_html_e( 'Переиндексировать ссылки', 'wp-smart-search' ); ?></button>
						</form>
					<?php endif; ?>

					<p class="wss-panel-subtitle"><?php esc_html_e( 'Из консоли', 'wp-smart-search' ); ?></p>
					<pre class="wss-api-code"><code>wp wss index status
wp wss index rebuild --batch-size=200
wp wss index clear</code></pre>
				</div>
			</section>
		</div>
		<?php
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
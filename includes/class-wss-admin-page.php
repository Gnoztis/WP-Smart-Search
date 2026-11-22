<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Admin_Page {

	public function register_hooks(): void {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'admin_body_class',      [ $this, 'add_body_class' ] );
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
		$content = file_get_contents( $real );
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
		?>
		<div class="wrap wss-wrap">
			<div id="wss-progress" class="wss-progress" aria-hidden="true">
				<div class="wss-progress-bar"></div>
			</div>

			<h1 class="wss-title"><?php esc_html_e( 'Умный поиск', 'wp-smart-search' ); ?></h1>

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

						<details class="wss-filter-section wss-api-section">
							<summary><?php esc_html_e( 'API', 'wp-smart-search' ); ?></summary>
							<div class="wss-api-body">
								<p class="wss-api-line"><strong><?php esc_html_e( 'Endpoint:', 'wp-smart-search' ); ?></strong><br>
									<code><?php echo esc_html( rest_url( WSS_Config::REST_NAMESPACE . '/search' ) ); ?></code></p>
								<p class="wss-api-line"><strong><?php esc_html_e( 'Авторизация:', 'wp-smart-search' ); ?></strong>
									Application Password (Basic auth)</p>
								<p class="wss-api-line"><strong><?php esc_html_e( 'Проверка доступа:', 'wp-smart-search' ); ?></strong><br>
									<code><?php echo esc_html( rest_url( WSS_Config::REST_NAMESPACE . '/me' ) ); ?></code></p>
							</div>
						</details>
					</div>
				</aside>
			</div>
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
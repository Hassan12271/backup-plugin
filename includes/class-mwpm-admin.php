<?php
/**
 * Admin page, AJAX endpoints and backup downloads.
 *
 * Start actions need a logged-in administrator and a nonce. Every following
 * step is authorised by the job's secret token instead, because an import
 * replaces the users table (and with it the current login) mid-way.
 */

defined( 'ABSPATH' ) || exit;

class MWPM_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'mwpm';

	private static $ob_level = 0;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MWPM_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_mwpm_download', array( __CLASS__, 'download' ) );

		foreach ( array( 'export_start', 'import_start', 'delete' ) as $action ) {
			add_action( 'wp_ajax_mwpm_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		foreach ( array( 'upload', 'step' ) as $action ) {
			add_action( 'wp_ajax_mwpm_' . $action, array( __CLASS__, 'ajax_' . $action ) );
			add_action( 'wp_ajax_nopriv_mwpm_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function menu() {
		add_menu_page( 'SiteShift Migration', 'SiteShift', self::CAP, self::SLUG, array( __CLASS__, 'page' ), 'dashicons-migrate', 76 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Backup / Migrate</a>' );
		return $links;
	}

	public static function chunk_size() {
		$size = (int) min( 8 * MB_IN_BYTES, max( 65536, wp_max_upload_size() - 131072 ) );
		return (int) apply_filters( 'mwpm_chunk_size', $size );
	}

	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'toplevel_page_' . self::SLUG !== $hook && self::SLUG !== $page ) {
			return;
		}
		// File modification time in the version, so browsers never use a stale copy after an update.
		$css = MWPM_VERSION . '.' . (int) @filemtime( MWPM_DIR . 'assets/admin.css' );
		$js  = MWPM_VERSION . '.' . (int) @filemtime( MWPM_DIR . 'assets/admin.js' );
		wp_enqueue_style( 'mwpm-admin', MWPM_URL . 'assets/admin.css', array(), $css );
		wp_enqueue_script( 'mwpm-admin', MWPM_URL . 'assets/admin.js', array(), $js, true );
		wp_localize_script(
			'mwpm-admin',
			'MWPM',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'mwpm' ),
				'chunkSize' => self::chunk_size(),
				'extension' => MWPM_Storage::EXT,
			)
		);
	}

	public static function download_url( $file ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=mwpm_download&file=' . rawurlencode( $file ) ), 'mwpm_download' );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	private static function begin() {
		@ini_set( 'display_errors', '0' );
		self::$ob_level = ob_get_level();
		ob_start();
	}

	private static function clean() {
		while ( ob_get_level() > self::$ob_level ) {
			ob_end_clean();
		}
	}

	private static function send( $data ) {
		self::clean();
		wp_send_json_success( $data );
	}

	private static function fail( $message, $status = 200 ) {
		self::clean();
		wp_send_json_error( array( 'message' => $message ), $status );
	}

	private static function guard() {
		self::begin();
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'mwpm', 'nonce', false ) ) {
			self::fail( 'You are not allowed to do this. Please reload the page and try again.', 403 );
		}
	}

	private static function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security
	}

	private static function job() {
		$state = MWPM_Storage::load_job( self::post( 'job' ), self::post( 'token' ) );
		if ( ! $state ) {
			self::fail( 'This job has expired or is invalid. Please start again.', 403 );
		}
		return $state;
	}

	private static function job_response( array $state ) {
		return array(
			'job'   => $state['id'],
			'token' => $state['token'],
		);
	}

	public static function ajax_export_start() {
		self::guard();
		try {
			$options = array();
			foreach ( MWPM_Export::OPTIONS as $key ) {
				$options[ $key ] = '1' === self::post( $key );
			}
			self::send( self::job_response( MWPM_Export::start( $options ) ) );
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage() );
		}
	}

	public static function ajax_import_start() {
		self::guard();
		try {
			if ( 'backup' === self::post( 'mode' ) ) {
				$path = MWPM_Storage::backup_path( self::post( 'file' ) );
				if ( ! $path ) {
					self::fail( 'Backup file not found.' );
				}
				$state = MWPM_Import::start_backup( $path );
			} else {
				$size = (int) self::post( 'size', 0 );
				if ( $size <= 0 ) {
					self::fail( 'The selected file is empty.' );
				}
				MWPM_Storage::ensure();
				$free = function_exists( 'disk_free_space' ) ? @disk_free_space( MWPM_Storage::dir() ) : false;
				if ( false !== $free && $free < $size ) {
					self::fail( sprintf( 'Not enough disk space: the file needs %1$s but only %2$s is free.', MWPM_Storage::format_size( $size ), MWPM_Storage::format_size( $free ) ) );
				}
				$state = MWPM_Import::start_upload( (string) self::post( 'name' ), $size );
			}
			self::send( self::job_response( $state ) );
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage() );
		}
	}

	public static function ajax_upload() {
		self::begin();
		$state = self::job();

		$file = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null; // phpcs:ignore WordPress.Security
		if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$code = $file && isset( $file['error'] ) ? (int) $file['error'] : -1;
			if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
				self::fail( 'The server rejected the upload chunk as too large. Lower it with the "mwpm_chunk_size" filter.' );
			}
			self::fail( 'The upload chunk did not arrive (error code ' . $code . '). Retrying usually helps.' );
		}

		try {
			MWPM_Storage::prepare_request();
			self::send( MWPM_Import::receive_chunk( $state, (int) self::post( 'offset', -1 ), $file['tmp_name'] ) );
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage() );
		}
	}

	public static function ajax_step() {
		self::begin();
		$state  = self::job();
		$budget = MWPM_Storage::prepare_request();
		try {
			$runner = 'export' === $state['type'] ? new MWPM_Export( $state, $budget ) : new MWPM_Import( $state, $budget );
			self::send( $runner->step() );
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage() );
		}
	}

	public static function ajax_delete() {
		self::guard();
		$path = MWPM_Storage::backup_path( self::post( 'file' ) );
		if ( ! $path || ! @unlink( $path ) ) {
			self::fail( 'Could not delete the backup.' );
		}
		self::send( array( 'deleted' => true ) );
	}

	/* ---------------------------------------------------------------------
	 * Download (supports HTTP Range so downloads can resume)
	 * ------------------------------------------------------------------- */

	public static function download() {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( $nonce, 'mwpm_download' ) ) {
			wp_die( 'You are not allowed to download this file.', 403 );
		}
		$name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$path = MWPM_Storage::backup_path( $name );
		if ( ! $path ) {
			wp_die( 'Backup file not found.', 404 );
		}

		$size  = MWPM_Storage::filesize( $path );
		$start = 0;
		$end   = $size - 1;
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/^bytes=(\d*)-(\d*)$/', trim( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $m ) ) {
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			} elseif ( '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			}
			if ( $start > $end ) {
				header( 'HTTP/1.1 416 Range Not Satisfiable' );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			header( 'HTTP/1.1 206 Partial Content' );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			wp_die( 'Could not open the backup file.', 500 );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		MWPM_Storage::prepare_request();
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Accel-Buffering: no' );

		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) && ! connection_aborted() ) {
			$data = fread( $fh, (int) min( MWPM_Archive::CHUNK, $left ) );
			if ( false === $data || '' === $data ) {
				break;
			}
			echo $data; // phpcs:ignore WordPress.Security.EscapeOutput
			$left -= strlen( $data );
			flush();
		}
		fclose( $fh );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------- */

	/**
	 * Inline SVG icon (Lucide, ISC licence). Decorative: hidden from screen readers.
	 */
	public static function icon( $name, $class = '' ) {
		static $icons = array(
			'migrate'  => '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
			'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
			'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
			'palette'  => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
			'plug'     => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
			'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
			'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
			'cloud'    => '<path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/>',
			'archive'  => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
			'alert'    => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'check'    => '<path d="M20 6 9 17l-5-5"/>',
			'success'  => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
			'error'    => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
			'trash'    => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
			'restore'  => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
			'plus'     => '<path d="M5 12h14"/><path d="M12 5v14"/>',
			'shield'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
			'info'     => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
			'drive'    => '<line x1="22" x2="2" y1="12" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" x2="6.01" y1="16" y2="16"/><line x1="10" x2="10.01" y1="16" y2="16"/>',
			'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
			'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
			'sparkles' => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.287 1.288L3 12l5.8 1.9a2 2 0 0 1 1.288 1.287L12 21l1.9-5.8a2 2 0 0 1 1.287-1.288L21 12l-5.8-1.9a2 2 0 0 1-1.288-1.287Z"/>',
		);
		if ( ! isset( $icons[ $name ] ) ) {
			return '';
		}
		// width/height attributes keep icons small even if the stylesheet fails to load.
		return '<svg class="mwpm-svg ' . esc_attr( $class ) . '" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[ $name ] . '</svg>';
	}

	/**
	 * Cheap numbers shown on the export tiles.
	 */
	private static function site_stats() {
		global $wpdb;

		$db = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		$media = (array) wp_count_attachments();
		unset( $media['trash'] );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array(
			'db'      => $db,
			'media'   => (int) array_sum( $media ),
			'plugins' => count( get_plugins() ),
			'themes'  => count( wp_get_themes() ),
		);
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$error = '';
		try {
			MWPM_Storage::ensure();
			MWPM_Storage::cleanup();
		} catch ( Exception $e ) {
			$error = $e->getMessage();
		}

		$backups = MWPM_Storage::backups();
		$stats   = self::site_stats();
		$chunk   = MWPM_Storage::format_size( self::chunk_size() );
		$used    = array_sum( wp_list_pluck( $backups, 'size' ) );
		$last    = $backups ? $backups[0]['time'] : 0;
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$ext     = MWPM_Storage::EXT;
		$rel_dir = ltrim( str_replace( wp_normalize_path( ABSPATH ), '', MWPM_Storage::dir() ), '/' );

		$tiles   = array(
			'no_database' => array( 'database', 'Database', sprintf( '%s · posts, pages, users, settings', MWPM_Storage::format_size( $stats['db'] ) ) ),
			'no_media'    => array( 'image', 'Media library', sprintf( '%s files in uploads', number_format_i18n( $stats['media'] ) ) ),
			'no_themes'   => array( 'palette', 'Themes', sprintf( '%s installed', number_format_i18n( $stats['themes'] ) ) ),
			'no_plugins'  => array( 'plug', 'Plugins', sprintf( '%s installed', number_format_i18n( $stats['plugins'] ) ) ),
		);
		$cleanup = array(
			'no_spam'      => array( 'Skip spam comments', 'Spam never needs to move.' ),
			'no_revisions' => array( 'Skip post revisions', 'Old drafts of posts, often a big part of the database.' ),
		);
		?>
		<div class="wrap mwpm">
			<hr class="wp-header-end">

			<header class="mwpm-hero">
				<div class="mwpm-hero-top">
					<div class="mwpm-brand">
						<span class="mwpm-logo"><?php echo self::icon( 'migrate' ); // phpcs:ignore ?></span>
						<div>
							<h1>SiteShift Migration</h1>
							<p>Backup, move and restore your whole WordPress site with no size limits.</p>
						</div>
					</div>
					<span class="mwpm-version">v<?php echo esc_html( MWPM_VERSION ); ?></span>
				</div>

				<dl class="mwpm-stats">
					<div class="mwpm-stat">
						<dt><?php echo self::icon( 'archive' ); // phpcs:ignore ?> Backups</dt>
						<dd data-stat="count"><?php echo (int) count( $backups ); ?></dd>
					</div>
					<div class="mwpm-stat">
						<dt><?php echo self::icon( 'drive' ); // phpcs:ignore ?> Storage used</dt>
						<dd><?php echo esc_html( MWPM_Storage::format_size( $used ) ); ?></dd>
					</div>
					<div class="mwpm-stat">
						<dt><?php echo self::icon( 'clock' ); // phpcs:ignore ?> Last backup</dt>
						<dd><?php echo esc_html( $last ? sprintf( '%s ago', human_time_diff( $last ) ) : 'Never' ); ?></dd>
					</div>
					<div class="mwpm-stat">
						<dt><?php echo self::icon( 'globe' ); // phpcs:ignore ?> This site</dt>
						<dd class="mwpm-mono"><?php echo esc_html( $host ); ?></dd>
					</div>
				</dl>

				<nav class="mwpm-tabs" role="tablist" aria-label="SiteShift Migration sections">
					<button type="button" class="mwpm-tab" role="tab" id="mwpm-tab-export" data-tab="export" aria-controls="mwpm-panel-export"><?php echo self::icon( 'download' ); // phpcs:ignore ?> Export</button>
					<button type="button" class="mwpm-tab" role="tab" id="mwpm-tab-import" data-tab="import" aria-controls="mwpm-panel-import"><?php echo self::icon( 'upload' ); // phpcs:ignore ?> Import</button>
					<button type="button" class="mwpm-tab" role="tab" id="mwpm-tab-backups" data-tab="backups" aria-controls="mwpm-panel-backups"><?php echo self::icon( 'archive' ); // phpcs:ignore ?> Backups <span class="mwpm-badge" data-stat="count"><?php echo (int) count( $backups ); ?></span></button>
				</nav>
			</header>

			<?php if ( $error ) : ?>
				<div class="mwpm-alert mwpm-alert-error" role="alert"><?php echo self::icon( 'error' ); // phpcs:ignore ?><div><?php echo esc_html( $error ); ?></div></div>
			<?php endif; ?>
			<?php if ( is_multisite() ) : ?>
				<div class="mwpm-alert mwpm-alert-warning"><?php echo self::icon( 'alert' ); // phpcs:ignore ?><div>This is a multisite network. SiteShift Migration is built for single sites, so a network may not migrate correctly.</div></div>
			<?php endif; ?>

			<section class="mwpm-panel" id="mwpm-panel-export" role="tabpanel" aria-labelledby="mwpm-tab-export" data-panel="export">
				<div class="mwpm-grid">
					<div class="mwpm-card">
						<h2>Export this site</h2>
						<p class="mwpm-muted">Pick what goes into the backup. Everything is packed into one <code>.<?php echo esc_html( $ext ); ?></code> file that you can download or import on any site.</p>

						<fieldset class="mwpm-fieldset">
							<legend>Include</legend>
							<div class="mwpm-tiles">
								<?php foreach ( $tiles as $key => $tile ) : ?>
									<label class="mwpm-tile">
										<input type="checkbox" data-opt="<?php echo esc_attr( $key ); ?>" data-invert="1" checked>
										<span class="mwpm-tile-icon"><?php echo self::icon( $tile[0] ); // phpcs:ignore ?></span>
										<span class="mwpm-tile-text">
											<strong><?php echo esc_html( $tile[1] ); ?></strong>
											<small><?php echo esc_html( $tile[2] ); ?></small>
										</span>
										<span class="mwpm-tile-check"><?php echo self::icon( 'check' ); // phpcs:ignore ?></span>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="mwpm-field-error" id="mwpm-include-error" role="alert" hidden><?php echo self::icon( 'alert' ); // phpcs:ignore ?> Select at least one item to export.</p>
						</fieldset>

						<fieldset class="mwpm-fieldset">
							<legend><?php echo self::icon( 'sparkles' ); // phpcs:ignore ?> Make it smaller</legend>
							<?php foreach ( $cleanup as $key => $item ) : ?>
								<label class="mwpm-switch">
									<input type="checkbox" role="switch" data-opt="<?php echo esc_attr( $key ); ?>">
									<span class="mwpm-switch-ui" aria-hidden="true"></span>
									<span class="mwpm-switch-text">
										<strong><?php echo esc_html( $item[0] ); ?></strong>
										<small><?php echo esc_html( $item[1] ); ?></small>
									</span>
								</label>
							<?php endforeach; ?>
						</fieldset>

						<div class="mwpm-cta">
							<button type="button" class="mwpm-btn mwpm-btn-primary mwpm-btn-lg" id="mwpm-export"><?php echo self::icon( 'download' ); // phpcs:ignore ?> Export site</button>
							<p class="mwpm-hint">Caches, logs and other backup plugins' files are skipped automatically.</p>
						</div>
					</div>

					<aside class="mwpm-card mwpm-side" aria-labelledby="mwpm-howto-title">
						<h3 id="mwpm-howto-title">Moving to a new host?</h3>
						<ol class="mwpm-howto">
							<li><strong>Export</strong> this site and download the file.</li>
							<li>Install WordPress and <strong>this plugin</strong> on the new site.</li>
							<li><strong>Import</strong> the file there. Links and paths are updated automatically.</li>
						</ol>
						<p class="mwpm-note"><?php echo self::icon( 'shield' ); // phpcs:ignore ?> Backups stay on this server in a folder that is blocked from public access.</p>
					</aside>
				</div>
			</section>

			<section class="mwpm-panel" id="mwpm-panel-import" role="tabpanel" aria-labelledby="mwpm-tab-import" data-panel="import" hidden>
				<div class="mwpm-grid">
					<div class="mwpm-card">
						<h2>Import a site</h2>
						<p class="mwpm-muted">Upload a <code>.<?php echo esc_html( $ext ); ?></code> backup. It is restored automatically as soon as the upload finishes.</p>

						<div class="mwpm-alert mwpm-alert-warning">
							<?php echo self::icon( 'alert' ); // phpcs:ignore ?>
							<div><strong>This replaces the current site.</strong> Its database, media, themes and plugins are overwritten. Create a backup first if you might need it.</div>
						</div>

						<div class="mwpm-drop" id="mwpm-drop">
							<span class="mwpm-drop-icon"><?php echo self::icon( 'cloud' ); // phpcs:ignore ?></span>
							<p class="mwpm-drop-title">Drag &amp; drop your backup here</p>
							<p class="mwpm-drop-sub">or</p>
							<button type="button" class="mwpm-btn mwpm-btn-secondary" id="mwpm-choose"><?php echo self::icon( 'upload' ); // phpcs:ignore ?> Choose file</button>
							<input type="file" id="mwpm-file" accept=".<?php echo esc_attr( $ext ); ?>" hidden>
							<p class="mwpm-drop-meta"><span>.<?php echo esc_html( $ext ); ?> files only</span><span>No size limit</span><span>Uploaded in <?php echo esc_html( $chunk ); ?> pieces</span></p>
						</div>
					</div>

					<aside class="mwpm-card mwpm-side" aria-labelledby="mwpm-large-title">
						<h3 id="mwpm-large-title">Huge backup or slow internet?</h3>
						<p>Upload the file with FTP or your hosting file manager into:</p>
						<p><code class="mwpm-path"><?php echo esc_html( $rel_dir ); ?>/</code></p>
						<p>It then shows up under <a href="#backups" data-tab-link="backups">Backups</a>, ready to restore.</p>
						<h3>After importing</h3>
						<ul class="mwpm-checklist">
							<li><?php echo self::icon( 'check' ); // phpcs:ignore ?> <span>Log in with the <strong>old site's</strong> username and password.</span></li>
							<li><?php echo self::icon( 'check' ); // phpcs:ignore ?> <span>Open Settings → Permalinks and click Save.</span></li>
						</ul>
					</aside>
				</div>
			</section>

			<section class="mwpm-panel" id="mwpm-panel-backups" role="tabpanel" aria-labelledby="mwpm-tab-backups" data-panel="backups" hidden>
				<div class="mwpm-card">
					<div class="mwpm-card-head">
						<div>
							<h2>Backups</h2>
							<p class="mwpm-muted">Stored on this server in <code><?php echo esc_html( $rel_dir ); ?></code></p>
						</div>
						<button type="button" class="mwpm-btn mwpm-btn-primary" data-action="export"><?php echo self::icon( 'plus' ); // phpcs:ignore ?> Create backup</button>
					</div>

					<ul class="mwpm-list" id="mwpm-list"<?php echo $backups ? '' : ' hidden'; ?>>
						<?php foreach ( $backups as $backup ) : ?>
							<li class="mwpm-item" data-file="<?php echo esc_attr( $backup['name'] ); ?>">
								<span class="mwpm-item-icon"><?php echo self::icon( 'archive' ); // phpcs:ignore ?></span>
								<div class="mwpm-item-main">
									<span class="mwpm-item-name mwpm-mono"><?php echo esc_html( $backup['name'] ); ?></span>
									<span class="mwpm-item-meta">
										<?php echo esc_html( MWPM_Storage::format_size( $backup['size'] ) ); ?> ·
										<time datetime="<?php echo esc_attr( gmdate( 'c', $backup['time'] ) ); ?>" title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['time'] ) ); ?>"><?php echo esc_html( sprintf( '%s ago', human_time_diff( $backup['time'] ) ) ); ?></time>
									</span>
								</div>
								<div class="mwpm-item-actions">
									<a class="mwpm-btn mwpm-btn-secondary mwpm-btn-sm" href="<?php echo esc_url( self::download_url( $backup['name'] ) ); ?>"><?php echo self::icon( 'download' ); // phpcs:ignore ?> Download</a>
									<button type="button" class="mwpm-btn mwpm-btn-secondary mwpm-btn-sm mwpm-restore"><?php echo self::icon( 'restore' ); // phpcs:ignore ?> Restore</button>
									<button type="button" class="mwpm-btn mwpm-btn-icon mwpm-delete" aria-label="<?php echo esc_attr( 'Delete ' . $backup['name'] ); ?>" title="Delete"><?php echo self::icon( 'trash' ); // phpcs:ignore ?></button>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>

					<div class="mwpm-empty" id="mwpm-empty"<?php echo $backups ? ' hidden' : ''; ?>>
						<span class="mwpm-empty-icon"><?php echo self::icon( 'archive' ); // phpcs:ignore ?></span>
						<h3>No backups yet</h3>
						<p>Create your first backup. It takes a few minutes, depending on the size of your site.</p>
						<button type="button" class="mwpm-btn mwpm-btn-primary" data-action="export"><?php echo self::icon( 'plus' ); // phpcs:ignore ?> Create backup</button>
					</div>
				</div>
			</section>

			<ul class="mwpm-system" aria-label="Server information">
				<li>PHP <?php echo esc_html( PHP_VERSION ); ?></li>
				<li>Max upload <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?></li>
				<li>Memory <?php echo esc_html( ini_get( 'memory_limit' ) ); ?></li>
				<li>Max execution <?php echo esc_html( (int) ini_get( 'max_execution_time' ) ); ?>s</li>
			</ul>

			<div class="mwpm-modal" id="mwpm-modal" hidden>
				<div class="mwpm-dialog" role="dialog" aria-modal="true" aria-labelledby="mwpm-modal-title" aria-describedby="mwpm-modal-status" tabindex="-1">
					<div class="mwpm-dialog-icon">
						<span class="mwpm-spinner" aria-hidden="true"></span>
						<?php echo self::icon( 'success', 'mwpm-icon-success' ); // phpcs:ignore ?>
						<?php echo self::icon( 'error', 'mwpm-icon-error' ); // phpcs:ignore ?>
					</div>
					<h2 id="mwpm-modal-title"></h2>
					<ol class="mwpm-steps" aria-label="Progress steps"></ol>
					<div class="mwpm-progress">
						<div class="mwpm-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-labelledby="mwpm-modal-title"><span></span></div>
						<div class="mwpm-progress-meta">
							<span class="mwpm-percent">0%</span>
							<span class="mwpm-elapsed"></span>
						</div>
					</div>
					<p class="mwpm-status" id="mwpm-modal-status" aria-live="polite"></p>
					<p class="mwpm-keep-open"><?php echo self::icon( 'info' ); // phpcs:ignore ?> Keep this tab open until it finishes.</p>
					<div class="mwpm-buttons"></div>
				</div>
			</div>

			<div class="mwpm-modal" id="mwpm-confirm" hidden>
				<div class="mwpm-dialog mwpm-dialog-confirm" role="alertdialog" aria-modal="true" aria-labelledby="mwpm-confirm-title" aria-describedby="mwpm-confirm-body" tabindex="-1">
					<div class="mwpm-confirm-icon"><?php echo self::icon( 'alert' ); // phpcs:ignore ?></div>
					<h2 id="mwpm-confirm-title"></h2>
					<p id="mwpm-confirm-body"></p>
					<label class="mwpm-confirm-check" hidden><input type="checkbox"> <span></span></label>
					<div class="mwpm-buttons">
						<button type="button" class="mwpm-btn mwpm-btn-secondary" data-cancel>Cancel</button>
						<button type="button" class="mwpm-btn mwpm-btn-danger" data-confirm></button>
					</div>
				</div>
			</div>

			<div class="mwpm-toast" id="mwpm-toast" role="status" aria-live="polite" hidden></div>
		</div>
		<?php
	}
}

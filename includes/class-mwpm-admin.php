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
		add_menu_page( 'My WP Migration', 'WP Migration', self::CAP, self::SLUG, array( __CLASS__, 'page' ), 'dashicons-migrate', 76 );
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
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'mwpm-admin', MWPM_URL . 'assets/admin.css', array(), MWPM_VERSION );
		wp_enqueue_script( 'mwpm-admin', MWPM_URL . 'assets/admin.js', array(), MWPM_VERSION, true );
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
		$chunk   = MWPM_Storage::format_size( self::chunk_size() );

		$options = array(
			'no_spam'      => array( 'Do not export spam comments', '' ),
			'no_revisions' => array( 'Do not export post revisions', 'Makes the backup smaller.' ),
			'no_media'     => array( 'Do not export media library (uploads folder)', '' ),
			'no_themes'    => array( 'Do not export themes', '' ),
			'no_plugins'   => array( 'Do not export plugins', '' ),
			'no_database'  => array( 'Do not export the database', 'Only files will be included.' ),
		);
		?>
		<div class="wrap mwpm">
			<h1 class="mwpm-title"><span class="dashicons dashicons-migrate" aria-hidden="true"></span> My WP Migration</h1>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-warning"><p>This is a multisite network. My WP Migration is built for single sites; multisite networks may not migrate correctly.</p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper mwpm-tabs" role="tablist">
				<a href="#export" class="nav-tab nav-tab-active" data-tab="export" role="tab"><span class="dashicons dashicons-download" aria-hidden="true"></span> Export</a>
				<a href="#import" class="nav-tab" data-tab="import" role="tab"><span class="dashicons dashicons-upload" aria-hidden="true"></span> Import</a>
				<a href="#backups" class="nav-tab" data-tab="backups" role="tab"><span class="dashicons dashicons-backup" aria-hidden="true"></span> Backups <span class="mwpm-count"><?php echo (int) count( $backups ); ?></span></a>
			</nav>

			<section class="mwpm-panel" data-panel="export">
				<div class="mwpm-card">
					<h2>Export this site</h2>
					<p class="mwpm-lead">Creates one <code>.<?php echo esc_html( MWPM_Storage::EXT ); ?></code> file with your database, media, themes and plugins. Import it on any WordPress site with this plugin — URLs and paths are updated automatically.</p>

					<details class="mwpm-advanced">
						<summary>Advanced options</summary>
						<div class="mwpm-options">
							<?php foreach ( $options as $key => $label ) : ?>
								<label class="mwpm-option">
									<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1">
									<span><?php echo esc_html( $label[0] ); ?><?php if ( $label[1] ) : ?> <small><?php echo esc_html( $label[1] ); ?></small><?php endif; ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</details>

					<button type="button" class="button button-primary button-hero mwpm-export-btn" id="mwpm-export">
						<span class="dashicons dashicons-download" aria-hidden="true"></span> Export site
					</button>
				</div>
			</section>

			<section class="mwpm-panel" data-panel="import" hidden>
				<div class="mwpm-card">
					<h2>Import a site</h2>
					<div class="mwpm-warning">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<p>Importing <strong>replaces</strong> this site's database, media, themes and plugins with the ones in the backup. Create a backup of this site first if you may need it.</p>
					</div>

					<label class="mwpm-drop" id="mwpm-drop">
						<input type="file" id="mwpm-file" accept=".<?php echo esc_attr( MWPM_Storage::EXT ); ?>">
						<span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
						<strong>Drop a .<?php echo esc_html( MWPM_Storage::EXT ); ?> file here</strong>
						<span>or <u>choose a file</u></span>
						<small>No size limit — the file is uploaded in pieces of <?php echo esc_html( $chunk ); ?>.</small>
					</label>

					<p class="description">Very large backup or slow connection? Upload the file with FTP or your hosting file manager to <code><?php echo esc_html( str_replace( wp_normalize_path( ABSPATH ), '', MWPM_Storage::dir() ) ); ?>/</code> and restore it from the <a href="#backups" data-tab-link="backups">Backups</a> tab.</p>
				</div>
			</section>

			<section class="mwpm-panel" data-panel="backups" hidden>
				<div class="mwpm-card">
					<div class="mwpm-card-head">
						<h2>Backups</h2>
						<button type="button" class="button button-primary" id="mwpm-backup-now"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Create backup</button>
					</div>

					<?php if ( $backups ) : ?>
						<table class="widefat striped mwpm-table">
							<thead>
								<tr><th>File</th><th>Size</th><th>Date</th><th class="mwpm-actions-col">Actions</th></tr>
							</thead>
							<tbody>
								<?php foreach ( $backups as $backup ) : ?>
									<tr data-file="<?php echo esc_attr( $backup['name'] ); ?>">
										<td class="mwpm-file"><span class="dashicons dashicons-media-archive" aria-hidden="true"></span> <?php echo esc_html( $backup['name'] ); ?></td>
										<td><?php echo esc_html( MWPM_Storage::format_size( $backup['size'] ) ); ?></td>
										<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['time'] ) ); ?></td>
										<td class="mwpm-actions-col">
											<a class="button" href="<?php echo esc_url( self::download_url( $backup['name'] ) ); ?>">Download</a>
											<button type="button" class="button mwpm-restore">Restore</button>
											<button type="button" class="button-link mwpm-delete">Delete</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<div class="mwpm-empty">
							<span class="dashicons dashicons-backup" aria-hidden="true"></span>
							<p>No backups yet. Click <strong>Create backup</strong> to make your first one.</p>
						</div>
					<?php endif; ?>

					<p class="description">Backups are stored in <code><?php echo esc_html( MWPM_Storage::dir() ); ?></code>, protected from direct web access.</p>
				</div>
			</section>

			<p class="mwpm-system">
				PHP <?php echo esc_html( PHP_VERSION ); ?> ·
				Max upload <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?> ·
				Memory limit <?php echo esc_html( ini_get( 'memory_limit' ) ); ?> ·
				Max execution time <?php echo esc_html( (int) ini_get( 'max_execution_time' ) ); ?>s ·
				Plugin v<?php echo esc_html( MWPM_VERSION ); ?>
			</p>

			<div class="mwpm-modal" id="mwpm-modal" hidden>
				<div class="mwpm-dialog" role="dialog" aria-modal="true" aria-labelledby="mwpm-modal-title">
					<span class="mwpm-icon dashicons" aria-hidden="true"></span>
					<h2 id="mwpm-modal-title"></h2>
					<div class="mwpm-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><span></span></div>
					<p class="mwpm-percent"></p>
					<p class="mwpm-status" aria-live="polite"></p>
					<div class="mwpm-buttons"></div>
				</div>
			</div>
		</div>
		<?php
	}
}

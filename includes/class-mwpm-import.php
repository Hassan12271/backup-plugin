<?php
/**
 * Import: receives the archive in chunks, restores wp-content, imports the
 * database into temporary tables, fixes URLs/paths and finally swaps the
 * temporary tables in place of the live ones.
 *
 * The live site keeps working on its old tables until the very last step.
 */

defined( 'ABSPATH' ) || exit;

class MWPM_Import {

	const TEMP_PREFIX = 'mwpmtmp_';

	/** Rows checked per search & replace query. */
	const BATCH = 200;

	private $state;
	private $dir;
	private $deadline;

	public static function start_upload( $name, $size ) {
		$state            = MWPM_Storage::create_job( 'import', self::defaults( 'upload', '', true ) + array( 'size' => (int) $size, 'source' => sanitize_file_name( $name ) ) );
		$state['archive'] = MWPM_Storage::job_dir( $state['id'] ) . '/upload.' . MWPM_Storage::EXT;
		MWPM_Storage::save_job( $state );
		return $state;
	}

	public static function start_backup( $path ) {
		return MWPM_Storage::create_job( 'import', self::defaults( 'validate', $path, false ) + array( 'source' => basename( $path ) ) );
	}

	private static function defaults( $stage, $archive, $uploaded ) {
		return array(
			'stage'    => $stage,
			'archive'  => $archive,
			'uploaded' => $uploaded,
			'total'    => 0,
			'package'  => null,
			'ex'       => array(
				'pos'     => 0,
				'entry'   => null,
				'bytes'   => 0,
				'files'   => 0,
				'skipped' => 0,
			),
			'imp'      => array(
				'pos'     => 0,
				'started' => false,
				'fixes'   => array(),
			),
			'rep'      => null,
			'swap'     => false,
		);
	}

	/**
	 * Appends one uploaded chunk to the archive.
	 *
	 * @param int    $offset Where the client thinks this chunk starts.
	 * @param string $tmp    Path of the uploaded chunk.
	 */
	public static function receive_chunk( array $state, $offset, $tmp ) {
		if ( 'upload' !== $state['stage'] ) {
			throw new Exception( 'The upload has already finished.' );
		}

		$path    = $state['archive'];
		$current = file_exists( $path ) ? MWPM_Storage::filesize( $path ) : 0;

		// Out of sync (e.g. a retried request): tell the client where to continue.
		if ( (int) $offset !== $current ) {
			return array(
				'offset'   => $current,
				'complete' => false,
			);
		}

		$in  = @fopen( $tmp, 'rb' );
		$out = @fopen( $path, 'ab' );
		if ( ! $in || ! $out ) {
			throw new Exception( 'Could not save the uploaded chunk. Please check folder permissions and free disk space.' );
		}
		$copied = stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		if ( false === $copied ) {
			throw new Exception( 'Could not save the uploaded chunk. Is the disk full?' );
		}

		$current  = MWPM_Storage::filesize( $path );
		$complete = $current >= $state['size'];
		if ( $complete ) {
			$state['stage'] = 'validate';
			MWPM_Storage::save_job( $state );
		}

		return array(
			'offset'   => $current,
			'complete' => $complete,
		);
	}

	public function __construct( array $state, $budget ) {
		$this->state    = $state;
		$this->dir      = MWPM_Storage::job_dir( $state['id'] );
		$this->deadline = microtime( true ) + $budget;
	}

	private function timed_out() {
		return microtime( true ) >= $this->deadline;
	}

	/**
	 * Runs one step. Progress is kept even when the step fails, so a retry
	 * continues exactly after the last successful operation.
	 */
	public function step() {
		try {
			while ( ! $this->timed_out() ) {
				switch ( $this->state['stage'] ) {
					case 'upload':
						throw new Exception( 'The upload is not complete yet.' );
					case 'validate':
						$this->step_validate();
						break;
					case 'extract':
						$this->step_extract();
						break;
					case 'db_import':
						$this->step_db_import();
						break;
					case 'db_replace':
						$this->step_db_replace();
						break;
					case 'db_swap':
						$this->step_db_swap();
						// The database changed under this request: finish in a fresh one.
						MWPM_Storage::save_job( $this->state );
						return $this->response();
					case 'finish':
						return $this->step_finish();
					default:
						throw new Exception( 'Unknown import stage.' );
				}
			}
		} catch ( Throwable $e ) {
			MWPM_Storage::save_job( $this->state );
			throw $e;
		}

		MWPM_Storage::save_job( $this->state );
		return $this->response();
	}

	private function response() {
		$stage = $this->state['stage'];
		$ex    = $this->state['ex'];

		switch ( $stage ) {
			case 'validate':
			case 'extract':
				$total    = max( 1, $this->state['total'] );
				$progress = 50 * min( 1, $ex['bytes'] / $total );
				$message  = sprintf( 'Restoring files: %1$s files (%2$s of %3$s)', number_format_i18n( $ex['files'] ), MWPM_Storage::format_size( $ex['bytes'] ), MWPM_Storage::format_size( $total ) );
				break;
			case 'db_import':
				$size     = max( 1, MWPM_Storage::filesize( $this->dir . '/database.sql' ) );
				$progress = 50 + 35 * min( 1, $this->state['imp']['pos'] / $size );
				$message  = sprintf( 'Importing database: %1$s of %2$s', MWPM_Storage::format_size( $this->state['imp']['pos'] ), MWPM_Storage::format_size( $size ) );
				break;
			case 'db_replace':
				$rep      = $this->state['rep'];
				$count    = $rep ? max( 1, count( $rep['tables'] ) ) : 1;
				$index    = $rep ? $rep['index'] : 0;
				$progress = 85 + 12 * $index / $count;
				$message  = sprintf( 'Updating URLs and paths: table %1$d of %2$d', min( $index + 1, $count ), $count );
				break;
			case 'db_swap':
				$progress = 97;
				$message  = 'Activating the imported database...';
				break;
			default:
				$progress = 99;
				$message  = 'Finishing...';
		}

		return array(
			'done'     => false,
			'stage'    => $stage,
			'progress' => round( $progress, 1 ),
			'message'  => $message,
		);
	}

	/* ---------------------------------------------------------------------
	 * Archive
	 * ------------------------------------------------------------------- */

	private function open_archive() {
		$fh = @fopen( $this->state['archive'], 'rb' );
		if ( ! $fh ) {
			throw new Exception( 'Could not open the backup file.' );
		}
		return $fh;
	}

	private function step_validate() {
		$fh = $this->open_archive();
		MWPM_Archive::check_magic( $fh );
		$header = MWPM_Archive::read_header( $fh );
		if ( ! $header || '__mwpm/package.json' !== $header['name'] || $header['size'] > 1048576 ) {
			throw new Exception( 'The backup file is corrupt (package information missing).' );
		}
		$package = json_decode( (string) fread( $fh, (int) $header['size'] ), true );
		if ( ! is_array( $package ) || empty( $package['home'] ) ) {
			throw new Exception( 'The backup file is corrupt (package information unreadable).' );
		}

		$this->state['package']   = $package;
		$this->state['ex']['pos'] = ftell( $fh );
		$this->state['total']     = MWPM_Storage::filesize( $this->state['archive'] );
		$this->state['stage']     = 'extract';
		fclose( $fh );
	}

	private function step_extract() {
		$ex =& $this->state['ex'];
		$fh = $this->open_archive();
		fseek( $fh, $ex['pos'] );

		while ( ! $this->timed_out() ) {
			if ( null === $ex['entry'] ) {
				$header = MWPM_Archive::read_header( $fh );
				if ( false === $header ) {
					$this->state['stage'] = is_file( $this->dir . '/database.sql' ) ? 'db_import' : 'finish';
					break;
				}
				$ex['entry'] = array(
					'target' => base64_encode( $this->target_for( $header['name'] ) ),
					'size'   => $header['size'],
					'mtime'  => $header['mtime'],
					'done'   => 0,
				);
				$ex['pos']   = ftell( $fh );
			}

			$target    = base64_decode( $ex['entry']['target'] );
			$remaining = $ex['entry']['size'] - $ex['entry']['done'];
			$out       = '' === $target ? false : $this->open_target( $target, $ex['entry']['done'] );

			if ( ! $out ) {
				if ( '' !== $target ) {
					$ex['skipped']++;
				}
				$ex['pos']   += $remaining;
				$ex['bytes'] += $remaining;
				$ex['entry']  = null;
				fseek( $fh, $ex['pos'] );
				continue;
			}

			$copied = MWPM_Archive::copy( $fh, $out, $remaining, $this->deadline );
			fclose( $out );
			$ex['entry']['done'] += $copied;
			$ex['pos']           += $copied;
			$ex['bytes']         += $copied;

			if ( $ex['entry']['done'] >= $ex['entry']['size'] ) {
				@touch( $target, $ex['entry']['mtime'] );
				$ex['entry'] = null;
				$ex['files']++;
			} elseif ( 0 === $copied || feof( $fh ) ) {
				throw new Exception( 'The backup file is incomplete or corrupt (unexpected end of file).' );
			}
		}

		fclose( $fh );
	}

	/**
	 * Where an archive entry is restored to, or '' to skip it.
	 */
	private function target_for( $name ) {
		if ( '__mwpm/database.sql' === $name ) {
			return $this->dir . '/database.sql';
		}
		if ( 0 === strpos( $name, '__mwpm/' ) ) {
			return '';
		}

		$name = str_replace( '\\', '/', $name );
		if ( '' === $name || false !== strpos( $name, "\0" ) || '/' === $name[0]
			|| preg_match( '#(^|/)\.\.?(/|$)#', $name ) || preg_match( '#^[A-Za-z]:#', $name ) ) {
			return '';
		}

		// Never overwrite our own storage or the copy of this plugin that is running the import.
		$lower = strtolower( $name );
		$own   = 'plugins/' . strtolower( MWPM_Storage::plugin_folder() ) . '/';
		if ( 0 === strpos( $lower, 'mwpm-backups/' ) || 0 === strpos( $lower, $own ) ) {
			return '';
		}

		return MWPM_Storage::content_dir() . '/' . $name;
	}

	private function open_target( $path, $done ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		return $done > 0 ? MWPM_Archive::open_at( $path, $done ) : @fopen( $path, 'wb' );
	}

	/* ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------- */

	private function session() {
		MWPM_DB::query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		MWPM_DB::query( 'SET SESSION foreign_key_checks = 0' );
	}

	private function step_db_import() {
		$imp =& $this->state['imp'];

		if ( ! $imp['started'] ) {
			foreach ( MWPM_DB::tables( self::TEMP_PREFIX ) as $table ) {
				MWPM_DB::query( 'DROP TABLE IF EXISTS ' . MWPM_DB::ident( $table ) );
			}
			$imp['fixes']   = $this->collation_fixes();
			$imp['started'] = true;
		}
		$this->session();

		$fh = @fopen( $this->dir . '/database.sql', 'rb' );
		if ( ! $fh ) {
			throw new Exception( 'Could not read the database dump.' );
		}
		fseek( $fh, $imp['pos'] );

		while ( ! $this->timed_out() ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				$this->state['stage'] = 'db_replace';
				break;
			}
			$sql = rtrim( $line, "\r\n" );
			if ( '' !== $sql && 0 !== strncmp( $sql, '--', 2 ) ) {
				MWPM_DB::query( $this->prepare_sql( $sql ) );
			}
			$imp['pos'] = ftell( $fh );
		}

		fclose( $fh );
	}

	/**
	 * Points a dump statement at the temporary tables and fixes collations
	 * this MySQL/MariaDB server does not know.
	 */
	private function prepare_sql( $sql ) {
		$placeholder = MWPM_Export::PLACEHOLDER;
		foreach ( array( 'REPLACE INTO `', 'INSERT INTO `', 'DROP TABLE IF EXISTS `', 'CREATE TABLE `' ) as $start ) {
			$head = $start . $placeholder;
			if ( 0 !== strncmp( $sql, $head, strlen( $head ) ) ) {
				continue;
			}
			$sql = $start . self::TEMP_PREFIX . substr( $sql, strlen( $head ) );
			if ( 'CREATE TABLE `' === $start ) {
				$sql = str_replace( 'REFERENCES `' . $placeholder, 'REFERENCES `' . self::TEMP_PREFIX, $sql );
				foreach ( $this->state['imp']['fixes'] as $fix ) {
					$sql = preg_replace( $fix[0], $fix[1], $sql );
				}
			}
			break;
		}
		return $sql;
	}

	private function collation_fixes() {
		if ( ! MWPM_DB::supports_collation( 'utf8mb4_general_ci' ) ) {
			return array(
				array( '/utf8mb4_(0900|uca1400)_\w+|utf8mb4_unicode_520_ci/', 'utf8_general_ci' ),
				array( '/utf8mb4/', 'utf8' ),
			);
		}

		$fallback = MWPM_DB::supports_collation( 'utf8mb4_unicode_520_ci' ) ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
		$fixes    = array();
		if ( ! MWPM_DB::supports_collation( 'utf8mb4_0900_ai_ci' ) ) {
			$fixes[] = array( '/utf8mb4_0900_\w+/', $fallback );
		}
		if ( ! MWPM_DB::supports_collation( 'utf8mb4_uca1400_ai_ci' ) ) {
			$fixes[] = array( '/utf8mb4_uca1400_\w+/', $fallback );
		}
		if ( 'utf8mb4_unicode_ci' === $fallback ) {
			$fixes[] = array( '/utf8mb4_unicode_520_ci/', 'utf8mb4_unicode_ci' );
		}
		return $fixes;
	}

	/* ---------------------------------------------------------------------
	 * Search & replace of old URLs and paths
	 * ------------------------------------------------------------------- */

	private function step_db_replace() {
		if ( null === $this->state['rep'] ) {
			$pairs              = $this->replace_pairs();
			$this->state['rep'] = array(
				'pairs'  => $pairs,
				'tables' => $pairs ? MWPM_DB::tables( self::TEMP_PREFIX ) : array(),
				'index'  => 0,
				'pk'     => null,
				'cols'   => null,
				'last'   => null,
			);
		}

		$rep =& $this->state['rep'];
		$this->session();

		while ( $rep['index'] < count( $rep['tables'] ) && ! $this->timed_out() ) {
			$table = $rep['tables'][ $rep['index'] ];

			if ( null === $rep['cols'] ) {
				$pk   = MWPM_DB::primary_key( $table );
				$cols = array();
				foreach ( MWPM_DB::columns( $table ) as $col => $type ) {
					if ( ! in_array( $col, $pk, true ) && preg_match( '/^((var)?char|(tiny|medium|long)?text)\b/', $type ) ) {
						$cols[] = $col;
					}
				}
				$rep['pk']   = $pk;
				$rep['cols'] = ( $pk && $cols ) ? $cols : array();
			}

			if ( ! $rep['cols'] || $this->replace_rows( $table ) ) {
				$rep['index']++;
				$rep['pk']   = null;
				$rep['cols'] = null;
				$rep['last'] = null;
			}
		}

		if ( $rep['index'] >= count( $rep['tables'] ) ) {
			$this->state['stage'] = 'db_swap';
		}
	}

	/**
	 * @return bool True when the table is finished.
	 */
	private function replace_rows( $table ) {
		$rep     =& $this->state['rep'];
		$pairs   = $rep['pairs'];
		$pk      = $rep['pk'];
		$cols    = $rep['cols'];
		$ident   = array( 'MWPM_DB', 'ident' );
		$needles = $this->needles( array_keys( $pairs ) );

		$likes = array();
		foreach ( $cols as $col ) {
			foreach ( $needles as $needle ) {
				$likes[] = MWPM_DB::ident( $col ) . ' LIKE ' . MWPM_DB::like_contains( $needle );
			}
		}
		$select = implode( ', ', array_map( $ident, array_merge( $pk, $cols ) ) );
		$order  = implode( ', ', array_map( $ident, $pk ) );

		while ( true ) {
			$where = array( '(' . implode( ' OR ', $likes ) . ')' );
			if ( $rep['last'] ) {
				$where[] = MWPM_DB::after_key( $pk, $rep['last'] );
			}
			$rows = MWPM_DB::rows( 'SELECT ' . $select . ' FROM ' . MWPM_DB::ident( $table ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY ' . $order . ' LIMIT ' . self::BATCH );

			foreach ( $rows as $row ) {
				$set = array();
				foreach ( $cols as $col ) {
					if ( null === $row[ $col ] ) {
						continue;
					}
					$new = MWPM_Replace::run( $row[ $col ], $pairs );
					if ( $new !== $row[ $col ] ) {
						$set[] = MWPM_DB::ident( $col ) . ' = ' . MWPM_DB::quote( $new );
					}
				}

				$key = array();
				$cond = array();
				foreach ( $pk as $col ) {
					$key[]  = $row[ $col ];
					$cond[] = MWPM_DB::ident( $col ) . ' = ' . MWPM_DB::quote( $row[ $col ] );
				}
				if ( $set ) {
					MWPM_DB::query( 'UPDATE ' . MWPM_DB::ident( $table ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ) );
				}
				$rep['last'] = $key;
			}

			if ( count( $rows ) < self::BATCH ) {
				return true;
			}
			if ( $this->timed_out() ) {
				return false;
			}
		}
	}

	/**
	 * Old => new pairs for URLs (all common encodings) and server paths.
	 */
	private function replace_pairs() {
		$p     = $this->state['package'];
		$pairs = array();
		$add   = function ( $search, $replace ) use ( &$pairs ) {
			if ( '' !== $search && $search !== $replace && ! isset( $pairs[ $search ] ) ) {
				$pairs[ $search ] = $replace;
			}
		};

		$urls = array(
			array( isset( $p['home'] ) ? $p['home'] : '', get_option( 'home' ) ),
			array( isset( $p['siteurl'] ) ? $p['siteurl'] : '', get_option( 'siteurl' ) ),
			array( isset( $p['content_url'] ) ? $p['content_url'] : '', WP_CONTENT_URL ),
		);
		foreach ( $urls as $url ) {
			$old = untrailingslashit( (string) $url[0] );
			$new = untrailingslashit( (string) $url[1] );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$old_bare = preg_replace( '#^https?:#i', '', $old );
			$new_bare = preg_replace( '#^https?:#i', '', $new );

			$variants = array(
				$old              => $new,
				'https:' . $old_bare => $new,
				'http:' . $old_bare  => $new,
				$old_bare         => $new_bare,
			);
			foreach ( $variants as $search => $replace ) {
				$search = (string) $search;
				$add( $search, $replace );
				$add( str_replace( '/', '\\/', $search ), str_replace( '/', '\\/', $replace ) );
				$add( rawurlencode( $search ), rawurlencode( $replace ) );
			}
		}

		$paths = array(
			array( isset( $p['abspath'] ) ? $p['abspath'] : '', untrailingslashit( wp_normalize_path( ABSPATH ) ) ),
			array( isset( $p['content_dir'] ) ? $p['content_dir'] : '', MWPM_Storage::content_dir() ),
		);
		foreach ( $paths as $path ) {
			$old = untrailingslashit( (string) $path[0] );
			$new = untrailingslashit( (string) $path[1] );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$add( $old, $new );
			$add( str_replace( '/', '\\/', $old ), str_replace( '/', '\\/', $new ) );
			if ( preg_match( '#^[A-Za-z]:/#', $old ) ) {
				// Site came from Windows: paths may also be stored with backslashes.
				$add( str_replace( '/', '\\', $old ), $new );
			}
		}

		return apply_filters( 'mwpm_replace_pairs', $pairs, $p );
	}

	/**
	 * Smallest set of search strings for the SQL pre-filter: a string that
	 * contains another search string is already covered by it.
	 */
	private function needles( array $searches ) {
		$needles = array();
		foreach ( $searches as $search ) {
			$search = (string) $search;
			foreach ( $searches as $other ) {
				$other = (string) $other;
				if ( $other !== $search && '' !== $other && false !== strpos( $search, $other ) ) {
					continue 2;
				}
			}
			$needles[] = $search;
		}
		return $needles;
	}

	/* ---------------------------------------------------------------------
	 * Swap & finish
	 * ------------------------------------------------------------------- */

	private function step_db_swap() {
		global $wpdb;
		$this->session();

		if ( ! $this->state['swap'] ) {
			$this->prepare_swap();
			$this->state['swap'] = true;
		}

		foreach ( MWPM_DB::tables( self::TEMP_PREFIX ) as $table ) {
			$target = $wpdb->prefix . substr( $table, strlen( self::TEMP_PREFIX ) );
			MWPM_DB::query( 'DROP TABLE IF EXISTS ' . MWPM_DB::ident( $target ) );
			MWPM_DB::query( 'RENAME TABLE ' . MWPM_DB::ident( $table ) . ' TO ' . MWPM_DB::ident( $target ) );
		}

		wp_cache_flush();
		$this->state['stage'] = 'finish';
	}

	/**
	 * Adjusts the imported options before they go live: keeps this site's
	 * URLs, keeps this plugin active and renames prefix-based keys.
	 */
	private function prepare_swap() {
		global $wpdb;
		$tables  = MWPM_DB::tables( self::TEMP_PREFIX );
		$options = self::TEMP_PREFIX . 'options';
		if ( ! in_array( $options, $tables, true ) ) {
			return;
		}
		$opt = MWPM_DB::ident( $options );

		foreach ( array( 'siteurl', 'home' ) as $name ) {
			MWPM_DB::query( "UPDATE $opt SET `option_value` = " . MWPM_DB::quote( get_option( $name ) ) . ' WHERE `option_name` = ' . MWPM_DB::quote( $name ) );
		}

		$own     = plugin_basename( MWPM_FILE );
		$raw     = MWPM_DB::value( "SELECT `option_value` FROM $opt WHERE `option_name` = 'active_plugins'" );
		$plugins = is_string( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : array();
		$plugins = is_array( $plugins ) ? $plugins : array();
		$plugins = array_filter(
			$plugins,
			function ( $plugin ) use ( $own ) {
				// Drop other copies of this plugin (e.g. installed under another folder name).
				return is_string( $plugin ) && basename( $plugin ) !== basename( $own );
			}
		);
		$plugins[] = $own;
		sort( $plugins );
		$value = MWPM_DB::quote( serialize( array_values( $plugins ) ) );
		if ( null === $raw ) {
			MWPM_DB::query( "INSERT INTO $opt (`option_name`, `option_value`, `autoload`) VALUES ('active_plugins', $value, 'yes')" );
		} else {
			MWPM_DB::query( "UPDATE $opt SET `option_value` = $value WHERE `option_name` = 'active_plugins'" );
		}

		$old = isset( $this->state['package']['prefix'] ) ? (string) $this->state['package']['prefix'] : '';
		$new = $wpdb->prefix;
		if ( '' !== $old && $old !== $new ) {
			MWPM_DB::query( "UPDATE $opt SET `option_name` = " . MWPM_DB::quote( $new . 'user_roles' ) . ' WHERE `option_name` = ' . MWPM_DB::quote( $old . 'user_roles' ) );

			$usermeta = self::TEMP_PREFIX . 'usermeta';
			if ( in_array( $usermeta, $tables, true ) ) {
				MWPM_DB::query(
					'UPDATE ' . MWPM_DB::ident( $usermeta )
					. ' SET `meta_key` = CONCAT(' . MWPM_DB::quote( $new ) . ', SUBSTRING(`meta_key`, ' . ( strlen( $old ) + 1 ) . '))'
					. ' WHERE `meta_key` LIKE ' . MWPM_DB::quote( addcslashes( $old, '_%\\' ) . '%' )
				);
			}
		}
	}

	private function step_finish() {
		if ( $this->state['swap'] ) {
			delete_option( 'rewrite_rules' );
			wp_cache_flush();
		}

		$response = array(
			'done'      => true,
			'progress'  => 100,
			'message'   => 'Import complete!',
			'skipped'   => $this->state['ex']['skipped'],
			'relogin'   => (bool) $this->state['swap'],
			'login_url' => wp_login_url( admin_url( 'admin.php?page=mwpm' ) ),
		);

		// Uploaded archives live in the job folder and go away with it.
		MWPM_Storage::delete_job( $this->state['id'] );
		$this->state['stage'] = 'done';

		return $response;
	}
}

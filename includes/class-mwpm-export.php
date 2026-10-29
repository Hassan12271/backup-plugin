<?php
/**
 * Export: dumps the database, scans wp-content and writes the .mwpm archive.
 *
 * Work is split into short steps; every step continues where the previous one
 * stopped, so the size of the site does not matter.
 */

defined( 'ABSPATH' ) || exit;

class MWPM_Export {

	/** Stands in for the table prefix inside the SQL dump. */
	const PLACEHOLDER = 'MWPMPFX_';

	/** Rows fetched per query. */
	const BATCH = 250;

	/** Approximate maximum size of one INSERT statement. */
	const STATEMENT_SIZE = 500000;

	const OPTIONS = array( 'no_database', 'no_media', 'no_themes', 'no_plugins', 'no_revisions', 'no_spam' );

	private $state;
	private $dir;
	private $deadline;
	private $excludes;

	public static function start( array $input ) {
		global $wpdb;

		$options = array();
		foreach ( self::OPTIONS as $key ) {
			$options[ $key ] = ! empty( $input[ $key ] );
		}

		return MWPM_Storage::create_job(
			'export',
			array(
				'options' => $options,
				'file'    => self::file_name(),
				'stage'   => $options['no_database'] ? 'scan' : 'db',
				'db'      => array(
					'tables'  => $options['no_database'] ? array() : MWPM_DB::tables( $wpdb->prefix ),
					'index'   => 0,
					'pos'     => 0,
					'pk'      => null,
					'last'    => null,
					'offset'  => 0,
					'created' => false,
					'rows'    => 0,
				),
				'scan'    => array(
					'queue' => array( base64_encode( '' ) ),
					'pos'   => 0,
					'files' => 0,
					'bytes' => 0,
				),
				'archive' => array(
					'pos'        => 0,
					'phase'      => 'meta',
					'list_pos'   => 0,
					'entry_open' => false,
					'entry_size' => 0,
					'entry_done' => 0,
					'bytes'      => 0,
					'files'      => 0,
				),
			)
		);
	}

	private static function file_name() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		$slug = trim( preg_replace( '/[^A-Za-z0-9.-]+/', '-', $host . ( $path ? '-' . $path : '' ) ), '-.' );
		if ( '' === $slug ) {
			$slug = 'site';
		}
		return $slug . '-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false ) ) . '.' . MWPM_Storage::EXT;
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
	 * Runs one step. The state is only saved when the step succeeds; on failure
	 * the next attempt repeats the step from the last saved point.
	 */
	public function step() {
		while ( ! $this->timed_out() ) {
			$stage = $this->state['stage'];
			if ( 'db' === $stage ) {
				$this->step_db();
			} elseif ( 'scan' === $stage ) {
				$this->step_scan();
			} elseif ( 'archive' === $stage ) {
				$this->step_archive();
			}
			if ( 'done' === $this->state['stage'] ) {
				return $this->finish();
			}
		}
		MWPM_Storage::save_job( $this->state );
		return $this->response();
	}

	private function response() {
		$db      = $this->state['db'];
		$scan    = $this->state['scan'];
		$archive = $this->state['archive'];

		switch ( $this->state['stage'] ) {
			case 'db':
				$count    = max( 1, count( $db['tables'] ) );
				$progress = 20 * $db['index'] / $count;
				$table    = isset( $db['tables'][ $db['index'] ] ) ? $db['tables'][ $db['index'] ] : '';
				$message  = sprintf( 'Exporting database: table %1$d of %2$d (%3$s), %4$s rows done', min( $db['index'] + 1, $count ), $count, $table, number_format_i18n( $db['rows'] ) );
				break;
			case 'scan':
				$progress = 20;
				$message  = sprintf( 'Scanning files: %1$s files found (%2$s)', number_format_i18n( $scan['files'] ), MWPM_Storage::format_size( $scan['bytes'] ) );
				break;
			default:
				$total    = max( 1, $scan['bytes'] + MWPM_Storage::filesize( $this->dir . '/database.sql' ) );
				$progress = 25 + 75 * min( 1, $archive['bytes'] / $total );
				$message  = sprintf( 'Creating archive: %1$s of %2$s files (%3$s of %4$s)', number_format_i18n( $archive['files'] ), number_format_i18n( $scan['files'] ), MWPM_Storage::format_size( $archive['bytes'] ), MWPM_Storage::format_size( $total ) );
		}

		return array(
			'done'     => false,
			'stage'    => $this->state['stage'],
			'progress' => round( $progress, 1 ),
			'message'  => $message,
		);
	}

	private function finish() {
		$part  = MWPM_Storage::dir() . '/' . $this->state['file'] . '.part';
		$final = MWPM_Storage::dir() . '/' . $this->state['file'];
		if ( ! @rename( $part, $final ) ) {
			throw new Exception( 'Could not finalize the backup file.' );
		}
		MWPM_Storage::delete_job( $this->state['id'] );

		$size = MWPM_Storage::filesize( $final );
		return array(
			'done'         => true,
			'progress'     => 100,
			'message'      => sprintf( 'Export complete: %1$s (%2$s)', $this->state['file'], MWPM_Storage::format_size( $size ) ),
			'file'         => $this->state['file'],
			'size'         => MWPM_Storage::format_size( $size ),
			'download_url' => MWPM_Admin::download_url( $this->state['file'] ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------- */

	private function step_db() {
		global $wpdb;
		$db =& $this->state['db'];

		$fh = MWPM_Archive::open_at( $this->dir . '/database.sql', $db['pos'] );
		if ( ! $fh ) {
			throw new Exception( 'Could not write the database dump file.' );
		}
		if ( 0 === $db['pos'] ) {
			MWPM_Archive::write( $fh, '-- SiteShift Migration ' . MWPM_VERSION . " database dump\n" );
		}

		while ( $db['index'] < count( $db['tables'] ) ) {
			$table = $db['tables'][ $db['index'] ];
			$name  = self::PLACEHOLDER . substr( $table, strlen( $wpdb->prefix ) );

			if ( ! $db['created'] ) {
				$row    = MWPM_DB::rows( 'SHOW CREATE TABLE ' . MWPM_DB::ident( $table ), true );
				$create = isset( $row[0][1] ) ? $row[0][1] : '';
				if ( '' === $create ) {
					throw new Exception( 'Could not read the structure of table ' . $table );
				}
				$create = preg_replace( '/^CREATE TABLE `' . preg_quote( $table, '/' ) . '`/', 'CREATE TABLE ' . MWPM_DB::ident( $name ), $create );
				$create = str_replace( 'REFERENCES `' . $wpdb->prefix, 'REFERENCES `' . self::PLACEHOLDER, $create );
				$create = str_replace( array( "\r\n", "\n", "\r" ), ' ', $create );

				MWPM_Archive::write( $fh, 'DROP TABLE IF EXISTS ' . MWPM_DB::ident( $name ) . ";\n" . $create . ";\n" );
				$db['created'] = true;
				$db['pk']      = MWPM_DB::primary_key( $table );
			}

			$finished = $this->export_rows( $fh, $table, $name );
			if ( $finished ) {
				$db['index']++;
				$db['created'] = false;
				$db['pk']      = null;
				$db['last']    = null;
				$db['offset']  = 0;
			}
			if ( $this->timed_out() ) {
				break;
			}
		}

		$db['pos'] = ftell( $fh );
		fclose( $fh );

		if ( $db['index'] >= count( $db['tables'] ) ) {
			$this->state['stage'] = 'scan';
		}
	}

	/**
	 * Writes rows of one table until it is finished (true) or time runs out (false).
	 */
	private function export_rows( $fh, $table, $name ) {
		$db     =& $this->state['db'];
		$pk     = $db['pk'];
		$filter = $this->row_filter( $table );
		$batch  = (int) apply_filters( 'mwpm_export_batch', self::BATCH, $table );
		$prefix = 'REPLACE INTO ' . MWPM_DB::ident( $name ) . ' VALUES ';

		while ( true ) {
			$where = $filter ? array( $filter ) : array();
			if ( $pk && $db['last'] ) {
				$where[] = MWPM_DB::after_key( $pk, $db['last'] );
			}
			$sql = 'SELECT * FROM ' . MWPM_DB::ident( $table ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
			if ( $pk ) {
				$sql .= ' ORDER BY ' . implode( ', ', array_map( array( 'MWPM_DB', 'ident' ), $pk ) ) . ' LIMIT ' . $batch;
			} else {
				$sql .= ' LIMIT ' . (int) $db['offset'] . ', ' . $batch;
			}

			$rows = MWPM_DB::rows( $sql );
			if ( ! $rows ) {
				return true;
			}

			$values = array();
			$length = 0;
			foreach ( $rows as $row ) {
				$value    = '(' . implode( ',', array_map( array( 'MWPM_DB', 'quote' ), array_values( $row ) ) ) . ')';
				$values[] = $value;
				$length  += strlen( $value );
				if ( $length >= self::STATEMENT_SIZE ) {
					MWPM_Archive::write( $fh, $prefix . implode( ',', $values ) . ";\n" );
					$values = array();
					$length = 0;
				}
			}
			if ( $values ) {
				MWPM_Archive::write( $fh, $prefix . implode( ',', $values ) . ";\n" );
			}

			$last = end( $rows );
			if ( $pk ) {
				$db['last'] = array();
				foreach ( $pk as $col ) {
					$db['last'][] = $last[ $col ];
				}
			} else {
				$db['offset'] += count( $rows );
			}
			$db['rows'] += count( $rows );

			if ( count( $rows ) < $batch ) {
				return true;
			}
			if ( $this->timed_out() ) {
				return false;
			}
		}
	}

	private function row_filter( $table ) {
		global $wpdb;
		$options = $this->state['options'];

		if ( $wpdb->prefix . 'options' === $table ) {
			return "`option_name` NOT LIKE '\\_transient\\_%' AND `option_name` NOT LIKE '\\_site\\_transient\\_%'";
		}
		if ( $options['no_revisions'] && $wpdb->prefix . 'posts' === $table ) {
			return "`post_type` <> 'revision'";
		}
		if ( $options['no_spam'] && $wpdb->prefix . 'comments' === $table ) {
			return "`comment_approved` <> 'spam'";
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * File scan
	 * ------------------------------------------------------------------- */

	private function excluded( $rel ) {
		if ( null === $this->excludes ) {
			$options = $this->state['options'];
			$list    = array(
				'mwpm-backups',
				'cache',
				'upgrade',
				'upgrade-temp-backup',
				'ai1wm-backups',
				'updraft',
				'backups-dup-lite',
				'backups-dup-pro',
				'wpvividbackups',
				'wflogs',
				'et-cache',
				'object-cache.php',
				'advanced-cache.php',
				'db.php',
				'debug.log',
			);
			if ( $options['no_media'] ) {
				$list[] = 'uploads';
			}
			if ( $options['no_themes'] ) {
				$list[] = 'themes';
			}
			if ( $options['no_plugins'] ) {
				$list[] = 'plugins';
			}
			$list           = apply_filters( 'mwpm_export_exclude', $list, $options );
			$this->excludes = array_fill_keys( array_map( 'strtolower', $list ), true );
		}
		return isset( $this->excludes[ strtolower( $rel ) ] );
	}

	private function step_scan() {
		$scan =& $this->state['scan'];
		$root = MWPM_Storage::content_dir();

		$list = MWPM_Archive::open_at( $this->dir . '/files.txt', $scan['pos'] );
		if ( ! $list ) {
			throw new Exception( 'Could not write the file list.' );
		}

		while ( ! empty( $scan['queue'] ) ) {
			$rel   = base64_decode( array_pop( $scan['queue'] ) );
			$items = @scandir( '' === $rel ? $root : $root . '/' . $rel );

			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( '.' === $item || '..' === $item || false !== strpbrk( $item, "\r\n" ) ) {
						continue;
					}
					$child = '' === $rel ? $item : $rel . '/' . $item;
					if ( $this->excluded( $child ) ) {
						continue;
					}
					$path = $root . '/' . $child;
					if ( is_dir( $path ) ) {
						if ( ! is_link( $path ) ) {
							$scan['queue'][] = base64_encode( $child );
						}
					} elseif ( is_file( $path ) && is_readable( $path ) ) {
						MWPM_Archive::write( $list, $child . "\n" );
						$scan['files']++;
						$scan['bytes'] += (int) @filesize( $path );
					}
				}
			}

			if ( $this->timed_out() ) {
				break;
			}
		}

		$scan['pos'] = ftell( $list );
		fclose( $list );

		if ( empty( $scan['queue'] ) ) {
			$this->state['stage'] = 'archive';
		}
	}

	/* ---------------------------------------------------------------------
	 * Archive
	 * ------------------------------------------------------------------- */

	private function step_archive() {
		$archive =& $this->state['archive'];

		$out = MWPM_Archive::open_at( MWPM_Storage::dir() . '/' . $this->state['file'] . '.part', $archive['pos'] );
		if ( ! $out ) {
			throw new Exception( 'Could not create the backup file in ' . MWPM_Storage::dir() );
		}

		if ( 'meta' === $archive['phase'] ) {
			$json = wp_json_encode( $this->package() );
			MWPM_Archive::write( $out, MWPM_Archive::MAGIC . MWPM_Archive::header( '__mwpm/package.json', strlen( $json ), time() ) . $json );
			$archive['phase'] = $this->state['options']['no_database'] ? 'files' : 'database';
		}

		if ( 'database' === $archive['phase'] && $this->add_file( $out, $this->dir . '/database.sql', '__mwpm/database.sql' ) ) {
			$archive['phase'] = 'files';
		}

		if ( 'files' === $archive['phase'] ) {
			$root = MWPM_Storage::content_dir();
			$list = fopen( $this->dir . '/files.txt', 'rb' );
			if ( ! $list ) {
				throw new Exception( 'Could not read the file list.' );
			}
			fseek( $list, $archive['list_pos'] );
			while ( ! $this->timed_out() ) {
				$line = fgets( $list );
				if ( false === $line ) {
					$archive['phase'] = 'end';
					break;
				}
				$rel = rtrim( $line, "\n" );
				if ( '' !== $rel && ! $this->add_file( $out, $root . '/' . $rel, $rel ) ) {
					break;
				}
				$archive['list_pos'] = ftell( $list );
				$archive['files']++;
			}
			fclose( $list );
		}

		if ( 'end' === $archive['phase'] ) {
			MWPM_Archive::write( $out, MWPM_Archive::end_marker() );
			$this->state['stage'] = 'done';
		}

		fflush( $out );
		$archive['pos'] = ftell( $out );
		fclose( $out );
	}

	/**
	 * Appends (part of) a file to the archive.
	 *
	 * @return bool True once the whole file is in the archive.
	 */
	private function add_file( $out, $path, $name ) {
		$archive =& $this->state['archive'];

		$in = @fopen( $path, 'rb' );
		if ( ! $archive['entry_open'] ) {
			if ( ! $in ) {
				return true; // Unreadable or deleted since the scan: skip it.
			}
			$size = MWPM_Storage::filesize( $path );
			MWPM_Archive::write( $out, MWPM_Archive::header( $name, $size, (int) @filemtime( $path ) ) );
			$archive['entry_open'] = true;
			$archive['entry_size'] = $size;
			$archive['entry_done'] = 0;
		}

		$remaining = $archive['entry_size'] - $archive['entry_done'];
		if ( $in ) {
			fseek( $in, $archive['entry_done'] );
			$copied = MWPM_Archive::copy( $in, $out, $remaining, $this->deadline );
			$eof    = feof( $in );
			fclose( $in );
		} else {
			$copied = 0;
			$eof    = true;
		}
		$archive['entry_done'] += $copied;
		$archive['bytes']      += $copied;

		if ( $archive['entry_done'] < $archive['entry_size'] && $eof ) {
			// The file shrank or vanished while being archived: keep the archive consistent.
			MWPM_Archive::pad( $out, $archive['entry_size'] - $archive['entry_done'] );
			$archive['entry_done'] = $archive['entry_size'];
		}

		if ( $archive['entry_done'] >= $archive['entry_size'] ) {
			$archive['entry_open'] = false;
			return true;
		}
		return false;
	}

	private function package() {
		global $wpdb;
		return array(
			'format'         => 1,
			'plugin_version' => MWPM_VERSION,
			'created'        => time(),
			'siteurl'        => get_option( 'siteurl' ),
			'home'           => get_option( 'home' ),
			'content_url'    => untrailingslashit( WP_CONTENT_URL ),
			'abspath'        => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'content_dir'    => MWPM_Storage::content_dir(),
			'prefix'         => $wpdb->prefix,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'mysql_version'  => $wpdb->db_version(),
			'multisite'      => is_multisite(),
			'database'       => ! $this->state['options']['no_database'],
			'options'        => $this->state['options'],
			'tables'         => count( $this->state['db']['tables'] ),
			'files'          => $this->state['scan']['files'],
			'bytes'          => $this->state['scan']['bytes'],
			'template'       => get_option( 'template' ),
			'stylesheet'     => get_option( 'stylesheet' ),
		);
	}
}

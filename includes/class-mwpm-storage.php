<?php
/**
 * Storage paths, job state files and request helpers.
 *
 * Job state lives in JSON files (not in the database) because an import
 * replaces the whole database while the job is still running.
 */

defined( 'ABSPATH' ) || exit;

class MWPM_Storage {

	const EXT = 'mwpm';

	public static function content_dir() {
		return untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
	}

	public static function dir() {
		return self::content_dir() . '/mwpm-backups';
	}

	public static function jobs_dir() {
		return self::dir() . '/jobs';
	}

	public static function job_dir( $id ) {
		return self::jobs_dir() . '/' . $id;
	}

	/**
	 * Name of this plugin's folder inside wp-content/plugins.
	 */
	public static function plugin_folder() {
		return dirname( plugin_basename( MWPM_FILE ) );
	}

	/**
	 * Creates the protected storage folders.
	 */
	public static function ensure() {
		foreach ( array( self::dir(), self::jobs_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				throw new Exception( sprintf( 'Could not create folder %s. Please check permissions.', $dir ) );
			}
			self::protect( $dir );
		}
	}

	private static function protect( $dir ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content );
			}
		}
	}

	public static function create_job( $type, array $data ) {
		self::ensure();
		$id = gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 8, false ) );
		if ( ! wp_mkdir_p( self::job_dir( $id ) ) ) {
			throw new Exception( 'Could not create the job folder. Please check permissions of wp-content.' );
		}
		$state = array_merge(
			array(
				'id'      => $id,
				'type'    => $type,
				'token'   => wp_generate_password( 40, false ),
				'created' => time(),
			),
			$data
		);
		self::save_job( $state );
		return $state;
	}

	/**
	 * Loads a job and checks its secret token. Returns null when invalid.
	 */
	public static function load_job( $id, $token ) {
		if ( ! is_string( $id ) || ! preg_match( '/^\d{14}-[a-z0-9]{8}$/', $id ) || ! is_string( $token ) ) {
			return null;
		}
		$file = self::job_dir( $id ) . '/state.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$state = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $state ) || empty( $state['token'] ) || ! hash_equals( $state['token'], $token ) ) {
			return null;
		}
		return $state;
	}

	public static function save_job( array $state ) {
		$file = self::job_dir( $state['id'] ) . '/state.json';
		$json = wp_json_encode( $state );
		if ( false === $json ) {
			throw new Exception( 'Could not encode the job state.' );
		}
		$tmp = $file . '.tmp';
		if ( false === file_put_contents( $tmp, $json ) || ! @rename( $tmp, $file ) ) {
			if ( false === file_put_contents( $file, $json ) ) {
				throw new Exception( 'Could not save the job state. Is the disk full?' );
			}
		}
	}

	public static function delete_job( $id ) {
		self::rrmdir( self::job_dir( $id ) );
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . '/' . $item;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					self::rrmdir( $path );
				} else {
					@unlink( $path );
				}
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Removes abandoned jobs and half-written backups older than a day.
	 */
	public static function cleanup() {
		$limit = time() - DAY_IN_SECONDS;
		foreach ( (array) glob( self::jobs_dir() . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( $dir && @filemtime( $dir . '/state.json' ) < $limit ) {
				self::rrmdir( $dir );
			}
		}
		foreach ( (array) glob( self::dir() . '/*.part' ) as $file ) {
			if ( $file && @filemtime( $file ) < $limit ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Lists finished backups, newest first.
	 */
	public static function backups() {
		$list = array();
		foreach ( (array) glob( self::dir() . '/*.' . self::EXT ) as $file ) {
			if ( ! $file || ! is_file( $file ) ) {
				continue;
			}
			$list[] = array(
				'name' => basename( $file ),
				'size' => self::filesize( $file ),
				'time' => filemtime( $file ),
			);
		}
		usort(
			$list,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		return $list;
	}

	/**
	 * Absolute path of a backup by file name, or null if it does not exist.
	 */
	public static function backup_path( $name ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9._-]+\.' . self::EXT . '$/', $name ) ) {
			return null;
		}
		$path = self::dir() . '/' . $name;
		return is_file( $path ) ? $path : null;
	}

	public static function filesize( $path ) {
		clearstatcache( true, $path );
		$size = @filesize( $path );
		return false === $size ? 0 : $size;
	}

	/**
	 * Prepares a long-running request and returns the time (seconds) a step may use.
	 */
	public static function prepare_request() {
		$budget = 10;
		$limit  = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$budget = min( $budget, max( 2, $limit - 5 ) );
		}
		@ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		wp_raise_memory_limit( 'admin' );
		return (int) apply_filters( 'mwpm_time_budget', $budget );
	}

	public static function format_size( $bytes ) {
		return size_format( $bytes, $bytes >= 1048576 ? 2 : 0 );
	}
}

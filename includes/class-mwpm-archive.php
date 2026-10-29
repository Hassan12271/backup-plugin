<?php
/**
 * The .mwpm archive format.
 *
 *   "MWPMARC1"                                   8 byte magic
 *   entry*                                       one per file
 *   end marker                                   16 zero bytes
 *
 * entry = header (name length, size high 32 bits, size low 32 bits, mtime;
 * each an unsigned 32-bit big-endian int) + name + raw file contents.
 *
 * It needs no PHP extensions and can be written and read in small steps,
 * so archives of any size can be handled within short requests.
 */

defined( 'ABSPATH' ) || exit;

class MWPM_Archive {

	const MAGIC       = 'MWPMARC1';
	const HEADER_SIZE = 16;
	const CHUNK       = 1048576;

	public static function header( $name, $size, $mtime ) {
		$high = (int) floor( $size / 4294967296 );
		$low  = (int) ( $size - $high * 4294967296 );
		return pack( 'NNNN', strlen( $name ), $high, $low, (int) $mtime ) . $name;
	}

	public static function end_marker() {
		return pack( 'NNNN', 0, 0, 0, 0 );
	}

	public static function check_magic( $fh ) {
		if ( self::MAGIC !== fread( $fh, strlen( self::MAGIC ) ) ) {
			throw new Exception( 'This is not a valid SiteShift Migration (.mwpm) backup file.' );
		}
	}

	/**
	 * Reads the next entry header.
	 *
	 * @return array|false Entry (name, size, mtime) or false at the end of the archive.
	 */
	public static function read_header( $fh ) {
		$raw = fread( $fh, self::HEADER_SIZE );
		if ( ! is_string( $raw ) || self::HEADER_SIZE !== strlen( $raw ) ) {
			throw new Exception( 'The backup file is incomplete or corrupt (unexpected end of file).' );
		}
		$h = unpack( 'Nlen/Nhigh/Nlow/Nmtime', $raw );
		if ( 0 === $h['len'] ) {
			return false;
		}
		if ( $h['len'] > 4096 ) {
			throw new Exception( 'The backup file is corrupt (invalid entry header).' );
		}
		$name = fread( $fh, $h['len'] );
		if ( ! is_string( $name ) || strlen( $name ) !== $h['len'] ) {
			throw new Exception( 'The backup file is incomplete or corrupt (unexpected end of file).' );
		}
		return array(
			'name'  => $name,
			'size'  => $h['high'] * 4294967296 + $h['low'],
			'mtime' => $h['mtime'],
		);
	}

	/**
	 * Copies up to $length bytes from $in to $out, stopping early once $deadline passes.
	 *
	 * @return int Bytes copied.
	 */
	public static function copy( $in, $out, $length, $deadline ) {
		$copied = 0;
		while ( $copied < $length ) {
			$data = fread( $in, (int) min( self::CHUNK, $length - $copied ) );
			if ( false === $data || '' === $data ) {
				break;
			}
			self::write( $out, $data );
			$copied += strlen( $data );
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		return $copied;
	}

	/**
	 * Writes $length zero bytes (used when a file shrank while it was being archived).
	 */
	public static function pad( $out, $length ) {
		while ( $length > 0 ) {
			$n = (int) min( self::CHUNK, $length );
			self::write( $out, str_repeat( "\0", $n ) );
			$length -= $n;
		}
	}

	public static function write( $out, $data ) {
		if ( fwrite( $out, $data ) !== strlen( $data ) ) {
			throw new Exception( 'Could not write data to disk. Is the disk full or are permissions wrong?' );
		}
	}

	/**
	 * Opens a file for writing and cuts it back to $size bytes, so a step
	 * that was interrupted half-way can safely be repeated.
	 */
	public static function open_at( $path, $size ) {
		$fh = @fopen( $path, 'c+b' );
		if ( ! $fh ) {
			return false;
		}
		ftruncate( $fh, $size );
		fseek( $fh, $size );
		return $fh;
	}
}

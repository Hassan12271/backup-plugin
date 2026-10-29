<?php
/**
 * Thin database layer.
 *
 * Queries go straight to the mysqli connection so that wpdb does not touch
 * the data (placeholder escaping, invalid text stripping, result caching).
 */

defined( 'ABSPATH' ) || exit;

class MWPM_DB {

	private static function link() {
		global $wpdb;
		$dbh = $wpdb->dbh;
		return ( $dbh instanceof mysqli ) ? $dbh : null;
	}

	/**
	 * Runs a query and throws on error.
	 *
	 * @return mixed mysqli_result for reads, true for writes.
	 */
	public static function query( $sql ) {
		global $wpdb;
		$link = self::link();
		if ( $link ) {
			try {
				$result = mysqli_query( $link, $sql );
			} catch ( Exception $e ) {
				throw new Exception( 'Database error: ' . $e->getMessage() );
			}
			if ( false === $result ) {
				throw new Exception( 'Database error: ' . mysqli_error( $link ) );
			}
			return $result;
		}
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			throw new Exception( 'Database error: ' . $wpdb->last_error );
		}
		return true;
	}

	/**
	 * @return array[] Rows as associative arrays (or numeric arrays when $numeric).
	 */
	public static function rows( $sql, $numeric = false ) {
		global $wpdb;
		$link = self::link();
		if ( $link ) {
			$result = self::query( $sql );
			$rows   = array();
			if ( $result instanceof mysqli_result ) {
				while ( $row = ( $numeric ? mysqli_fetch_row( $result ) : mysqli_fetch_assoc( $result ) ) ) {
					$rows[] = $row;
				}
				mysqli_free_result( $result );
			}
			return $rows;
		}
		$rows = $wpdb->get_results( $sql, $numeric ? ARRAY_N : ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new Exception( 'Database error: ' . $wpdb->last_error );
		}
		return $rows ? $rows : array();
	}

	public static function value( $sql ) {
		$rows = self::rows( $sql, true );
		return isset( $rows[0][0] ) ? $rows[0][0] : null;
	}

	public static function escape( $value ) {
		return strtr(
			(string) $value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	public static function quote( $value ) {
		return null === $value ? 'NULL' : "'" . self::escape( $value ) . "'";
	}

	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Quoted "%needle%" pattern for LIKE.
	 */
	public static function like_contains( $needle ) {
		return self::quote( '%' . addcslashes( $needle, '_%\\' ) . '%' );
	}

	/**
	 * Base tables whose name starts with $prefix.
	 */
	public static function tables( $prefix ) {
		$tables = array();
		foreach ( self::rows( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", true ) as $row ) {
			if ( '' === $prefix || 0 === strpos( $row[0], $prefix ) ) {
				$tables[] = $row[0];
			}
		}
		return $tables;
	}

	public static function primary_key( $table ) {
		$keys = array();
		foreach ( self::rows( 'SHOW KEYS FROM ' . self::ident( $table ) . " WHERE Key_name = 'PRIMARY'" ) as $row ) {
			$keys[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}
		ksort( $keys );
		return array_values( $keys );
	}

	/**
	 * @return array Column name => type.
	 */
	public static function columns( $table ) {
		$cols = array();
		foreach ( self::rows( 'SHOW COLUMNS FROM ' . self::ident( $table ) ) as $row ) {
			$cols[ $row['Field'] ] = strtolower( $row['Type'] );
		}
		return $cols;
	}

	/**
	 * WHERE fragment that continues after $last (keyset pagination).
	 */
	public static function after_key( array $pk, $last ) {
		if ( empty( $last ) ) {
			return '';
		}
		$cols = array_map( array( __CLASS__, 'ident' ), $pk );
		$vals = array_map( array( __CLASS__, 'quote' ), $last );
		if ( 1 === count( $cols ) ) {
			return $cols[0] . ' > ' . $vals[0];
		}
		return '(' . implode( ', ', $cols ) . ') > (' . implode( ', ', $vals ) . ')';
	}

	public static function supports_collation( $name ) {
		return null !== self::value( 'SHOW COLLATION LIKE ' . self::quote( $name ) );
	}
}

<?php
/**
 * Serialization-safe search & replace.
 *
 * Serialized PHP values are rewritten token by token (never unserialized),
 * so string lengths stay correct and no plugin classes are instantiated.
 */

defined( 'ABSPATH' ) || exit;

class MWPM_Replace {

	/**
	 * @param string $value
	 * @param array  $pairs search => replace.
	 */
	public static function run( $value, array $pairs ) {
		if ( ! is_string( $value ) || '' === $value || ! self::contains( $value, $pairs ) ) {
			return $value;
		}
		if ( is_serialized( $value, false ) ) {
			$pos = 0;
			try {
				$result = self::token( $value, $pos, $pairs );
				if ( strlen( $value ) === $pos ) {
					return $result;
				}
			} catch ( Exception $e ) {
				// Not really serialized: fall through to a plain replace.
			}
		}
		return strtr( $value, $pairs );
	}

	private static function contains( $value, array $pairs ) {
		foreach ( $pairs as $search => $replace ) {
			if ( false !== strpos( $value, (string) $search ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rewrites one serialized token starting at $pos and advances $pos past it.
	 */
	private static function token( $s, &$pos, array $pairs, $is_key = false ) {
		if ( ! isset( $s[ $pos ] ) ) {
			throw new Exception( 'eof' );
		}
		$type = $s[ $pos ];
		switch ( $type ) {
			case 'N':
				self::expect( $s, $pos, 'N;' );
				$pos += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( false === $end || ':' !== $s[ $pos + 1 ] ) {
					throw new Exception( 'bad scalar' );
				}
				$token = substr( $s, $pos, $end - $pos + 1 );
				$pos   = $end + 1;
				return $token;

			case 's':
				$str = self::string_body( $s, $pos );
				self::expect( $s, $pos, ';' );
				$pos++;
				if ( ! $is_key ) {
					$str = self::run( $str, $pairs );
				}
				return 's:' . strlen( $str ) . ':"' . $str . '";';

			case 'a':
				$count = self::int_after( $s, $pos );
				self::expect( $s, $pos, '{' );
				$pos++;
				$out = 'a:' . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$out .= self::token( $s, $pos, $pairs, true );
					$out .= self::token( $s, $pos, $pairs );
				}
				self::expect( $s, $pos, '}' );
				$pos++;
				return $out . '}';

			case 'O':
				$class = self::string_body( $s, $pos );
				self::expect( $s, $pos, ':' );
				$pos++;
				$count = self::int_after( $s, $pos, false );
				self::expect( $s, $pos, '{' );
				$pos++;
				$out = 'O:' . strlen( $class ) . ':"' . $class . '":' . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$out .= self::token( $s, $pos, $pairs, true );
					$out .= self::token( $s, $pos, $pairs );
				}
				self::expect( $s, $pos, '}' );
				$pos++;
				return $out . '}';

			case 'C':
				// Custom serialized object: its payload format is unknown, copy it as is.
				$start = $pos;
				self::string_body( $s, $pos );
				self::expect( $s, $pos, ':' );
				$pos++;
				$len = self::int_after( $s, $pos, false );
				self::expect( $s, $pos, '{' );
				$pos += 1 + $len;
				self::expect( $s, $pos, '}' );
				$pos++;
				return substr( $s, $start, $pos - $start );

			case 'E':
				$start = $pos;
				self::string_body( $s, $pos );
				self::expect( $s, $pos, ';' );
				$pos++;
				return substr( $s, $start, $pos - $start );
		}
		throw new Exception( 'unknown token' );
	}

	/**
	 * Parses `X:<len>:"<bytes>"` at $pos, leaving $pos after the closing quote.
	 */
	private static function string_body( $s, &$pos ) {
		$len = self::int_after( $s, $pos );
		self::expect( $s, $pos, '"' );
		$str = substr( $s, $pos + 1, $len );
		if ( strlen( $str ) !== $len ) {
			throw new Exception( 'short string' );
		}
		$pos += 1 + $len;
		self::expect( $s, $pos, '"' );
		$pos++;
		return $str;
	}

	/**
	 * Parses `X:<int>:` (or `<int>:` when $skip_type is false) at $pos.
	 */
	private static function int_after( $s, &$pos, $skip_type = true ) {
		if ( $skip_type ) {
			self::expect( $s, $pos + 1, ':' );
			$pos += 2;
		}
		$end = strpos( $s, ':', $pos );
		if ( false === $end ) {
			throw new Exception( 'bad length' );
		}
		$num = substr( $s, $pos, $end - $pos );
		if ( ! ctype_digit( $num ) ) {
			throw new Exception( 'bad length' );
		}
		$pos = $end + 1;
		return (int) $num;
	}

	private static function expect( $s, $pos, $chars ) {
		if ( substr( $s, $pos, strlen( $chars ) ) !== $chars ) {
			throw new Exception( 'unexpected' );
		}
	}
}

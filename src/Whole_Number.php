<?php
/**
 * Reads a whole number out of a header or JSON value.
 *
 * @package AdaRemediationClient
 */

namespace AdaRemediationClient;

/**
 * One definition of "a whole number" for everything this plugin reads from outside: response
 * headers (always strings), the pipeline's JSON body (ints) and wp-config.php constants (either).
 */
class Whole_Number {

	/**
	 * A non-negative whole number from an int or a string of digits.
	 *
	 * @param mixed $value The raw value.
	 * @return int|null Null for anything else: a date, a fraction, a sign, a negative, a boolean, an
	 *                  empty header, or digits too many to fit in an int.
	 */
	public static function from( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		if ( ! is_string( $value ) || ! ctype_digit( $value ) ) {
			return null;
		}

		// filter_var() refuses leading zeros, which are still a whole number.
		$digits = ltrim( $value, '0' );

		if ( '' === $digits ) {
			return 0;
		}

		$number = filter_var( $digits, FILTER_VALIDATE_INT );

		return false === $number ? null : $number;
	}
}

<?php
declare(strict_types=1);

namespace ABSmartly\SDK;

use React\Promise\PromiseInterface;

use function array_values;
use function base64_encode;
use function hash;
use function is_float;
use function is_int;
use function is_object;
use function abs;
use function explode;
use function floor;
use function ini_set;
use function is_infinite;
use function is_nan;
use function ltrim;
use function sprintf;
use function strtolower;
use function substr;
use function var_export;
use function strtr;
use function unpack;

/**
 * Helpers shared by the SDK, mirroring the JavaScript SDK's utils module.
 */
final class Utils {

	public static function base64UrlNoPadding(string $bytes): string {
		return strtr(base64_encode($bytes), [
			'+' => '-',
			'/' => '_',
			'=' => '',
		]);
	}

	/**
	 * Returns the UTF-8 bytes of a string. PHP strings are byte strings, so a
	 * UTF-8 encoded string maps directly to its bytes.
	 *
	 * @return int[]
	 */
	public static function utf8Bytes(string $value): array {
		if ($value === '') {
			return [];
		}

		return array_values(unpack('C*', $value));
	}

	/**
	 * Units may be strings or numbers. A number is hashed as its value rounded
	 * to an integer, like the JavaScript SDK's `value.toFixed(0)`.
	 *
	 * @param string|int|float $unit
	 */
	public static function unitToString($unit): string {
		if (is_float($unit)) {
			return self::toFixed0($unit);
		}

		return (string) $unit;
	}

	/**
	 * JavaScript `Number.prototype.toFixed(0)`: halves round away from zero,
	 * negative values keep their sign ("-0"), and magnitudes of 1e21 or more
	 * use JavaScript's exponent notation ("1e+21").
	 */
	private static function toFixed0(float $value): string {
		if (is_nan($value)) {
			return 'NaN';
		}

		$sign = $value < 0 ? '-' : '';
		$abs = abs($value);
		if (is_infinite($abs)) {
			return $sign . 'Infinity';
		}

		if ($abs >= 1e21) {
			// Shortest round-trip form, like JavaScript, whatever the host's
			// serialize_precision setting is.
			$previous = ini_set('serialize_precision', '-1');
			try {
				$repr = strtolower(var_export($abs, true));
			} finally {
				ini_set('serialize_precision', (string) $previous);
			}
			[$mantissa, $exponent] = explode('e', $repr);
			if (substr($mantissa, -2) === '.0') {
				$mantissa = substr($mantissa, 0, -2);
			}
			return $sign . $mantissa . 'e' . ($exponent[0] === '-' ? '' : '+') . ltrim($exponent, '+');
		}

		$floor = floor($abs);
		$rounded = ($abs - $floor) >= 0.5 ? $floor + 1 : $floor;

		return $sign . sprintf('%.0f', $rounded);
	}

	/**
	 * @param string|int|float $unit
	 */
	public static function hashUnit($unit): string {
		return self::base64UrlNoPadding(hash('md5', self::unitToString($unit), true));
	}

	/**
	 * @param mixed $value
	 */
	public static function isNumeric($value): bool {
		return is_int($value) || is_float($value);
	}

	/**
	 * True for plain objects (stdClass, e.g. a decoded JSON object).
	 *
	 * @param mixed $value
	 */
	public static function isObject($value): bool {
		return is_object($value) && get_class($value) === \stdClass::class;
	}

	/**
	 * @param mixed $value
	 */
	public static function isPromise($value): bool {
		return $value instanceof PromiseInterface;
	}
}

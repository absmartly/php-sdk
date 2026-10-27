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
use function number_format;
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
			return number_format($unit, 0, '.', '');
		}

		return (string) $unit;
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

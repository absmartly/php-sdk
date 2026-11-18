<?php
declare(strict_types=1);

namespace ABSmartly\SDK\Switchback;

use function is_int;
use function is_string;

/**
 * Port of the Collector's SwitchbackClusterKeys.normalize: the cluster key an attribute value yields, or null when it
 * yields none. A non-empty string is the key as-is; an integer is its base-10 text. Null, empty strings, booleans,
 * floating-point numbers (3.0 included: json_decode keeps it a float), objects and arrays yield no key. The key is
 * passed to SwitchbackAssigner unhashed.
 *
 * @internal
 */
final class SwitchbackClusterKeys {
	private function __construct() {}

	/**
	 * @param mixed $value
	 */
	public static function normalize($value): ?string {
		if (is_string($value)) {
			return $value === '' ? null : $value;
		}
		if (is_int($value)) {
			return (string) $value;
		}
		return null;
	}
}

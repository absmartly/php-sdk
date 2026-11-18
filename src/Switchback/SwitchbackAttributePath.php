<?php
declare(strict_types=1);

namespace ABSmartly\SDK\Switchback;

use Throwable;

use function array_key_exists;
use function count;
use function is_array;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function ord;
use function property_exists;
use function strlen;
use function strpos;
use function substr;

use const JSON_PRESERVE_ZERO_FRACTION;

/**
 * A switchback cluster attribute path, resolved like the Collector's SwitchbackAttributePath: "/" separates segments,
 * a segment wrapped in ' or " is a literal key, and an unquoted segment that Guava Ints.tryParse accepts (ASCII digits,
 * optional leading '-') indexes a list and nothing else. Every other segment reads an object key. A path is malformed,
 * and its experiment unservable, when a quote is left unterminated or any segment key, quoted or not, contains a '.'.
 *
 * @internal
 */
final class SwitchbackAttributePath {
	/** Nesting bound for the attribute value, as in java-sdk (MAX_DEPTH). */
	private const MAX_DEPTH = 64;

	/** @var string[] */
	private array $keys;
	/** @var (int|null)[] Parallel to $keys; null where the segment is an object key. */
	private array $indices;

	/**
	 * @param string[] $keys
	 * @param (int|null)[] $indices
	 */
	private function __construct(array $keys, array $indices) {
		$this->keys = $keys;
		$this->indices = $indices;
	}

	/**
	 * Null for an unsupported path: not a string, malformed (see above), empty, or an array index that is negative,
	 * first, last, or directly after another index. Analysis cannot read those.
	 *
	 * @param mixed $path
	 */
	public static function parseSupported($path): ?self {
		if (!is_string($path)) {
			return null;
		}
		$parsed = self::parse($path);
		if ($parsed === null) {
			return null;
		}
		$n = count($parsed->keys);
		if ($n === 0) {
			return null;
		}
		for ($i = 0; $i < $n; ++$i) {
			$index = $parsed->indices[$i];
			if ($index !== null && ($index < 0 || $i === 0 || $i === $n - 1 || $parsed->indices[$i - 1] !== null)) {
				return null;
			}
		}
		return $parsed;
	}

	/** The attribute name the path starts at; a supported path always starts at a key segment. */
	public function rootKey(): string {
		return $this->keys[0];
	}

	/**
	 * Resolves the whole path in a decoded JSON object of attributes (the platform's attribute-path-cases.json).
	 *
	 * @return mixed
	 */
	public function resolve(object $attributes) {
		return $this->resolveFrom($attributes, 0);
	}

	/**
	 * Resolves the path below rootKey() in an attribute value set with setAttribute(), read as the Collector reads it:
	 * the value's JSON encoding, decoded with objects as objects and lists as arrays. Floats keep their fraction, so
	 * 3.0 stays a float, which never keys a cluster. Null when the value has no JSON encoding (a cycle, NAN, invalid
	 * UTF-8) or nests deeper than MAX_DEPTH.
	 *
	 * @param mixed $rootValue
	 * @return mixed
	 */
	public function resolveAttribute($rootValue) {
		if (is_array($rootValue) || is_object($rootValue)) {
			try {
				$json = json_encode($rootValue, JSON_PRESERVE_ZERO_FRACTION, self::MAX_DEPTH);
			}
			catch (Throwable $exception) {
				// A customer JsonSerializable that throws has no encoding either.
				return null;
			}
			if ($json === false) {
				return null;
			}
			$rootValue = json_decode($json, false);
		}
		return $this->resolveFrom($rootValue, 1);
	}

	/**
	 * @param mixed $root
	 * @return mixed
	 */
	private function resolveFrom($root, int $first) {
		$current = $root;
		for ($i = $first, $n = count($this->keys); $i < $n; ++$i) {
			$index = $this->indices[$i];
			if ($index !== null) {
				// json_decode gives arrays only for JSON arrays, always lists.
				$current = is_array($current) && array_key_exists($index, $current) ? $current[$index] : null;
			}
			else {
				$key = $this->keys[$i];
				$current = is_object($current) && property_exists($current, $key) ? $current->{$key} : null;
			}
			if ($current === null) {
				return null;
			}
		}
		return $current;
	}

	/**
	 * Null when the path is malformed. A '.' anywhere, quoted or not, is unreadable: the Collector rejects any parsed
	 * segment key containing '.', so "geo.city", "'geo.city'" and "\"geo.city\"" are alike unreadable.
	 */
	private static function parse(string $path): ?self {
		if (strpos($path, '.') !== false) {
			return null;
		}
		$keys = [];
		$indices = [];
		$length = strlen($path);
		$i = 0;
		while ($i < $length) {
			$first = $path[$i];
			if ($first === '/') {
				++$i;
				continue;
			}

			if ($first === "'" || $first === '"') {
				$end = strpos($path, $first, $i + 1);
				if ($end === false) {
					return null;
				}
				$keys[] = substr($path, $i + 1, $end - $i - 1);
				$indices[] = null;
				$i = $end + 1;
			}
			else {
				$end = strpos($path, '/', $i);
				$stop = $end === false ? $length : $end;
				$key = substr($path, $i, $stop - $i);
				$keys[] = $key;
				$indices[] = self::tryParseInt($key);
				$i = $stop;
			}
		}
		return new self($keys, $indices);
	}

	/**
	 * Guava Ints.tryParse(String): an optional '-', then one or more ASCII digits, within the 32-bit int range;
	 * otherwise null. "+1" and non-ASCII digits are not numbers; "-0" is 0.
	 */
	public static function tryParseInt(string $s): ?int {
		$length = strlen($s);
		$negative = $length > 0 && $s[0] === '-';
		$i = $negative ? 1 : 0;
		if ($i === $length) {
			return null;
		}
		$magnitude = 0;
		for (; $i < $length; ++$i) {
			$digit = ord($s[$i]) - 48;
			if ($digit < 0 || $digit > 9) {
				return null;
			}
			$magnitude = $magnitude * 10 + $digit;
			if ($magnitude > 2147483648) {
				return null;
			}
		}
		$value = $negative ? -$magnitude : $magnitude;
		return $value > 2147483647 ? null : $value;
	}
}

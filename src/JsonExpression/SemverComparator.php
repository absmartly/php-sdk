<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression;

use function count;
use function explode;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_string;
use function ltrim;
use function max;
use function preg_match;
use function rtrim;
use function sprintf;
use function strcmp;
use function strlen;
use function strpos;
use function substr;

/**
 * Semantic version comparison used by the semver_* operators, matching the JavaScript and Java SDKs: a leading
 * "v"/"V" and build metadata are ignored, missing core parts count as 0, numeric identifiers compare numerically
 * (leading zeros ignored) and rank below alphanumeric ones, and a release ranks above its pre-releases.
 *
 * Parsing is as permissive as the other SDKs' so they order the same inputs the same way: a non-numeric core
 * part (as in "1.x") is compared as an alphanumeric identifier, and an empty pre-release ("1.0.0-") is a release.
 */
final class SemverComparator {
	/**
	 * @param mixed $lhs
	 * @param mixed $rhs
	 * @return int|null -1, 0 or 1, or null when either side is not a string, number or boolean, or has an empty
	 *                  version core after removing a leading v/V and build metadata.
	 */
	public static function compare($lhs, $rhs): ?int {
		$lhsString = self::stringConvert($lhs);
		$rhsString = self::stringConvert($rhs);
		if ($lhsString === null || $rhsString === null) {
			return null;
		}

		$l = self::parse($lhsString);
		$r = self::parse($rhsString);
		if ($l === null || $r === null) {
			return null;
		}

		$coreLength = max(count($l['core']), count($r['core']));
		for ($i = 0; $i < $coreLength; ++$i) {
			$result = self::compareIdentifiers($l['core'][$i] ?? '0', $r['core'][$i] ?? '0');
			if ($result !== 0) {
				return $result;
			}
		}

		if ($l['preRelease'] === '' && $r['preRelease'] === '') {
			return 0;
		}
		if ($l['preRelease'] === '') {
			return 1;
		}
		if ($r['preRelease'] === '') {
			return -1;
		}

		$lPre = explode('.', $l['preRelease']);
		$rPre = explode('.', $r['preRelease']);
		$preLength = max(count($lPre), count($rPre));
		for ($i = 0; $i < $preLength; ++$i) {
			if (!isset($lPre[$i])) {
				return -1;
			}
			if (!isset($rPre[$i])) {
				return 1;
			}
			$result = self::compareIdentifiers($lPre[$i], $rPre[$i]);
			if ($result !== 0) {
				return $result;
			}
		}

		return 0;
	}

	/**
	 * Numbers are formatted like the JavaScript SDK's `toFixed(15)` with trailing zeros removed, so 2.5 is "2.5"
	 * and 10 is "10" regardless of PHP's precision settings.
	 *
	 * @param mixed $value
	 */
	private static function stringConvert($value): ?string {
		if (is_string($value)) {
			return $value;
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_int($value)) {
			return (string) $value;
		}
		if (is_float($value)) {
			if (is_nan($value)) {
				return 'NaN';
			}
			if (is_infinite($value)) {
				return $value > 0 ? 'Infinity' : '-Infinity';
			}
			return rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
		}

		return null;
	}

	/**
	 * @return array{core: string[], preRelease: string}|null
	 */
	private static function parse(string $version): ?array {
		if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
			$version = substr($version, 1);
		}

		$plus = strpos($version, '+');
		if ($plus !== false) {
			$version = substr($version, 0, $plus);
		}

		if ($version === '') {
			return null;
		}

		$dash = strpos($version, '-');
		$core = $dash === false ? $version : substr($version, 0, $dash);
		if ($core === '') {
			return null;
		}

		return [
			'core' => explode('.', $core),
			'preRelease' => $dash === false ? '' : substr($version, $dash + 1),
		];
	}

	private static function compareIdentifiers(string $a, string $b): int {
		$aIsNumeric = self::isNumeric($a);
		$bIsNumeric = self::isNumeric($b);

		if ($aIsNumeric && $bIsNumeric) {
			$a = self::stripLeadingZeros($a);
			$b = self::stripLeadingZeros($b);
			if (strlen($a) !== strlen($b)) {
				return strlen($a) > strlen($b) ? 1 : -1;
			}
			return self::sign(strcmp($a, $b));
		}
		if ($aIsNumeric) {
			return -1;
		}
		if ($bIsNumeric) {
			return 1;
		}

		return self::sign(strcmp($a, $b));
	}

	private static function isNumeric(string $identifier): bool {
		return preg_match('/^[0-9]+$/D', $identifier) === 1;
	}

	private static function stripLeadingZeros(string $identifier): string {
		$stripped = ltrim($identifier, '0');
		return $stripped === '' ? '0' : $stripped;
	}

	private static function sign(int $value): int {
		return $value <=> 0;
	}
}

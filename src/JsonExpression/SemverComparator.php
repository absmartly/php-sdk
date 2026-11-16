<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression;

use function abs;
use function count;
use function explode;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_string;
use function ltrim;
use function ord;
use function max;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strcmp;
use function strlen;
use function strspn;
use function strpos;
use function substr;
use function substr_replace;
use function trim;

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
	 * Numbers are formatted like the JavaScript SDK's `x.toFixed(15).replace(/\.?0{0,15}$/, "")`, regardless of
	 * PHP's precision settings: 2.5 is "2.5", 10 is "10", and from 1e21 up toFixed gives JavaScript's shortest
	 * exponent form, so 1e21 is "1e+21" (and, as in JavaScript, 1e210 loses its trailing zero to "1e+21").
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

			$number = abs($value) >= 1e21 ? self::shortestExponentForm($value) : self::toFixed15($value);
			return preg_replace('/\.?0{0,15}$/D', '', $number, 1);
		}

		return null;
	}

	/**
	 * JavaScript's Number.prototype.toFixed(15) for |x| < 1e21: the exact value rounded to 15 decimals, an exact
	 * half rounding away from zero (PHP's sprintf rounds such a tie to even).
	 */
	private static function toFixed15(float $value): string {
		$sign = $value < 0 ? '-' : '';
		$value = abs($value);

		// 53 decimals tell an exact tie (a value with exactly 16 decimals, the last being 5) from its neighbours:
		// any other double within the toFixed range differs from a tie by far more than 1e-53.
		$exact = sprintf('%.53F', $value);
		$point = strpos($exact, '.');
		if (substr($exact, $point + 16, 1) !== '5' || trim(substr($exact, $point + 17), '0') !== '') {
			return $sign . sprintf('%.15F', $value);
		}

		$digits = substr($exact, 0, $point) . substr($exact, $point + 1, 15);
		for ($i = strlen($digits) - 1; $i >= 0 && $digits[$i] === '9'; --$i) {
			$digits[$i] = '0';
		}
		$digits = $i < 0 ? '1' . $digits : substr_replace($digits, (string) ((int) $digits[$i] + 1), $i, 1);

		return $sign . substr($digits, 0, -15) . '.' . substr($digits, -15);
	}

	/**
	 * JavaScript's Number.prototype.toString() for |x| >= 1e21: the fewest significant digits that read back as
	 * $value, as d[.ddd]e+n.
	 */
	private static function shortestExponentForm(float $value): string {
		for ($precision = 0; $precision < 16; ++$precision) {
			$number = sprintf('%.' . $precision . 'e', $value);
			if ((float) $number === $value) {
				return $number;
			}
		}

		return sprintf('%.16e', $value);
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

		return self::compareUtf16($a, $b);
	}

	/**
	 * JavaScript orders strings by UTF-16 code unit. UTF-8 byte order is code point order, which differs only where
	 * a supplementary character (a surrogate pair, from 0xD800; UTF-8 lead byte F0-F4) meets one in U+E000-U+FFFF
	 * (lead byte EE or EF).
	 */
	private static function compareUtf16(string $a, string $b): int {
		$result = self::sign(strcmp($a, $b));
		$i = strspn($a ^ $b, "\0");
		if ($result === 0 || !isset($a[$i], $b[$i])) {
			return $result;
		}

		$aLead = ord($a[$i]);
		$bLead = ord($b[$i]);
		if (($aLead >= 0xF0 && ($bLead === 0xEE || $bLead === 0xEF))
			|| ($bLead >= 0xF0 && ($aLead === 0xEE || $aLead === 0xEF))) {
			return -$result;
		}

		return $result;
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

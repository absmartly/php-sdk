<?php
declare(strict_types=1);

namespace ABSmartly\SDK\Switchback;

use InvalidArgumentException;

use function floor;
use function hash;
use function hexdec;
use function intdiv;
use function min;
use function ord;
use function pack;
use function sprintf;
use function strlen;
use function unpack;

use const PHP_VERSION_ID;

/**
 * Balanced-block switchback assignment. Port of java-sdk's internal SwitchbackAssigner (the platform's
 * SwitchbackAssignment, murmur3_32 with seed 0):
 *   key       = utf8(clusterUnit + "\u001f" + cycleIndex)
 *   baseHash  = murmur3_32(le64(seed) ++ le32(murmur3_32(key)))
 *   stepHash  = murmur3_32(le32(baseHash) ++ le32(i)),  i = armCount-1 .. 1
 *   j         = min(i, floor(u32(stepHash) / 0xffffffff * (i + 1)))
 *   variant   = permutation[floorMod(periodIndex, armCount)], cycle = floorDiv(periodIndex, armCount)
 * Any change here breaks parity with the platform golden vectors and the analysis-side schedule.
 *
 * @internal
 */
final class SwitchbackAssigner {
	private const NORMALIZER = 1.0 / 0xFFFFFFFF;
	private const SEPARATOR = "\x1f";

	private function __construct() {}

	public static function assign(string $clusterUnit, int $periodIndex, int $seed, int $armCount): int {
		self::requireValidArmCount($armCount);
		$cycleIndex = self::floorDiv($periodIndex, $armCount);
		// floorMod with %, not periodIndex - cycleIndex * armCount: PHP turns that product into a float when it
		// overflows near PHP_INT_MIN, where Java's long arithmetic wraps.
		$cycleSlot = $periodIndex % $armCount;
		if ($cycleSlot < 0) {
			$cycleSlot += $armCount;
		}
		return self::fisherYatesPermutation($armCount, self::baseHash($clusterUnit, $cycleIndex, $seed))[$cycleSlot];
	}

	public static function baseHash(string $clusterUnit, int $cycleIndex, int $seed): int {
		$keyHash = self::digest($clusterUnit . self::SEPARATOR . $cycleIndex);
		return self::digest(pack('V3', $seed & 0xFFFFFFFF, ($seed >> 32) & 0xFFFFFFFF, $keyHash));
	}

	/**
	 * @return int[]
	 */
	public static function fisherYatesPermutation(int $armCount, int $baseHash): array {
		self::requireValidArmCount($armCount);

		$permutation = [];
		for ($i = 0; $i < $armCount; ++$i) {
			$permutation[] = $i;
		}

		$prefix = pack('V', $baseHash & 0xFFFFFFFF);
		for ($i = $armCount - 1; $i > 0; --$i) {
			$probability = self::digest($prefix . pack('V', $i)) * self::NORMALIZER;
			// probability == 1.0 would map to i + 1
			$j = min($i, (int) floor($probability * ($i + 1)));

			$tmp = $permutation[$i];
			$permutation[$i] = $permutation[$j];
			$permutation[$j] = $tmp;
		}

		return $permutation;
	}

	/**
	 * Floor division for a positive divisor. intdiv() truncates toward zero, so a negative inexact quotient is one
	 * less.
	 */
	public static function floorDiv(int $dividend, int $divisor): int {
		$quotient = intdiv($dividend, $divisor);
		return (($dividend % $divisor) !== 0 && (($dividend ^ $divisor) < 0)) ? $quotient - 1 : $quotient;
	}

	/**
	 * The unsigned murmur3_32 (seed 0) of $bytes. PHP 8.1+ has it natively; older runtimes use murmur3_32() below, not
	 * lastguest/murmurhash, whose 64-bit arithmetic leaves the hash unmasked and diverges for keys longer than 4 bytes.
	 * @codeCoverageIgnore Because this snippet is version-dependent.
	 */
	private static function digest(string $bytes): int {
		if (PHP_VERSION_ID >= 80100) {
			return hexdec(hash('murmur3a', $bytes));
		}

		return self::murmur3_32($bytes);
	}

	/**
	 * Unsigned murmur3_32 with seed 0 in plain 64-bit integer arithmetic, masked to 32 bits after every step.
	 */
	public static function murmur3_32(string $bytes): int {
		$length = strlen($bytes);
		$h = 0;
		$blocks = $length - ($length & 3);
		for ($i = 0; $i < $blocks; $i += 4) {
			$h ^= self::mixK(unpack('V', $bytes, $i)[1]);
			$h = (($h << 13) | ($h >> 19)) & 0xFFFFFFFF;
			$h = (self::mul32($h, 5) + 0xE6546B64) & 0xFFFFFFFF;
		}
		$k = 0;
		switch ($length & 3) {
			case 3:
				$k ^= ord($bytes[$blocks + 2]) << 16;
			// no break
			case 2:
				$k ^= ord($bytes[$blocks + 1]) << 8;
			// no break
			case 1:
				$k ^= ord($bytes[$blocks]);
				$h ^= self::mixK($k);
		}
		$h ^= $length;
		$h ^= $h >> 16;
		$h = self::mul32($h, 0x85EBCA6B);
		$h ^= $h >> 13;
		$h = self::mul32($h, 0xC2B2AE35);
		return $h ^ ($h >> 16);
	}

	private static function mixK(int $k): int {
		$k = self::mul32($k, 0xCC9E2D51);
		$k = (($k << 15) | ($k >> 17)) & 0xFFFFFFFF;
		return self::mul32($k, 0x1B873593);
	}

	/** ($a * $b) mod 2^32 for 32-bit unsigned operands, split so the product never leaves the 64-bit int range. */
	private static function mul32(int $a, int $b): int {
		return ((($a & 0xFFFF) * $b) + (((($a >> 16) * $b) & 0xFFFF) << 16)) & 0xFFFFFFFF;
	}

	private static function requireValidArmCount(int $armCount): void {
		if ($armCount < 2) {
			throw new InvalidArgumentException(sprintf('armCount must be >= 2, got %d', $armCount));
		}
	}
}

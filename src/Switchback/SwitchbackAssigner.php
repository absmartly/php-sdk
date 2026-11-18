<?php
declare(strict_types=1);

namespace ABSmartly\SDK\Switchback;

use InvalidArgumentException;
use lastguest\Murmur;

use function floor;
use function hash;
use function hexdec;
use function intdiv;
use function min;
use function pack;
use function sprintf;

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
	 * The unsigned murmur3_32 (seed 0) of $bytes, as VariantAssigner computes it.
	 * @codeCoverageIgnore Because this snippet is version-dependent.
	 */
	private static function digest(string $bytes): int {
		if (PHP_VERSION_ID >= 80100) {
			return hexdec(hash('murmur3a', $bytes));
		}

		return Murmur::hash3_int($bytes);
	}

	private static function requireValidArmCount(int $armCount): void {
		if ($armCount < 2) {
			throw new InvalidArgumentException(sprintf('armCount must be >= 2, got %d', $armCount));
		}
	}
}

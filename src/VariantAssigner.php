<?php
declare(strict_types=1);

namespace ABSmartly\SDK;

use InvalidArgumentException;
use lastguest\Murmur;

use function array_values;
use function base64_encode;
use function count;
use function gettype;
use function hash;
use function hexdec;
use function is_numeric;
use function pack;
use function sprintf;
use function strtr;

use const PHP_VERSION_ID;

final class VariantAssigner {

	/**
	 * @readonly
	 * @var int
	 */
	private int $unitHash;

	public function __construct(string $unit) {
		$hash = hash('md5', $unit, true);

		// Removing padding and +/ characters in the base64 encoded string.
		$hash = strtr(base64_encode($hash), [
			'+' => '-',
			'/' => '_',
			'=' => '',
		]);

		$this->unitHash = $this->digest($hash);
	}

	/**
	 * Calculates the murmur3a hash. On PHP 8.1, this uses the native implementation, and on older versions, a user-land
	 * polyfill.
	 * @codeCoverageIgnore Because this snippet is version-dependent.
	 * @param string $seed
	 * @return int
	 */
	private function digest(string $seed): int {
		if (PHP_VERSION_ID >= 80100) {
			return hexdec(hash('murmur3a', $seed));
		}

		return Murmur::hash3_int($seed);
	}

	public function assign(array $split, int $seedHi, int $seedLo): int {
		$probability = $this->probability($seedHi, $seedLo);
		return $this->chooseVariant($split, $probability);
	}

	/**
	 * @param float[] $split
	 * @param float $probability
	 * @return int
	 */
	public function chooseVariant(array $split, float $probability): int {
		$cumSum = 0.0;
		$split = array_values($split); // Reindex array to make sure to avoid custom array keys

		foreach ($split as $key => $value) {
			if (!is_numeric($value)) { // Make sure we have float[]
				throw new InvalidArgumentException(
					sprintf('VariantAssigner::chooseVariant($split) must be an array of float values. Encountered: "%s" of type %s at key position %d',
					        $value, gettype($value), $key)
				);
			}

			$cumSum += (float) $value;

			if ($probability < $cumSum) {
				return $key;
			}
		}

		return count($split) - 1;
	}

	private function probability(int $seedHi, int $seedLo): float {
		$buffer = [$seedLo, $seedHi, $this->unitHash];
		return $this->digest(pack('V*', ...$buffer)) * (1.0 / 0xFFFFFFFF);
	}
}

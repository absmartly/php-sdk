<?php

namespace ABSmartly\SDK\Tests;

use PHPUnit\Framework\TestCase;

class Murmur3Test extends TestCase {
	private function murmur3Hash(string $input, int $seed = 0): int {
		return hexdec(hash('murmur3a', $input, false, ['seed' => $seed]));
	}

	public static function murmur3Seed0Provider(): array {
		return [
			['', 0],
			[' ', 2129959832],
			['t', 3397902157],
			['te', 3988319771],
			['tes', 196677210],
			['test', 3127628307],
			['testy', 1152353090],
			['testy1', 2316969018],
			['testy12', 2220122553],
			['testy123', 1197640388],
			['special characters açb↓c', 3196301632],
			['The quick brown fox jumps over the lazy dog', 776992547],
		];
	}

	public static function murmur3SeedDeadbeefProvider(): array {
		return [
			['', 233162409],
			[' ', 632081987],
			['t', 991288568],
			['te', 2895647538],
			['tes', 3251080666],
			['test', 2854409242],
			['testy', 2230711843],
			['testy1', 166537449],
			['testy12', 575043637],
			['testy123', 3593668109],
			['special characters açb↓c', 4160608418],
			['The quick brown fox jumps over the lazy dog', 981155661],
		];
	}

	public static function murmur3Seed1Provider(): array {
		return [
			['', 1364076727],
			[' ', 1326412082],
			['t', 1571914526],
			['te', 3527981870],
			['tes', 3560106868],
			['test', 2579507938],
			['testy', 3316833310],
			['testy1', 865230059],
			['testy12', 3643580195],
			['testy123', 1002533165],
			['special characters açb↓c', 691218357],
			['The quick brown fox jumps over the lazy dog', 2028379687],
		];
	}

	/**
	 * @dataProvider murmur3Seed0Provider
	 */
	public function testShouldMatchKnownHashesWithSeed0(string $input, int $expectedHash): void {
		self::assertSame($expectedHash, $this->murmur3Hash($input, 0));
	}

	/**
	 * @dataProvider murmur3SeedDeadbeefProvider
	 */
	public function testShouldMatchKnownHashesWithSeedDeadbeef(string $input, int $expectedHash): void {
		self::assertSame($expectedHash, $this->murmur3Hash($input, 0xdeadbeef));
	}

	/**
	 * @dataProvider murmur3Seed1Provider
	 */
	public function testShouldMatchKnownHashesWithSeed1(string $input, int $expectedHash): void {
		self::assertSame($expectedHash, $this->murmur3Hash($input, 1));
	}
}

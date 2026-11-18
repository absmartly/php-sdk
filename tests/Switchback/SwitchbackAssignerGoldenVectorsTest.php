<?php

namespace ABSmartly\SDK\Tests\Switchback;

use ABSmartly\SDK\Switchback\SwitchbackAssigner;
use PHPUnit\Framework\TestCase;

/**
 * Port of java-sdk SwitchbackAssignerGoldenVectorsTest. Fixture copied byte-for-byte from absmartly/abs 9bd2b5ca35
 * lib/utils/src/test/resources/switchback/golden-vectors.json (git blob b314abeb9e97), the same copy java-sdk and
 * cross-sdk-tests vendor.
 */
class SwitchbackAssignerGoldenVectorsTest extends TestCase {
	private const FIXTURE = __DIR__ . '/../Fixtures/switchback/golden-vectors.json';

	private static function loadVectors(): object {
		return json_decode(file_get_contents(self::FIXTURE), false, 512, JSON_THROW_ON_ERROR);
	}

	/** The platform hashes a numeric cluster unit as Long.toString(n); strings pass through. */
	private static function clusterUnit($value): string {
		if (is_int($value)) {
			return (string) $value;
		}
		self::assertIsString($value);
		return $value;
	}

	/** 64-bit fields are decimal strings; a number would mean the fixture regressed to a lossy encoding. */
	private static function exactLong($value): int {
		self::assertIsString($value);
		self::assertMatchesRegularExpression('/^-?[0-9]+$/', $value);
		$parsed = (int) $value;
		self::assertSame($value, (string) $parsed, 'outside the 64-bit range');
		return $parsed;
	}

	public function testFixtureIsTheByteIdenticalPlatformBlob(): void {
		$bytes = file_get_contents(self::FIXTURE);
		self::assertSame('b314abeb9e97b35f911c7caacec90b8ff73b9d77', sha1('blob ' . strlen($bytes) . "\0" . $bytes));
	}

	public function testAssignSwitchbackMatchesEveryGoldenVector(): void {
		$cases = self::loadVectors()->assignSwitchback;
		self::assertCount(882, $cases);

		$armCounts = [];
		$boundaryCases = 0;
		foreach ($cases as $c) {
			$periodIndex = self::exactLong($c->periodIndex);
			$seed = self::exactLong($c->seed);
			$armCounts[$c->armCount] = true;
			if (in_array($periodIndex, [PHP_INT_MIN, PHP_INT_MAX], true) || in_array($seed, [PHP_INT_MIN, PHP_INT_MAX], true)) {
				++$boundaryCases;
			}

			self::assertSame($c->expectedVariant,
				SwitchbackAssigner::assign(self::clusterUnit($c->clusterUnit), $periodIndex, $seed, $c->armCount),
				json_encode($c));
		}

		self::assertArrayHasKey(2, $armCounts);
		self::assertArrayHasKey(3, $armCounts);
		self::assertArrayHasKey(10, $armCounts);
		self::assertGreaterThan(0, $boundaryCases);
	}

	public function testFisherYatesPermutationMatchesEveryGoldenVector(): void {
		$cases = self::loadVectors()->fisherYatesPermutation;
		self::assertCount(504, $cases);

		foreach ($cases as $c) {
			$actual = SwitchbackAssigner::fisherYatesPermutation($c->armCount,
				SwitchbackAssigner::baseHash(self::clusterUnit($c->clusterUnit), self::exactLong($c->cycleIndex), self::exactLong($c->seed)));
			self::assertSame($c->expectedPermutation, $actual, json_encode($c));
		}
	}

	/**
	 * The platform vectors only use 2, 3 and 10 arms and ASCII keys, which cannot tell a normalizer of 1/0xffffffff
	 * from 1/2^32. These come from java-sdk's own SwitchbackAssigner (tests/Fixtures/switchback-java), over UTF-8 keys,
	 * extreme periods and seeds, and 4 to 100 arms.
	 */
	public function testMatchesJavaSdkAcrossArmCountsAndUtf8Keys(): void {
		$vectors = json_decode(file_get_contents(__DIR__ . '/../Fixtures/switchback-java/java-sdk-assigner-vectors.json'), false, 512, JSON_THROW_ON_ERROR);
		self::assertCount(3564, $vectors->assignSwitchback);
		foreach ($vectors->assignSwitchback as $c) {
			self::assertSame($c->expectedVariant,
				SwitchbackAssigner::assign($c->clusterUnit, self::exactLong($c->periodIndex), self::exactLong($c->seed), $c->armCount),
				json_encode($c, JSON_UNESCAPED_UNICODE));
		}
		self::assertCount(5, $vectors->fisherYatesPermutation);
		foreach ($vectors->fisherYatesPermutation as $c) {
			self::assertSame($c->expectedPermutation, SwitchbackAssigner::fisherYatesPermutation($c->armCount, $c->baseHash), json_encode($c));
		}
		// The one that pins the normalizer: 1/2^32 would swap positions 2 and 7 and 7 and 10.
		self::assertSame([11, 15, 8, 13, 7, 0, 5, 10, 4, 3, 9, 12, 6, 1, 2, 14], SwitchbackAssigner::fisherYatesPermutation(16, 13189659));
	}

	/**
	 * The pre-8.1 fallback is this class's own murmur3_32, not lastguest/murmurhash, which gives e424440a for the first
	 * key below (about 1 input in 75,000 differs) on PHP 7.4/8.0. Native murmur3a values; runs on every CI runtime.
	 */
	public function testPurePhpMurmur3MatchesNativeMurmur3(): void {
		foreach ([["store-294007\x1f0", 0x47866670], ["store-240098\x1f0", 0xd9e9cd1b], ["store-412903\x1f0", 0xf525e2d2],
			["store-444285\x1f0", 0x6254a4d1], ['', 0x00000000], ['a', 0x3c2569b2]] as [$bytes, $expected]) {
			self::assertSame($expected, SwitchbackAssigner::murmur3_32($bytes), bin2hex($bytes));
		}
		self::assertSame(1, SwitchbackAssigner::assign('store-294007', 0, (3603515 << 32) | 233373850, 2));
		if (PHP_VERSION_ID >= 80100) {
			for ($i = 0; $i < 2000; ++$i) {
				$bytes = $i === 0 ? '' : random_bytes($i % 41 + 1);
				self::assertSame(hexdec(hash('murmur3a', $bytes)), SwitchbackAssigner::murmur3_32($bytes), bin2hex($bytes));
			}
		}
	}

	/**
	 * The serving unit's traffic and split-rule draw uses the platform murmur3 on every runtime: lastguest/murmurhash
	 * puts these units in the other bucket on PHP 7.4/8.0. On 8.1+ it equals VariantAssigner, which is native there.
	 */
	public function testServingUnitDrawMatchesThePlatformOnEveryRuntime(): void {
		foreach ([['store-32476', 0], ['store-52503', 1], ['store-121460', 0], ['store-125857', 0]] as [$uid, $expected]) {
			self::assertSame($expected, SwitchbackAssigner::assignUnit($uid, [0.5, 0.5], 1, 2), $uid);
		}
		if (PHP_VERSION_ID >= 80100) {
			foreach ([[0.5, 0.5], [0.2, 0.3, 0.5], [1.0, 0.0], [0.0, 1.0]] as $split) {
				for ($i = 0; $i < 300; ++$i) {
					$uid = 'u-' . $i;
					self::assertSame((new \ABSmartly\SDK\VariantAssigner($uid))->assign($split, -5, 7 + $i),
						SwitchbackAssigner::assignUnit($uid, $split, -5, 7 + $i), $uid);
				}
			}
		}
	}

	public function testEveryCycleCoversEveryArmExactlyOnce(): void {
		foreach ([2, 3, 10] as $armCount) {
			for ($cycle = -3; $cycle < 20; ++$cycle) {
				$seen = [];
				for ($slot = 0; $slot < $armCount; ++$slot) {
					$variant = SwitchbackAssigner::assign('store_42', $cycle * $armCount + $slot, 0x123456789abc, $armCount);
					self::assertArrayNotHasKey($variant, $seen, 'arm repeated within a cycle');
					$seen[$variant] = true;
				}
				ksort($seen);
				self::assertSame(range(0, $armCount - 1), array_keys($seen), 'a cycle is not the arm set');
			}
		}
	}

	public function testRejectsFewerThanTwoArms(): void {
		foreach ([static function () {
			SwitchbackAssigner::assign('u', 0, 0, 1);
		}, static function () {
			SwitchbackAssigner::fisherYatesPermutation(0, 0);
		}] as $call) {
			try {
				$call();
				self::fail('expected InvalidArgumentException');
			}
			catch (\InvalidArgumentException $exception) {
				self::assertStringContainsString('armCount must be >= 2', $exception->getMessage());
			}
		}
	}

	public function testFloorDiv(): void {
		self::assertSame(0, SwitchbackAssigner::floorDiv(0, 3));
		self::assertSame(0, SwitchbackAssigner::floorDiv(2, 3));
		self::assertSame(1, SwitchbackAssigner::floorDiv(3, 3));
		self::assertSame(-1, SwitchbackAssigner::floorDiv(-1, 3));
		self::assertSame(-1, SwitchbackAssigner::floorDiv(-3, 3));
		self::assertSame(-2, SwitchbackAssigner::floorDiv(-4, 3));
		// Math.floorDiv(Long.MIN_VALUE, 3L) and Math.floorDiv(Long.MAX_VALUE, 10L).
		self::assertSame(-3074457345618258603, SwitchbackAssigner::floorDiv(PHP_INT_MIN, 3));
		self::assertSame(922337203685477580, SwitchbackAssigner::floorDiv(PHP_INT_MAX, 10));
		self::assertSame(-922337203685477581, SwitchbackAssigner::floorDiv(PHP_INT_MIN, 10));
		self::assertSame(-2, SwitchbackAssigner::floorDiv(PHP_INT_MIN, PHP_INT_MAX));
		self::assertSame(-1, SwitchbackAssigner::floorDiv(-1, PHP_INT_MAX));
	}

	/** The extreme periods select the slot by floorMod: PHP_INT_MIN mod 3 is 1, not the truncated -2. */
	public function testExtremePeriodsUseTheFlooredSlot(): void {
		$seed = 42;
		$cycle = SwitchbackAssigner::floorDiv(PHP_INT_MIN, 3);
		$permutation = SwitchbackAssigner::fisherYatesPermutation(3, SwitchbackAssigner::baseHash('market_1', $cycle, $seed));
		self::assertSame($permutation[1], SwitchbackAssigner::assign('market_1', PHP_INT_MIN, $seed, 3));

		$cycle = SwitchbackAssigner::floorDiv(PHP_INT_MAX, 10);
		$permutation = SwitchbackAssigner::fisherYatesPermutation(10, SwitchbackAssigner::baseHash('market_1', $cycle, $seed));
		self::assertSame($permutation[7], SwitchbackAssigner::assign('market_1', PHP_INT_MAX, $seed, 10));
	}
}

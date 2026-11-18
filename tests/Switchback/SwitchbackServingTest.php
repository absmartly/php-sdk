<?php

namespace ABSmartly\SDK\Tests\Switchback;

use ABSmartly\SDK\Switchback\SwitchbackAttributePath;
use ABSmartly\SDK\Switchback\SwitchbackClusterKeys;
use ABSmartly\SDK\Switchback\SwitchbackServing;
use PHPUnit\Framework\TestCase;

/** Ports java-sdk SwitchbackServingTest and the cluster-value rule of SwitchbackClusterKeys. */
class SwitchbackServingTest extends TestCase {
	public function testSourceOrderDecidesWhateverTheUnitOrder(): void {
		$types = ['store_id', 'device_id'];
		$paths = [SwitchbackAttributePath::parseSupported('store'), SwitchbackAttributePath::parseSupported('device')];
		$values = ['store' => 'porto', 'device' => 'porto'];
		$read = static function (SwitchbackAttributePath $path) use (&$values) {
			return $values[$path->rootKey()] ?? null;
		};

		foreach ([['device_id' => 'd', 'store_id' => 's'], ['store_id' => 's', 'device_id' => 'd']] as $units) {
			$serving = SwitchbackServing::resolve($types, $paths, null, $units, $read);
			self::assertSame(SwitchbackServing::ELIGIBLE, $serving->status);
			self::assertSame('store_id', $serving->unitType);
			self::assertSame('porto', $serving->clusterKey);
		}

		$values = ['device' => 'lisbon'];
		$serving = SwitchbackServing::resolve($types, $paths, null, ['device_id' => 'd', 'store_id' => 's'], $read);
		self::assertSame([SwitchbackServing::ELIGIBLE, 'device_id', 'lisbon'], [$serving->status, $serving->unitType, $serving->clusterKey]);

		$values = ['store' => 'porto', 'device' => 'lisbon'];
		$serving = SwitchbackServing::resolve($types, $paths, null, ['device_id' => 'd', 'store_id' => 's'], $read);
		self::assertSame([SwitchbackServing::INELIGIBLE, 'store_id', null], [$serving->status, $serving->unitType, $serving->clusterKey]);

		// No value: ineligible under the first linked unit; no linked unit: absent.
		$values = [];
		$serving = SwitchbackServing::resolve($types, $paths, null, ['device_id' => 'd'], $read);
		self::assertSame([SwitchbackServing::INELIGIBLE, 'device_id', null], [$serving->status, $serving->unitType, $serving->clusterKey]);
		$serving = SwitchbackServing::resolve($types, $paths, null, ['user_id' => 'u'], $read);
		self::assertSame([SwitchbackServing::ABSENT, null, null], [$serving->status, $serving->unitType, $serving->clusterKey]);
	}

	public function testAllowList(): void {
		self::assertTrue(SwitchbackServing::allowed(null, 'porto'));
		self::assertTrue(SwitchbackServing::allowed([], 'porto'));
		self::assertTrue(SwitchbackServing::allowed(['lisbon', 'porto'], 'porto'));
		self::assertFalse(SwitchbackServing::allowed(['lisbon'], 'porto'));
		// Strict: "1" is not "01", and a non-string entry never matches.
		self::assertFalse(SwitchbackServing::allowed(['01'], '1'));
		self::assertFalse(SwitchbackServing::allowed([1.0], '1'));
		self::assertTrue(SwitchbackServing::allowed(['1'], '1'));
	}

	public function testLegacyServesAnIneligibleControlUnderTheDeclaredUnitType(): void {
		$serving = SwitchbackServing::legacy('store_id', ['store_id' => 's']);
		self::assertSame([SwitchbackServing::INELIGIBLE, 'store_id', null], [$serving->status, $serving->unitType, $serving->clusterKey]);
		$serving = SwitchbackServing::legacy('store_id', ['user_id' => 'u']);
		self::assertSame([SwitchbackServing::ABSENT, null, null], [$serving->status, $serving->unitType, $serving->clusterKey]);
	}

	/** The owner's cluster-value rule: non-empty strings as-is, integers as base-10 text, nothing else. */
	public function testClusterValueRule(): void {
		self::assertSame('porto', SwitchbackClusterKeys::normalize('porto'));
		self::assertSame(' ', SwitchbackClusterKeys::normalize(' '));
		self::assertSame('0', SwitchbackClusterKeys::normalize('0'));
		self::assertSame('3', SwitchbackClusterKeys::normalize(3));
		self::assertSame('0', SwitchbackClusterKeys::normalize(0));
		self::assertSame('-42', SwitchbackClusterKeys::normalize(-42));
		self::assertSame('9223372036854775807', SwitchbackClusterKeys::normalize(PHP_INT_MAX));
		self::assertSame('-9223372036854775808', SwitchbackClusterKeys::normalize(PHP_INT_MIN));
		foreach ([null, '', true, false, 3.0, 1.5, -0.0, 0.0, 1e20, NAN, INF, [], ['a'], (object) ['a' => 1], new \stdClass()] as $value) {
			self::assertNull(SwitchbackClusterKeys::normalize($value), var_export($value, true));
		}
	}
}

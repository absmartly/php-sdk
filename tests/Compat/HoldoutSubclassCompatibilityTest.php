<?php

namespace ABSmartly\SDK\Tests\Compat;

use ABSmartly\SDK\Assignment;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Experiment;
use PHPUnit\Framework\TestCase;

class TypedHoldoutAssignment extends Assignment {
	public bool $served = true;
	public string $holdouts = 'customer';
	public string $holdoutAssignments = 'customer';
	public string $holdoutArmCount = 'customer';
	public string $suppressed = 'customer';

	public function getSDKState(): string {
		return 'customer';
	}
}

class TypedHoldoutExperiment extends Experiment {
	public string $holdoutIds = 'customer';
	public int $holdoutType = 7;

	public function sdkHoldoutIds(int $x): int {
		return $x;
	}
}

class TypedHoldoutContextData extends ContextData {
	public string $holdouts = 'customer';
}

class UntypedHoldoutContextData extends ContextData {
	public $holdouts;
}

/**
 * Customer subclasses written against 1.1.0 that already use a name the holdouts change needs still load and
 * construct: the SDK adds no declared property or method to these models.
 */
class HoldoutSubclassCompatibilityTest extends TestCase {
	public function testSubclassesThatReserveHoldoutNamesStillLoad(): void {
		$assignment = new TypedHoldoutAssignment();
		self::assertSame('customer', $assignment->holdouts);
		self::assertSame('customer', $assignment->getSDKState());

		$experiment = new TypedHoldoutExperiment((object) [
			'id' => 1, 'name' => 'exp', 'unitType' => 'session_id', 'iteration' => 1, 'seedHi' => 1, 'seedLo' => 2,
			'split' => [0.5, 0.5], 'trafficSeedHi' => 1, 'trafficSeedLo' => 2, 'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => 0, 'variants' => [], 'holdoutIds' => [11], 'holdoutType' => 'full',
		]);
		self::assertSame('customer', $experiment->holdoutIds);
		self::assertSame(7, $experiment->holdoutType);
		self::assertSame(3, $experiment->sdkHoldoutIds(3));

		self::assertSame('customer', (new TypedHoldoutContextData([], [(object) ['id' => 11]]))->holdouts);
		self::assertNull((new UntypedHoldoutContextData([], []))->holdouts);
	}
}

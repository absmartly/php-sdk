<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;
use ABSmartly\SDK\Exposure;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use ABSmartly\SDK\Tests\Mocks\HoldoutDataProvider;
use ABSmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy;
use PHPUnit\Framework\TestCase;

class ThrowingExposureLogger extends MockContextEventLoggerProxy {
	/** @var callable(Exposure): bool */
	public $throwWhen;

	public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
		parent::handleEvent($context, $event);
		$data = $event->getData();
		if ($event->getEvent() === ContextEventLoggerEvent::Exposure && ($this->throwWhen)($data)) {
			throw new \RuntimeException('boom');
		}
	}
}

/**
 * Ports java-sdk ContextHoldoutTest (the javascript-sdk holdout contract). A holdout arrives in ContextData::$holdouts
 * and covers the experiments that list its id in holdoutIds. Arm 0 holds the unit out; in a 3-arm holdout arm 1 holds
 * out experiments that are not full-on. A held-out unit gets control and no exposure for the covered experiment; the
 * holdout publishes one ordinary exposure per holdout and unit type.
 */
class ContextHoldoutTest extends TestCase {
	private const UNIT_TYPE = 'session_id';
	private const UID = 'e791e240fcd3df7d238cfc285f475e8152fcc0ec';
	private const UID_NOT_HELD_OUT = 'b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3';

	// split [0.5, 0.5], seed 100/200: variant 1 for UID, 0 for UID_NOT_HELD_OUT.
	private const NORMAL_VARIANT = 1;

	// split [0.1, 0.9]: seed 13/111 holds UID out (arm 0) and not UID_NOT_HELD_OUT; seed 1/222 holds out neither.
	private const HOLDOUT_A_SEED = [13, 111];
	private const HOLDOUT_B_SEED = [1, 222];

	// 3-arm split [0.3, 0.3, 0.4] for UID: seed 0/1 -> arm 0, 0/3 -> arm 1, 0/0 -> arm 2.
	private const THREE_ARM_SPLIT = [0.3, 0.3, 0.4];
	private const THREE_ARM_0_SEED = [0, 1];
	private const THREE_ARM_1_SEED = [0, 3];
	private const THREE_ARM_2_SEED = [0, 0];

	private const US_RULE = '{"rules":[{"name":"US Internal Users","type":"assign","conditions":{"and":[{"eq":[{"var":"country"},{"value":"US"}]}]},"environments":[],"variant":1}]}';

	private ContextEventHandlerMock $eventHandler;
	private HoldoutDataProvider $dataProvider;

	private static function experiment(int $id, string $name, string $unitType = self::UNIT_TYPE, int $fullOnVariant = 0): object {
		return (object) [
			'id' => $id,
			'name' => $name,
			'unitType' => $unitType,
			'iteration' => 1,
			'seedHi' => 100,
			'seedLo' => 200,
			'split' => [0.5, 0.5],
			'trafficSeedHi' => 1,
			'trafficSeedLo' => 2,
			'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => $fullOnVariant,
			'applications' => [(object) ['name' => 'website']],
			'variants' => [(object) ['name' => 'A', 'config' => null], (object) ['name' => 'B', 'config' => null]],
			'audienceStrict' => false,
			'audience' => null,
		];
	}

	private static function holdout(int $id, string $name, array $seed, string $unitType = self::UNIT_TYPE, string $holdoutType = 'full'): object {
		return (object) [
			'id' => $id,
			'name' => $name,
			'unitType' => $unitType,
			'iteration' => 1,
			'seedHi' => $seed[0],
			'seedLo' => $seed[1],
			'split' => [0.1, 0.9],
			'trafficSeedHi' => 0,
			'trafficSeedLo' => 0,
			'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => 0,
			'applications' => [],
			'variants' => [(object) ['name' => 'A', 'config' => null], (object) ['name' => 'B', 'config' => null]],
			'audienceStrict' => false,
			'audience' => null,
			'holdoutType' => $holdoutType,
		];
	}

	private static function threeArmHoldout(int $id, string $name, array $seed): object {
		$holdout = self::holdout($id, $name, $seed, self::UNIT_TYPE, 'all_full_on');
		$holdout->split = self::THREE_ARM_SPLIT;
		$holdout->variants[] = (object) ['name' => 'C', 'config' => null];
		return $holdout;
	}

	private static function coveredBy(object $experiment, int ...$holdoutIds): object {
		$experiment->holdoutIds = $holdoutIds;
		return $experiment;
	}

	private static function withVariables(object $experiment, string $key): object {
		$experiment->variants = [
			(object) ['name' => 'A', 'config' => json_encode([$key => 'control'])],
			(object) ['name' => 'B', 'config' => json_encode([$key => 'treatment'])],
		];
		return $experiment;
	}

	private static function data(array $holdouts, object ...$experiments): ContextData {
		return new ContextData($experiments, $holdouts);
	}

	private function createContext(ContextData $data, array $units = [self::UNIT_TYPE => self::UID], array $overrides = [], array $custom = [], $logger = null): Context {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$this->dataProvider = new HoldoutDataProvider();
		$config->setContextDataProvider($this->dataProvider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($units);
		$contextConfig->setOverrides($overrides);
		$contextConfig->setCustomAssignments($custom);
		if ($logger !== null) {
			$contextConfig->setEventLogger($logger);
		}

		return (new SDK($config))->createContextWithData($contextConfig, $data);
	}

	private function refresh(Context $context, ContextData $data): void {
		$this->dataProvider->next = $data;
		$context->refresh();
	}

	/** [id, name, unit, variant, assigned, eligible, overridden, fullOn, custom, audienceMismatch, ruleOverride] */
	private static function exposure(int $id, string $name, int $variant, bool $assigned = true, bool $overridden = false,
		bool $fullOn = false, bool $custom = false, bool $audienceMismatch = false, bool $ruleOverride = false,
		string $unit = self::UNIT_TYPE): array {
		return [$id, $name, $unit, $variant, $assigned, true, $overridden, $fullOn, $custom, $audienceMismatch, $ruleOverride];
	}

	private static function holdoutExposure(int $id, string $name, int $variant, string $unit = self::UNIT_TYPE): array {
		return self::exposure($id, $name, $variant, true, false, false, false, false, false, $unit);
	}

	private function published(Context $context): array {
		$context->publish();
		$exposures = [];
		foreach ($this->eventHandler->submitted as $event) {
			foreach ($event->exposures ?? [] as $e) {
				$exposures[] = [$e->id, $e->name, $e->unit, $e->variant, $e->assigned, $e->eligible, $e->overridden,
					$e->fullOn, $e->custom, $e->audienceMismatch, $e->ruleOverride];
			}
		}
		return $exposures;
	}

	private function assertPublished(Context $context, array ...$expected): void {
		self::assertSame($expected, $this->published($context));
	}

	private static function invoke(Context $context, string $method, ...$args) {
		$reflection = new \ReflectionMethod(Context::class, $method);
		if (PHP_VERSION_ID < 80100) {
			$reflection->setAccessible(true);
		}
		return $reflection->invoke($context, ...$args);
	}

	public function testHeldOutUnitGetsControlValuesAndEmitsNoExposureForCoveredExperiment(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_in'), 11)));

		self::assertSame(0, $context->peekTreatment('exp_holdout_in'));
		self::assertSame(0, $context->getTreatment('exp_holdout_in'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testNotHeldOutUnitEmitsHoldoutExposureVariantOneAndNormalExposure(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_out'), 11)), [self::UNIT_TYPE => self::UID_NOT_HELD_OUT]);

		self::assertSame(0, $context->getTreatment('exp_holdout_out'));
		$this->assertPublished($context, self::exposure(1, 'exp_holdout_out', 0), self::holdoutExposure(11, 'holdout_a', 1));
	}

	public function testExperimentWithoutHoldoutIdsEmitsNormallyEvenForHeldOutUnit(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_in'), 11), self::experiment(2, 'exp_not_covered')));

		self::assertSame(0, $context->getTreatment('exp_holdout_in'));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_not_covered'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0),
			self::exposure(2, 'exp_not_covered', self::NORMAL_VARIANT));
	}

	public function testUnionOfApplicableHoldoutsSuppressesExperimentAndBothEmitOwnExposure(): void {
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED),
			self::holdout(12, 'holdout_b', self::HOLDOUT_B_SEED),
		], self::coveredBy(self::experiment(1, 'exp_multi_holdout'), 11, 12)));

		self::assertSame(0, $context->getTreatment('exp_multi_holdout'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0), self::holdoutExposure(12, 'holdout_b', 1));
	}

	public function testUnionRuleAppliesWhenTheHigherIdHoldoutIsTheOnlyOneHoldingUnitOut(): void {
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_low_id', self::HOLDOUT_B_SEED),
			self::holdout(12, 'holdout_high_id', self::HOLDOUT_A_SEED),
		], self::coveredBy(self::experiment(1, 'exp_union_high_id_decides'), 11, 12)));

		self::assertSame(0, $context->getTreatment('exp_union_high_id_decides'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_low_id', 1),
			self::holdoutExposure(12, 'holdout_high_id', 0));
	}

	public function testUnitNotHeldOutByEitherHoldoutIsNotSuppressed(): void {
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED),
			self::holdout(12, 'holdout_b', self::HOLDOUT_B_SEED),
		], self::coveredBy(self::experiment(1, 'exp_multi_holdout_miss'), 11, 12)), [self::UNIT_TYPE => self::UID_NOT_HELD_OUT]);

		self::assertSame(0, $context->getTreatment('exp_multi_holdout_miss'));
		$this->assertPublished($context, self::exposure(1, 'exp_multi_holdout_miss', 0),
			self::holdoutExposure(11, 'holdout_a', 1), self::holdoutExposure(12, 'holdout_b', 1));
	}

	public function testHoldoutSuppressesFullOnExperimentRegardlessOfFullOnVariant(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_fullon_holdout', self::UNIT_TYPE, 2), 11)));

		self::assertSame(0, $context->getTreatment('exp_fullon_holdout'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testThreeArmVariantZeroHoldsOutBothFullOnAndNonFullOnExperiments(): void {
		$context = $this->createContext(self::data([self::threeArmHoldout(21, 'holdout_three_arm', self::THREE_ARM_0_SEED)],
			self::coveredBy(self::experiment(1, 'exp_three_arm_0_non_fullon'), 21),
			self::coveredBy(self::experiment(2, 'exp_three_arm_0_fullon', self::UNIT_TYPE, 2), 21)));

		self::assertSame(0, $context->getTreatment('exp_three_arm_0_non_fullon'));
		self::assertSame(0, $context->getTreatment('exp_three_arm_0_fullon'));
		$this->assertPublished($context, self::holdoutExposure(21, 'holdout_three_arm', 0));
	}

	public function testThreeArmVariantOneForcesNonFullOnExperimentToControlButAssignsFullOnExperimentNormally(): void {
		$context = $this->createContext(self::data([self::threeArmHoldout(21, 'holdout_three_arm', self::THREE_ARM_1_SEED)],
			self::coveredBy(self::experiment(1, 'exp_three_arm_1_non_fullon'), 21),
			self::coveredBy(self::experiment(2, 'exp_three_arm_1_fullon', self::UNIT_TYPE, 2), 21)));

		self::assertSame(0, $context->getTreatment('exp_three_arm_1_non_fullon'));
		self::assertSame(2, $context->getTreatment('exp_three_arm_1_fullon'));
		$this->assertPublished($context, self::holdoutExposure(21, 'holdout_three_arm', 1),
			self::exposure(2, 'exp_three_arm_1_fullon', 2, true, false, true));
	}

	public function testThreeArmVariantTwoEvaluatesBothExperimentKindsNormally(): void {
		$context = $this->createContext(self::data([self::threeArmHoldout(21, 'holdout_three_arm', self::THREE_ARM_2_SEED)],
			self::coveredBy(self::experiment(1, 'exp_three_arm_2_non_fullon'), 21),
			self::coveredBy(self::experiment(2, 'exp_three_arm_2_fullon', self::UNIT_TYPE, 2), 21)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_three_arm_2_non_fullon'));
		self::assertSame(2, $context->getTreatment('exp_three_arm_2_fullon'));
		$this->assertPublished($context, self::exposure(1, 'exp_three_arm_2_non_fullon', self::NORMAL_VARIANT),
			self::holdoutExposure(21, 'holdout_three_arm', 2), self::exposure(2, 'exp_three_arm_2_fullon', 2, true, false, true));
	}

	public function testTwoArmHoldoutWinsUnionEvenWhenThreeArmWouldNotHoldOut(): void {
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_two_arm', self::HOLDOUT_A_SEED),
			self::threeArmHoldout(22, 'holdout_three_arm', self::THREE_ARM_2_SEED),
		], self::coveredBy(self::experiment(1, 'exp_union_two_arm_wins'), 11, 22)));

		self::assertSame(0, $context->getTreatment('exp_union_two_arm_wins'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_two_arm', 0),
			self::holdoutExposure(22, 'holdout_three_arm', 2));
	}

	public function testThreeArmHoldoutWinsUnionEvenWhenTwoArmWouldNotHoldOut(): void {
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_two_arm', self::HOLDOUT_B_SEED),
			self::threeArmHoldout(22, 'holdout_three_arm', self::THREE_ARM_1_SEED),
		], self::coveredBy(self::experiment(1, 'exp_union_three_arm_wins'), 11, 22)));

		self::assertSame(0, $context->getTreatment('exp_union_three_arm_wins'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_two_arm', 1),
			self::holdoutExposure(22, 'holdout_three_arm', 1));
	}

	public function testThreeArmArmOneDefersToNormalPathSoAudienceMismatchStillWinsForFullOnExperiment(): void {
		$audience = '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}';
		foreach ([[self::THREE_ARM_1_SEED, 1], [self::THREE_ARM_2_SEED, 2]] as [$seed, $arm]) {
			$experiment = self::coveredBy(self::experiment(1, 'exp_three_arm_audience_fullon', self::UNIT_TYPE, 2), 21);
			$experiment->audienceStrict = true;
			$experiment->audience = $audience;
			$context = $this->createContext(self::data([self::threeArmHoldout(21, 'holdout_three_arm', $seed)], $experiment));
			$context->setAttribute('age', 5);

			self::assertSame(0, $context->getTreatment('exp_three_arm_audience_fullon'));
			$this->assertPublished($context, self::exposure(1, 'exp_three_arm_audience_fullon', 0, false, false, false, false, true),
				self::holdoutExposure(21, 'holdout_three_arm', $arm));
		}
	}

	public function testUnconfiguredUnitTypeGetsControlValuesAndEmitsNoExposureAtAll(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_user', self::HOLDOUT_A_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_user_holdout', 'user_id'), 11)));

		self::assertSame(0, $context->peekTreatment('exp_user_holdout'));
		self::assertSame(0, $context->getTreatment('exp_user_holdout'));
		self::assertSame(1, $context->getPendingCount());
		// The experiment's own exposure (unassigned, the unit is missing) is the only one: the holdout can't fire.
		$this->assertPublished($context, self::exposure(1, 'exp_user_holdout', 0, false, false, false, false, false, false, 'user_id'));
	}

	public function testAssignsNormallyWhenHoldoutsKeyIsAbsent(): void {
		$context = $this->createContext(new ContextData([self::experiment(1, 'exp_no_holdouts_key')]));

		self::assertSame(self::NORMAL_VARIANT, $context->peekTreatment('exp_no_holdouts_key'));
	}

	public function testHoldoutIdAbsentFromHoldoutsArrayIsIgnoredAndExperimentAssignsNormally(): void {
		$context = $this->createContext(self::data([], self::coveredBy(self::experiment(1, 'exp_dangling_holdout_id'), 999)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_dangling_holdout_id'));
		$this->assertPublished($context, self::exposure(1, 'exp_dangling_holdout_id', self::NORMAL_VARIANT));
	}

	public function testCustomAssignmentCannotOverrideHeldOutVariant(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_custom'), 11)), [self::UNIT_TYPE => self::UID], [], ['exp_holdout_custom' => 3]);

		self::assertSame(0, $context->peekTreatment('exp_holdout_custom'));
	}

	public function testReusesCachedHeldOutAssignmentWithCustomAssignment(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_custom_cache'), 11)), [self::UNIT_TYPE => self::UID], [], ['exp_holdout_custom_cache' => 3]);

		self::assertSame(0, $context->getTreatment('exp_holdout_custom_cache'));
		self::assertSame(0, $context->getTreatment('exp_holdout_custom_cache'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testOverrideTakesPrecedenceOverHoldout(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_override'), 11)), [self::UNIT_TYPE => self::UID], ['exp_holdout_override' => 3]);

		self::assertSame(3, $context->peekTreatment('exp_holdout_override'));
	}

	public function testGetTreatmentOnOverriddenExperimentTriggersApplicableHoldoutExposureBothArms(): void {
		foreach ([[self::UID, 0], [self::UID_NOT_HELD_OUT, 1]] as [$uid, $arm]) {
			$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
				self::coveredBy(self::experiment(1, 'exp_holdout_override'), 11)), [self::UNIT_TYPE => $uid], ['exp_holdout_override' => 3]);

			self::assertSame(3, $context->getTreatment('exp_holdout_override'));
			$this->assertPublished($context, self::exposure(1, 'exp_holdout_override', 3, false, true),
				self::holdoutExposure(11, 'holdout_a', $arm));
		}
	}

	public function testHoldoutTakesPrecedenceOverAudienceMismatch(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_holdout_audience'), 11);
		$experiment->audienceStrict = true;
		$experiment->audience = '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment));
		$context->setAttribute('age', 5);

		self::assertSame(0, $context->getTreatment('exp_holdout_audience'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testHoldoutExposureEmittedOncePerContextNotPerSuppressedExperiment(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_shared_a'), 11), self::coveredBy(self::experiment(2, 'exp_shared_b'), 11)));

		self::assertSame(0, $context->getTreatment('exp_shared_a'));
		self::assertSame(0, $context->getTreatment('exp_shared_b'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testHoldoutExposureFiresOnceEvenWhenTriggeringExperimentIsNotSuppressed(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_shared_a'), 11), self::coveredBy(self::experiment(2, 'exp_shared_b'), 11)),
			[self::UNIT_TYPE => self::UID_NOT_HELD_OUT]);

		$context->getTreatment('exp_shared_a');
		$context->getTreatment('exp_shared_b');
		$this->assertPublished($context, self::exposure(1, 'exp_shared_a', 0), self::holdoutExposure(11, 'holdout_a', 1),
			self::exposure(2, 'exp_shared_b', 0));
	}

	public function testReusesCachedSuppressedAssignmentAcrossRepeatedCalls(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_cache'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_cache'));
		self::assertSame(0, $context->getTreatment('exp_holdout_cache'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testRefreshWithSeedChangeSameIterationKeepsUnitInOriginalArmAndDoesNotReExpose(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_seed_thrash'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_seed_thrash'));
		self::assertSame(1, $context->getPendingCount());

		$this->refresh($context, self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_seed_thrash'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_seed_thrash'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testRefreshWithCosmeticHoldoutEditDoesNotReExposeHeldOutUnit(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_cosmetic'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_cosmetic'));
		self::assertSame(1, $context->getPendingCount());

		$cosmetic = self::holdout(11, 'holdout_a_renamed', self::HOLDOUT_A_SEED);
		$cosmetic->applications = [(object) ['name' => 'website']];
		$cosmetic->variants = [(object) ['name' => 'Control', 'config' => null], (object) ['name' => 'HeldOut', 'config' => null]];
		$this->refresh($context, self::data([$cosmetic], self::coveredBy(self::experiment(1, 'exp_holdout_cosmetic'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_cosmetic'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testRefreshWithCosmeticHoldoutEditDoesNotDuplicateCoveredExperimentExposure(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_cosmetic_covered'), 11)), [self::UNIT_TYPE => self::UID_NOT_HELD_OUT]);

		self::assertSame(0, $context->getTreatment('exp_holdout_cosmetic_covered'));
		self::assertSame(2, $context->getPendingCount());

		$this->refresh($context, self::data([self::holdout(11, 'holdout_a_renamed', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_cosmetic_covered'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_cosmetic_covered'));
		self::assertSame(2, $context->getPendingCount());
	}

	public function testRefreshWithIterationBumpReassignsToExactlyOneNewArm(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_iteration'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_holdout_iteration'));
		self::assertSame(2, $context->getPendingCount());

		$newEpoch = self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED);
		$newEpoch->iteration = 2;
		$this->refresh($context, self::data([$newEpoch], self::coveredBy(self::experiment(1, 'exp_holdout_iteration'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_iteration'));
		self::assertSame(3, $context->getPendingCount());
		self::assertSame(0, $context->getTreatment('exp_holdout_iteration'));
		self::assertSame(3, $context->getPendingCount());
		$this->assertPublished($context, self::exposure(1, 'exp_holdout_iteration', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_a', 1), self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testRefreshedSeedDoesNotDesyncSuppressionFromPinnedHoldoutArmForNewlyEvaluatedExperiment(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_desync_a'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_desync_a'));

		$this->refresh($context, self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_holdout_desync_a'), 11), self::coveredBy(self::experiment(2, 'exp_holdout_desync_b'), 11)));

		self::assertSame(0, $context->getTreatment('exp_holdout_desync_b'));
		self::assertSame(0, $context->getTreatment('exp_holdout_desync_a'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testRefreshFromTwoArmToThreeArmSameIterationDoesNotReinterpretDeferringArmAsFullOnOnly(): void {
		$context = $this->createContext(self::data([self::holdout(31, 'holdout_arity', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_arity_2to3_a'), 31)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_arity_2to3_a'));

		$this->refresh($context, self::data([self::threeArmHoldout(31, 'holdout_arity', self::THREE_ARM_1_SEED)],
			self::coveredBy(self::experiment(1, 'exp_arity_2to3_a'), 31), self::coveredBy(self::experiment(2, 'exp_arity_2to3_b'), 31)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_arity_2to3_b'));
		$this->assertPublished($context, self::exposure(1, 'exp_arity_2to3_a', self::NORMAL_VARIANT),
			self::holdoutExposure(31, 'holdout_arity', 1), self::exposure(2, 'exp_arity_2to3_b', self::NORMAL_VARIANT));
	}

	public function testRefreshFromThreeArmToTwoArmSameIterationKeepsFullOnOnlyArmSuppressingNonFullOnExperiment(): void {
		$context = $this->createContext(self::data([self::threeArmHoldout(41, 'holdout_arity_rev', self::THREE_ARM_1_SEED)],
			self::coveredBy(self::experiment(1, 'exp_arity_3to2_a'), 41)));

		self::assertSame(0, $context->getTreatment('exp_arity_3to2_a'));
		self::assertSame(1, $context->getPendingCount());

		$this->refresh($context, self::data([self::holdout(41, 'holdout_arity_rev', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_arity_3to2_a'), 41), self::coveredBy(self::experiment(2, 'exp_arity_3to2_b'), 41)));

		self::assertSame(0, $context->getTreatment('exp_arity_3to2_b'));
		$this->assertPublished($context, self::holdoutExposure(41, 'holdout_arity_rev', 1));
	}

	public function testExperimentsEvaluatedBeforeAndAfterSameIterationArityChangeAgreeOnSuppression(): void {
		$context = $this->createContext(self::data([self::holdout(71, 'holdout_arity_consistency', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_arity_consistency_pre'), 71)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_arity_consistency_pre'));

		$this->refresh($context, self::data([self::threeArmHoldout(71, 'holdout_arity_consistency', self::THREE_ARM_1_SEED)],
			self::coveredBy(self::experiment(1, 'exp_arity_consistency_pre'), 71),
			self::coveredBy(self::experiment(2, 'exp_arity_consistency_post'), 71)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_arity_consistency_post'));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_arity_consistency_pre'));
	}

	public function testTriggerFiresHoldoutExposureFromDecisionEpochDespiteRefreshRacingBetweenDecisionAndTrigger(): void {
		$iteration2 = self::holdout(11, 'holdout_h', self::HOLDOUT_A_SEED);
		$iteration2->iteration = 2;
		$refreshed = self::data([$iteration2], self::coveredBy(self::experiment(1, 'exp_epoch_race'), 11));

		$context = null;
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function ($exposure) use (&$context, $refreshed): bool {
			if ($exposure->id === 1) {
				$this->refresh($context, $refreshed);
			}
			return false;
		};
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_h', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_epoch_race'), 11)), [self::UNIT_TYPE => self::UID], [], [], $logger);

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_epoch_race'));
		$this->assertPublished($context, self::exposure(1, 'exp_epoch_race', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_h', 1));
	}

	public function testSetUnitInvalidatesNullHoldoutSnapshotSoTheHoldoutExposureIsNotLostForever(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_user_id', self::HOLDOUT_B_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_null_snapshot_fullon', 'user_id', 2), 11)), []);

		self::assertSame(2, $context->peekTreatment('exp_null_snapshot_fullon'));
		$context->setUnit('user_id', self::UID);

		self::assertSame(2, $context->getTreatment('exp_null_snapshot_fullon'));
		self::assertSame(2, $context->getPendingCount());
		$this->assertPublished($context, self::exposure(1, 'exp_null_snapshot_fullon', 2, true, false, true, false, false, false, 'user_id'),
			self::holdoutExposure(11, 'holdout_user_id', 1, 'user_id'));
	}

	public function testSetUnitDoesNotEvictAlreadyExposedAssignmentSoNoDuplicateExposure(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_user_id', self::HOLDOUT_B_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_exposed_before_unit', 'user_id', 2), 11)), []);

		self::assertSame(2, $context->getTreatment('exp_exposed_before_unit'));
		self::assertSame(1, $context->getPendingCount());
		$exposed = self::invoke($context, 'getAssignment', 'exp_exposed_before_unit');

		$context->setUnit('user_id', self::UID);

		self::assertSame(2, $context->getTreatment('exp_exposed_before_unit'));
		self::assertSame(1, $context->getPendingCount());
		self::assertSame($exposed, self::invoke($context, 'getAssignment', 'exp_exposed_before_unit'));
	}

	public function testSetUnitForUnrelatedUnitTypeDoesNotEvictExposedAssignment(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_unrelated_unit'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_unrelated_unit'));
		self::assertSame(2, $context->getPendingCount());

		$context->setUnit('other_unit_type', 'some-other-uid');

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_unrelated_unit'));
		self::assertSame(2, $context->getPendingCount());
	}

	public function testSetUnitEvictsOnlyTheAffectedEntryAndLeavesTheOtherUntouched(): void {
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_late', self::HOLDOUT_B_SEED, 'user_id'),
			self::holdout(12, 'holdout_present', self::HOLDOUT_B_SEED),
		], self::coveredBy(self::experiment(1, 'exp_late_unit', 'user_id', 2), 11), self::coveredBy(self::experiment(2, 'exp_present_unit'), 12)));

		self::assertSame(2, $context->peekTreatment('exp_late_unit'));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_present_unit'));
		self::assertSame(2, $context->getPendingCount());

		$context->setUnit('user_id', self::UID);

		self::assertSame(2, $context->getTreatment('exp_late_unit'));
		self::assertSame(4, $context->getPendingCount());
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_present_unit'));
		self::assertSame(4, $context->getPendingCount());
	}

	public function testSetUnitEvictsUsingTheCoveredExperimentsUnitTypeNotTheHoldoutsDeclaredUnitType(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_declares_b', self::HOLDOUT_B_SEED, 'B')],
			self::coveredBy(self::experiment(1, 'exp_mismatched_unit_type', 'A', 2), 11)), []);

		self::assertSame(2, $context->peekTreatment('exp_mismatched_unit_type'));
		$context->setUnit('A', self::UID);

		self::assertSame(2, $context->getTreatment('exp_mismatched_unit_type'));
		self::assertSame(2, $context->getPendingCount());
	}

	public function testSetUnitRecomputesSuppressionCoherentlyWhenLateResolvedHoldoutSuppresses(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_user_id_suppress', self::HOLDOUT_A_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_null_snapshot_suppresses', 'user_id', 2), 11)), []);

		self::assertSame(2, $context->peekTreatment('exp_null_snapshot_suppresses'));
		$context->setUnit('user_id', self::UID);

		self::assertSame(0, $context->getTreatment('exp_null_snapshot_suppresses'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_user_id_suppress', 0, 'user_id'));
	}

	// Java's detached-assignment and exposure-settlement tests cover a thread race between resolving an assignment
	// and exposing it. PHP has no such window, so these pin the same outcome through the public API: the decision
	// that is exposed is the one made after the late unit, never the evicted one.
	public function testDetachedTreatmentAssignmentCannotPublishBesideSuppressingReplacement(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_detached_suppressed', self::HOLDOUT_A_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_detached_suppressed', 'user_id', 2), 11)), []);

		self::assertSame(2, $context->peekTreatment('exp_detached_suppressed'));
		$context->setUnit('user_id', self::UID);
		self::assertSame(0, $context->getTreatment('exp_detached_suppressed'));
		self::assertSame(0, $context->getTreatment('exp_detached_suppressed'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_detached_suppressed', 0, 'user_id'));
	}

	public function testDetachedTreatmentAssignmentRetriesCurrentEntryWithoutLosingExposure(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_detached_retry', self::HOLDOUT_B_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_detached_retry', 'user_id', 2), 11)), []);

		self::assertSame(2, $context->peekTreatment('exp_detached_retry'));
		$context->setUnit('user_id', self::UID);
		self::assertSame(2, $context->getTreatment('exp_detached_retry'));
		self::assertSame(2, $context->getTreatment('exp_detached_retry'));
		$this->assertPublished($context, self::exposure(1, 'exp_detached_retry', 2, true, false, true, false, false, false, 'user_id'),
			self::holdoutExposure(11, 'holdout_detached_retry', 1, 'user_id'));
	}

	public function testDetachedTreatmentReturnsTheReplacementVariant(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_detached_variant', self::HOLDOUT_A_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_detached_variant', 'user_id', 2), 11)), []);

		$retained = self::invoke($context, 'getAssignment', 'exp_detached_variant');
		$context->setUnit('user_id', self::UID);

		self::assertNotSame($retained, self::invoke($context, 'getAssignment', 'exp_detached_variant'));
		self::assertSame(0, $context->getTreatment('exp_detached_variant'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testTreatmentExposureSettlesAfterTwoConsecutiveRetirements(): void {
		$context = $this->createContext(self::data([], self::experiment(1, 'exp_first', self::UNIT_TYPE, 2)));
		$second = self::experiment(1, 'exp_first', self::UNIT_TYPE, 1);
		$second->iteration = 2;
		$settled = self::experiment(1, 'exp_first', self::UNIT_TYPE, 1);
		$settled->iteration = 3;

		self::assertSame(2, $context->peekTreatment('exp_first'));
		$this->refresh($context, self::data([], $second));
		self::assertSame(1, $context->peekTreatment('exp_first'));
		$this->refresh($context, self::data([], $settled));

		self::assertSame(1, $context->getTreatment('exp_first'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testRepeatedPeeksQueueNoExposure(): void {
		$context = $this->createContext(self::data([], self::experiment(1, 'exp_bounded_retry', self::UNIT_TYPE, 1)));

		self::assertSame(1, $context->peekTreatment('exp_bounded_retry'));
		self::assertSame(1, $context->peekTreatment('exp_bounded_retry'));
		self::assertSame(0, $context->getPendingCount());
	}

	public function testDetachedVariableAssignmentReresolvesTheKeyToANewWinningExperiment(): void {
		$original = self::coveredBy(self::experiment(2, 'exp_detached_variable_old', 'user_id', 1), 11);
		$original->variants[1]->config = '{"detached_var":"old"}';
		$holdout = self::holdout(11, 'holdout_detached_variable', self::HOLDOUT_B_SEED, 'user_id');
		$context = $this->createContext(self::data([$holdout], $original), []);

		self::assertSame('old', $context->peekVariableValue('detached_var', 'default'));
		$context->setUnit('user_id', self::UID);

		$winner = self::coveredBy(self::experiment(1, 'exp_detached_variable_winner', 'user_id', 1), 11);
		$winner->variants[1]->config = '{"detached_var":"new winner"}';
		$this->refresh($context, self::data([$holdout], $winner, $original));

		self::assertSame('new winner', $context->getVariableValue('detached_var', 'default'));
		self::assertSame(2, $context->getPendingCount());
		$this->assertPublished($context, self::exposure(1, 'exp_detached_variable_winner', 1, true, false, true, false, false, false, 'user_id'),
			self::holdoutExposure(11, 'holdout_detached_variable', 1, 'user_id'));
	}

	public function testTriggerApplicableHoldoutExposuresNeverLiveResolvesAPinnedNullEntryForAnAlreadyExposedAssignment(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_a_mutant_pinned_null', 'user_id', 1), 12);
		$experiment->variants[1]->config = '{"my_var":"value_from_variant"}';
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_dropped_arm', self::HOLDOUT_B_SEED),
			self::holdout(12, 'holdout_a_mutant_pinned_null', self::HOLDOUT_A_SEED, 'user_id'),
		], $experiment, self::coveredBy(self::experiment(2, 'exp_dropped_arm'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_dropped_arm'));
		self::assertSame(1, $context->getTreatment('exp_a_mutant_pinned_null'));
		self::assertSame(3, $context->getPendingCount());

		// The cap drops exp_dropped_arm and its holdout arm, so exposed reads take the retry path from here on.
		$context->maxRetainedEvents = 1;
		$this->eventHandler->prerun = static function (): void {
			throw new \RuntimeException('collector down');
		};
		$context->publish();
		$this->eventHandler->prerun = null;
		self::assertSame(1, $context->getPendingCount());

		$context->setUnit('user_id', self::UID);

		self::assertSame(1, $context->getTreatment('exp_a_mutant_pinned_null'));
		self::assertSame('value_from_variant', $context->getVariableValue('my_var', 'default'));
		self::assertSame(1, $context->getPendingCount());
		$this->assertPublished($context, self::exposure(1, 'exp_a_mutant_pinned_null', 1, true, false, true, false, false, false, 'user_id'));
	}

	public function testSetUnitEvictsAssignmentWithMultipleHoldoutsDeclaringDifferentUnitTypes(): void {
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_11', self::HOLDOUT_B_SEED, 'type_x'),
			self::holdout(12, 'holdout_12', self::HOLDOUT_B_SEED, 'type_y'),
			self::holdout(13, 'holdout_13', self::HOLDOUT_B_SEED, 'user_id'),
		], self::coveredBy(self::experiment(1, 'exp_multi_holdout_unit_types', 'user_id', 2), 11, 12, 13)), []);

		self::assertSame(2, $context->peekTreatment('exp_multi_holdout_unit_types'));
		$context->setUnit('user_id', self::UID);

		self::assertSame(2, $context->getTreatment('exp_multi_holdout_unit_types'));
		self::assertSame(4, $context->getPendingCount());
	}

	public function testOneHoldoutCoveringTwoUnitTypesKeepsIndependentExposureStatePerUnitType(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_shared', self::HOLDOUT_B_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_unit_a1', 'user_id'), 11),
			self::coveredBy(self::experiment(2, 'exp_unit_b', 'session_id'), 11),
			self::coveredBy(self::experiment(3, 'exp_unit_a2', 'user_id'), 11)), ['user_id' => self::UID, 'session_id' => self::UID]);

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_unit_a1'));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_unit_b'));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_unit_a2'));
		self::assertSame(5, $context->getPendingCount());

		$this->assertPublished($context,
			self::exposure(1, 'exp_unit_a1', self::NORMAL_VARIANT, true, false, false, false, false, false, 'user_id'),
			self::holdoutExposure(11, 'holdout_shared', 1, 'user_id'),
			self::exposure(2, 'exp_unit_b', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_shared', 1, 'session_id'),
			self::exposure(3, 'exp_unit_a2', self::NORMAL_VARIANT, true, false, false, false, false, false, 'user_id'));
	}

	public function testSetUnitWithNoCachedAssignmentDoesNotThrowAndSubsequentAssignmentIsNormal(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_no_prior_assignment'), 11)), []);

		$context->setUnit(self::UNIT_TYPE, self::UID);
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_no_prior_assignment'));
	}

	public function testSetUnitCalledTwiceWithSameUidIsANoOpAndDoesNotThrow(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_set_unit_twice'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_set_unit_twice'));
		self::assertSame(2, $context->getPendingCount());

		$context->setUnit(self::UNIT_TYPE, self::UID);

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_set_unit_twice'));
		self::assertSame(2, $context->getPendingCount());
	}

	public function testSetUnitRedundantCallDoesNotEvictFullyResolvedUnexposedHoldoutSnapshot(): void {
		$holdout = self::holdout(11, 'holdout_a', self::HOLDOUT_B_SEED);
		$context = $this->createContext(self::data([$holdout], self::coveredBy(self::experiment(1, 'exp_redundant_set_unit'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->peekTreatment('exp_redundant_set_unit'));
		self::assertSame(0, $context->getPendingCount());

		$refreshed = self::coveredBy(self::experiment(1, 'exp_redundant_set_unit'), 11);
		$refreshed->split = [1.0, 0.0];
		$this->refresh($context, self::data([$holdout], $refreshed));

		$context->setUnit(self::UNIT_TYPE, self::UID);

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_redundant_set_unit'));
		$this->assertPublished($context, self::exposure(1, 'exp_redundant_set_unit', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_a', 1));
	}

	public function testPeekVariableValueThenSetUnitThenGetVariableValueRecomputesUnderLateSuppressingHoldout(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_var_late_unit_suppress', 'user_id', 1), 11);
		$experiment->variants[1]->config = '{"my_var":"value_from_variant"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_var_late', self::HOLDOUT_A_SEED, 'user_id')],
			$experiment), []);

		self::assertSame('value_from_variant', $context->peekVariableValue('my_var', 'default'));
		self::assertSame(0, $context->getPendingCount());

		$context->setUnit('user_id', self::UID);

		// Suppressed: the control variant has no "my_var", so the caller's default is returned.
		self::assertSame('default', $context->getVariableValue('my_var', 'default'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_var_late', 0, 'user_id'));
	}

	public function testSetUnitDoesNotEvictOverrideAssignmentAndHoldoutResolvesAfterTheUnitIsSet(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_override_late', self::HOLDOUT_B_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_override_late_unit', 'user_id'), 11)), [], ['exp_override_late_unit' => 3]);

		self::assertSame(3, $context->peekTreatment('exp_override_late_unit'));
		$context->setUnit('user_id', self::UID);

		self::assertSame(3, $context->getTreatment('exp_override_late_unit'));
		self::assertSame(2, $context->getPendingCount());
		$this->assertPublished($context, self::exposure(1, 'exp_override_late_unit', 3, false, true, false, false, false, false, 'user_id'),
			self::holdoutExposure(11, 'holdout_override_late', 1, 'user_id'));
	}

	public function testSetUnitEvictsNullSnapshotForCustomAssignmentAndCustomAppliesAfterRecompute(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_custom_late', self::HOLDOUT_B_SEED, 'user_id')],
			self::coveredBy(self::experiment(1, 'exp_custom_late_unit', 'user_id'), 11)), [], [], ['exp_custom_late_unit' => 3]);

		self::assertSame(0, $context->peekTreatment('exp_custom_late_unit'));
		$context->setUnit('user_id', self::UID);

		self::assertSame(3, $context->getTreatment('exp_custom_late_unit'));
		$this->assertPublished($context, self::exposure(1, 'exp_custom_late_unit', 3, true, false, false, true, false, false, 'user_id'),
			self::holdoutExposure(11, 'holdout_custom_late', 1, 'user_id'));
	}

	private function verdictContext(string $uid, object ...$holdouts): Context {
		$ids = array_map(fn($holdout) => $holdout->id, $holdouts);
		return $this->createContext(self::data($holdouts,
			self::coveredBy(self::experiment(9, 'verdict_vectors_experiment', 'verdict_unit_type'), ...$ids)), ['verdict_unit_type' => $uid]);
	}

	private static function verdictHoldout(int $id, int $seedHi, int $seedLo, array $split): object {
		$holdout = self::holdout($id, 'holdout_' . $id, [$seedHi, $seedLo], 'verdict_unit_type');
		$holdout->split = $split;
		return $holdout;
	}

	private function assertHeldOutBy(Context $context, int $holdoutId): void {
		self::assertSame(0, $context->getTreatment('verdict_vectors_experiment'));
		$this->assertPublished($context, self::holdoutExposure($holdoutId, 'holdout_' . $holdoutId, 0, 'verdict_unit_type'));
	}

	private function assertNotHeldOut(Context $context, int $holdoutId): void {
		$context->getTreatment('verdict_vectors_experiment');
		self::assertContains(self::holdoutExposure($holdoutId, 'holdout_' . $holdoutId, 1, 'verdict_unit_type'), $this->published($context));
	}

	public function testUnicodeUnitIds(): void {
		$this->assertHeldOutBy($this->verdictContext('façade', self::verdictHoldout(107, 0, 42, [0.1, 0.9])), 107);
		$this->assertNotHeldOut($this->verdictContext("unicode_emoji_\u{1F680}_0", self::verdictHoldout(107, 0, 42, [0.1, 0.9])), 107);
	}

	public function testSignedSeedHalves(): void {
		// seed 0x8000000080000000: the sign bit is set in both 32-bit halves.
		$seedHi = -2147483648;
		$seedLo = -2147483648;
		$this->assertHeldOutBy($this->verdictContext('signed_unit_4', self::verdictHoldout(106, $seedHi, $seedLo, [0.1, 0.9])), 106);
		$this->assertNotHeldOut($this->verdictContext('signed_unit_b_1', self::verdictHoldout(106, $seedHi, $seedLo, [0.1, 0.9])), 106);
	}

	public function testPercentageBoundaryAtOnePercent(): void {
		$this->assertHeldOutBy($this->verdictContext('pct1_unit_188', self::verdictHoldout(105, 0, 999999, [0.01, 0.99])), 105);
		$this->assertNotHeldOut($this->verdictContext('pct1_unit_0', self::verdictHoldout(105, 0, 999999, [0.01, 0.99])), 105);
	}

	public function testAssignmentProbabilityBoundaryAroundTenPercent(): void {
		$this->assertHeldOutBy($this->verdictContext('boundary_unit_175653', self::verdictHoldout(107, 0, 42, [0.1, 0.9])), 107);
		$this->assertNotHeldOut($this->verdictContext('boundary_unit_75792', self::verdictHoldout(107, 0, 42, [0.1, 0.9])), 107);
	}

	public function testUnitHeldOutByTwoHoldoutsSimultaneously(): void {
		$context = $this->verdictContext('dual_holdout_unit_5', self::verdictHoldout(107, 0, 42, [0.1, 0.9]),
			self::verdictHoldout(108, 0, 55, [0.1, 0.9]));

		self::assertSame(0, $context->getTreatment('verdict_vectors_experiment'));
		$this->assertPublished($context, self::holdoutExposure(107, 'holdout_107', 0, 'verdict_unit_type'),
			self::holdoutExposure(108, 'holdout_108', 0, 'verdict_unit_type'));
	}

	public function testVariableLookupFiresHoldoutExposureForBothHeldOutAndNonHeldOutUnits(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_var'), 11);
		$experiment->variants[1]->config = '{"var_a":"value_a"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment));

		self::assertSame('default', $context->getVariableValue('var_a', 'default'));
		self::assertSame(1, $context->getPendingCount());

		$experiment = self::coveredBy(self::experiment(1, 'exp_var'), 11);
		$experiment->variants[1]->config = '{"var_a":"value_a"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment),
			[self::UNIT_TYPE => self::UID_NOT_HELD_OUT]);

		self::assertSame('default', $context->getVariableValue('var_a', 'default'));
		self::assertSame(2, $context->getPendingCount());
	}

	public function testVariableKeyResolutionSkipsSuppressedAssignmentInFavorOfAssignedOne(): void {
		$suppressed = self::coveredBy(self::experiment(1, 'exp_var_key_suppressed'), 11);
		$suppressed->variants[1]->config = '{"shared":"from_suppressed"}';
		$assigned = self::experiment(2, 'exp_var_key_assigned');
		$assigned->variants[1]->config = '{"shared":"from_assigned"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $suppressed, $assigned));

		self::assertSame('from_assigned', $context->getVariableValue('shared', 'default'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0),
			self::exposure(2, 'exp_var_key_assigned', self::NORMAL_VARIANT));
	}

	public function testPeekVariableValueNeverTriggersHoldoutExposureForEvaluatedCandidates(): void {
		$suppressed = self::coveredBy(self::experiment(1, 'exp_var_key_peek_suppressed'), 11);
		$suppressed->variants[1]->config = '{"shared_peek":"from_suppressed"}';
		$assigned = self::experiment(2, 'exp_var_key_peek_assigned');
		$assigned->variants[1]->config = '{"shared_peek":"from_assigned"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $suppressed, $assigned));

		self::assertSame('from_assigned', $context->peekVariableValue('shared_peek', 'default'));
		self::assertSame(0, $context->getPendingCount());
	}

	public function testThrowingLoggerDoesNotPermanentlyLoseSiblingHoldoutExposure(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 11;
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED),
			self::holdout(12, 'holdout_b', self::HOLDOUT_B_SEED),
		], self::coveredBy(self::experiment(1, 'exp_multi_holdout_throwing_logger'), 11, 12)), [self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getTreatment('exp_multi_holdout_throwing_logger');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(2, $context->getPendingCount());
	}

	// A lower-id variable candidate's throwing holdout logger must not stop the walk: the later candidate's own and
	// holdout exposures are still queued and the first logger error reaches the caller (Java).
	public function testThrowingHoldoutLoggerOnEarlierVariableCandidateStillQueuesLaterCandidates(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 11;
		$suppressed = self::coveredBy(self::experiment(1, 'exp_var_throwing_suppressed'), 11);
		$suppressed->variants[1]->config = '{"shared_throw":"from_suppressed"}';
		$assigned = self::coveredBy(self::experiment(2, 'exp_var_throwing_assigned'), 12);
		$assigned->variants[1]->config = '{"shared_throw":"from_assigned"}';
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED),
			self::holdout(12, 'holdout_b', self::HOLDOUT_B_SEED),
		], $suppressed, $assigned), [self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getVariableValue('shared_throw', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}
		$logger->throwWhen = fn($exposure) => false;

		self::assertSame(3, $context->getPendingCount());
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0),
			self::exposure(2, 'exp_var_throwing_assigned', self::NORMAL_VARIANT), self::holdoutExposure(12, 'holdout_b', 1));
	}

	// The first error wins when a later candidate's logger throws too.
	public function testFirstVariableCandidateLoggerErrorWinsOverALaterOne(): void {
		$logger = new ThrowingExposureLogger();
		$suppressed = self::coveredBy(self::experiment(1, 'exp_var_first_error'), 11);
		$suppressed->variants[1]->config = '{"shared_first":"from_suppressed"}';
		$assigned = self::experiment(2, 'exp_var_second_error');
		$assigned->variants[1]->config = '{"shared_first":"from_assigned"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $suppressed,
			$assigned), [self::UNIT_TYPE => self::UID], [], [], $logger);
		$first = null;
		$logger->throwWhen = function($exposure) use (&$first) {
			if ($first === null) {
				$first = $exposure->id;
				throw new \LogicException('first');
			}
			return true;
		};

		try {
			$context->getVariableValue('shared_first', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\Throwable $exception) {
			self::assertSame(11, $first);
			self::assertInstanceOf(\LogicException::class, $exception);
		}
		self::assertSame(2, $context->getPendingCount());
	}

	// With no winner the walk ends on the suppressed fallback, and the deferred logger error still reaches the caller.
	public function testDeferredVariableCandidateLoggerErrorIsThrownWhenNoCandidateWins(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 11;
		$suppressed = self::coveredBy(self::experiment(1, 'exp_var_no_winner'), 11);
		$suppressed->variants[1]->config = '{"shared_none":"from_suppressed"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $suppressed),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getVariableValue('shared_none', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(1, $context->getPendingCount());
	}

	/** A first candidate covered by holdout 12 whose UID variant (1) lacks $key, and a later candidate that has it. */
	private static function candidateWithoutKeyThenOneWithKey(string $key): array {
		$first = self::coveredBy(self::experiment(1, 'exp_var_first_' . $key), 12);
		$first->variants[0]->config = json_encode([$key => 'from_first_control']);
		$first->variants[1]->config = '{"unrelated":"x"}';
		$second = self::experiment(2, 'exp_var_second_' . $key);
		$second->variants[1]->config = json_encode([$key => 'from_second']);
		return [$first, $second];
	}

	// A candidate covered by a holdout that does not hold the unit out also defers the error and keeps walking.
	public function testThrowingHoldoutLoggerOnCoveredNotHeldOutVariableCandidateKeepsWalking(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 12;
		[$first, $second] = self::candidateWithoutKeyThenOneWithKey('covered_assigned');
		$context = $this->createContext(self::data([self::holdout(12, 'holdout_b', self::HOLDOUT_B_SEED)], $first, $second),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getVariableValue('covered_assigned', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(3, $context->getPendingCount());
	}

	// An overridden covered candidate still fires its holdout exposures, so it defers the error and keeps walking too.
	public function testThrowingHoldoutLoggerOnOverriddenCoveredVariableCandidateKeepsWalking(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 12;
		[$first, $second] = self::candidateWithoutKeyThenOneWithKey('covered_overridden');
		$context = $this->createContext(self::data([self::holdout(12, 'holdout_b', self::HOLDOUT_B_SEED)], $first, $second),
			[self::UNIT_TYPE => self::UID], ['exp_var_first_covered_overridden' => 1], [], $logger);

		try {
			$context->getVariableValue('covered_overridden', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(3, $context->getPendingCount());
	}

	// Any Throwable is deferred, not only an Exception: a logger TypeError still lets later candidates be queued.
	public function testThrowingHoldoutLoggerErrorIsDeferredOnTheVariablePath(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function($exposure) {
			if ($exposure->id === 12) {
				throw new \TypeError('logger type error');
			}
			return false;
		};
		[$first, $second] = self::candidateWithoutKeyThenOneWithKey('covered_type_error');
		$context = $this->createContext(self::data([self::holdout(12, 'holdout_b', self::HOLDOUT_B_SEED)], $first, $second),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getVariableValue('covered_type_error', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\TypeError $exception) {
			self::assertSame('logger type error', $exception->getMessage());
		}

		self::assertSame(3, $context->getPendingCount());
	}

	// A logger Error on the experiment's own exposure still lets its holdout's exposure be queued.
	public function testThrowingOwnExposureErrorStillQueuesTheHoldoutExposure(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function($exposure) {
			if ($exposure->id === 1) {
				throw new \TypeError('logger type error');
			}
			return false;
		};
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_b', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_own_type_error'), 11)), [self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getTreatment('exp_own_type_error');
			self::fail('the logger error must reach the caller');
		}
		catch (\TypeError $exception) {
			self::assertSame('logger type error', $exception->getMessage());
		}

		$this->assertPublished($context, self::exposure(1, 'exp_own_type_error', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_b', 1));
	}

	// Data without holdouts never consults the holdout set on a cached read (the cheap path): a set planted behind the
	// flag's back would otherwise rebuild the assignment.
	public function testCachedReadWithoutHoldoutsSkipsTheHoldoutSetCheck(): void {
		$context = $this->createContext(self::data([], self::experiment(1, 'exp_no_holdouts_cached')));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_no_holdouts_cached'));
		$cache = new \ReflectionProperty(Context::class, 'assignmentCache');
		$holdouts = new \ReflectionProperty(Context::class, 'experimentHoldouts');
		if (PHP_VERSION_ID < 80100) {
			$cache->setAccessible(true);
			$holdouts->setAccessible(true);
		}
		$cached = $cache->getValue($context)['exp_no_holdouts_cached'];
		$holdouts->setValue($context, ['exp_no_holdouts_cached' => [self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)]]);

		self::assertSame(self::NORMAL_VARIANT, $context->peekTreatment('exp_no_holdouts_cached'));
		self::assertSame($cached, $cache->getValue($context)['exp_no_holdouts_cached']);
	}

	// A holdout that covers no experiment leaves the context on main's exposure and variable lookup: an arm planted on
	// the cached assignment and marked dropped is never queued by either read.
	public function testHoldoutCoveringNothingKeepsTheNoHoldoutReadPath(): void {
		$experiment = self::experiment(1, 'exp_fast_path');
		$experiment->variants[1]->config = '{"fast_path":"treatment"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_fast_path'));

		$cache = new \ReflectionProperty(Context::class, 'assignmentCache');
		$dropped = new \ReflectionProperty(Context::class, 'droppedHoldoutArms');
		if (PHP_VERSION_ID < 80100) {
			$cache->setAccessible(true);
			$dropped->setAccessible(true);
		}
		$assignment = $cache->getValue($context)['exp_fast_path'];
		$arm = new \ABSmartly\SDK\Assignment();
		$arm->id = 11;
		$arm->unitType = self::UNIT_TYPE;
		$arm->assigned = true;
		$arm->eligible = true;
		$assignment->holdouts = [self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)];
		$assignment->holdoutAssignments = [$arm];
		$dropped->setValue($context, [spl_object_id($arm) => $arm]);

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_fast_path'));
		self::assertSame('treatment', $context->getVariableValue('fast_path', 'default'));
		self::assertSame(1, $context->getPendingCount());
	}

	// holdoutIds that name no holdout leave the data without holdouts, so a throwing logger stops the walk, as on main.
	public function testUnknownHoldoutIdLeavesTheVariableCandidateUncovered(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 1;
		$first = self::coveredBy(self::experiment(1, 'exp_var_unknown_id'), 99);
		$first->variants[1]->config = '{"unrelated":"x"}';
		$first->variants[0]->config = '{"unknown_id":"control"}';
		$second = self::experiment(2, 'exp_var_unknown_id_second');
		$second->variants[1]->config = '{"unknown_id":"from_second"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $first, $second),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getVariableValue('unknown_id', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(1, $context->getPendingCount());
	}

	// When several holdouts' loggers throw, the first holdout's error is re-thrown after all exposures are queued.
	public function testFirstHoldoutLoggerErrorWinsOverALaterHoldoutError(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function($exposure) {
			if ($exposure->id === 11 || $exposure->id === 12) {
				throw new \RuntimeException('holdout ' . $exposure->id);
			}
			return false;
		};
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_b', self::HOLDOUT_B_SEED),
			self::holdout(12, 'holdout_b2', self::HOLDOUT_B_SEED),
		], self::coveredBy(self::experiment(1, 'exp_two_throwing_holdouts'), 11, 12)), [self::UNIT_TYPE => self::UID], [], [],
			$logger);

		try {
			$context->getTreatment('exp_two_throwing_holdouts');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('holdout 11', $exception->getMessage());
		}

		self::assertSame(3, $context->getPendingCount());
	}

	/** @dataProvider rejectedCoverageInstalls */
	public function testRejectedRefreshPreservesThePreviousVariableExposureBehavior(bool $hadCoverage, bool $typeError): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 1;
		$first = self::experiment(1, 'exp_refresh_first');
		$first->variants[0]->config = '{"refresh_key":"control"}';
		$first->variants[1]->config = '{"other":"x"}';
		$second = self::withVariables(self::experiment(2, 'exp_refresh_second'), 'refresh_key');
		$oldData = self::data([], $first, $second);
		$holdout = self::holdout(11, 'holdout_refresh', self::HOLDOUT_A_SEED);
		$covered = self::coveredBy(self::experiment(3, 'exp_refresh_covered'), 11);
		$context = $this->createContext($hadCoverage ? self::data([$holdout], $covered) : $oldData,
			[self::UNIT_TYPE => self::UID], [], [], $logger);
		if ($hadCoverage) {
			$this->refresh($context, $oldData);
		}

		$malformed = self::experiment(4, 'exp_refresh_malformed');
		if ($typeError) {
			$malformed->variants = null;
		}
		else {
			$malformed->audience = '{';
		}
		$logger->clear();
		$this->refresh($context, self::data([$holdout], $covered, $malformed));

		self::assertSame($oldData, $context->getContextData());
		self::assertTrue($context->isReady());
		self::assertFalse($context->isFailed());
		self::assertCount(1, $logger->events);
		self::assertSame(ContextEventLoggerEvent::Error, $logger->events[0]->getEvent());
		self::assertInstanceOf($typeError ? \TypeError::class : \JsonException::class, $logger->events[0]->getData());
		try {
			$context->getVariableValue('refresh_key', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		$expected = [self::exposure(1, 'exp_refresh_first', self::NORMAL_VARIANT)];
		if ($hadCoverage) {
			$expected[] = self::exposure(2, 'exp_refresh_second', self::NORMAL_VARIANT);
		}
		self::assertSame(count($expected), $context->getPendingCount());
		$this->assertPublished($context, ...$expected);
	}

	public static function rejectedCoverageInstalls(): array {
		return [
			'first coverage rejected by JsonException' => [false, false],
			'first coverage rejected by TypeError' => [false, true],
			'past coverage retained after JsonException' => [true, false],
			'past coverage retained after TypeError' => [true, true],
		];
	}

	public function testUnknownHoldoutIdsDoNotMarkTheContextAsHavingHoldouts(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_unknown_fast_path'), 99);
		$experiment->variants[1]->config = '{"unknown_fast_path":"treatment"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_unknown_fast_path'));

		$hasHoldouts = new \ReflectionProperty(Context::class, 'hasHoldouts');
		if (PHP_VERSION_ID < 80100) {
			$hasHoldouts->setAccessible(true);
		}
		self::assertFalse($hasHoldouts->getValue($context));
		self::assertSame('treatment', $context->getVariableValue('unknown_fast_path', 'default'));
		self::assertSame(1, $context->getPendingCount());
	}

	// With holdouts in the data, an uncovered candidate's logger error is deferred too, so a later covered candidate's
	// own and holdout exposures are still queued (Java). Data without holdouts keeps main's loop.
	public function testThrowingLoggerOnUncoveredVariableCandidateKeepsWalkingWhenTheDataHasHoldouts(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 1;
		$first = self::experiment(1, 'exp_var_uncovered_first');
		$first->variants[1]->config = '{"shared_uncovered":"from_first"}';
		$first->variants[1]->name = 'B';
		$first->split = [1.0, 0.0];
		$first->variants[0]->config = '{"other":"x"}';
		$second = self::coveredBy(self::experiment(2, 'exp_var_uncovered_second'), 11);
		$second->variants[1]->config = '{"shared_uncovered":"from_second"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_b', self::HOLDOUT_B_SEED)], $first,
			$second), [self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getVariableValue('shared_uncovered', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(3, $context->getPendingCount());
	}

	/** @dataProvider loggerCloseOutcomes */
	public function testVariableWalkStopsOnlyAfterASuccessfulLoggerClose(int $closeAt, bool $closeSucceeds): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function($exposure) use (&$context, $closeAt) {
			if ($exposure->id === $closeAt) {
				$context->close();
			}
			if ($exposure->id <= $closeAt) {
				throw new \RuntimeException('logger ' . $exposure->id);
			}
			return false;
		};
		$first = self::experiment(1, 'exp_close_first');
		$first->variants[0]->config = '{"close_key":"control"}';
		$first->variants[1]->config = '{"other":"x"}';
		$second = self::experiment(2, 'exp_close_second');
		$second->variants[0]->config = '{"close_key":"control"}';
		$second->variants[1]->config = '{"other":"x"}';
		$third = self::withVariables(self::experiment(3, 'exp_close_third'), 'close_key');
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_close', self::HOLDOUT_A_SEED)],
			$first, $second, $third, self::coveredBy(self::experiment(4, 'exp_close_covered'), 11)),
			[self::UNIT_TYPE => self::UID], [], [], $logger);
		if (!$closeSucceeds) {
			$this->eventHandler->prerun = static function() {
				throw new \RuntimeException('publish failed');
			};
		}

		try {
			$context->getVariableValue('close_key', 'default');
			self::fail('the first error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame($closeSucceeds || $closeAt > 1 ? 'logger 1' : 'publish failed', $exception->getMessage());
		}

		self::assertSame($closeSucceeds, $context->isClosed());
		self::assertSame($closeSucceeds ? 0 : 3, $context->getPendingCount());
		$this->eventHandler->prerun = null;
		$context->close();
		self::assertSame(0, $context->getPendingCount());
		self::assertCount(1, $this->eventHandler->submitted);
		self::assertSame($closeSucceeds ? range(1, $closeAt) : [1, 2, 3],
			array_column($this->eventHandler->submitted[0]->exposures, 'id'));
	}

	public static function loggerCloseOutcomes(): array {
		return [
			'first logger closes' => [1, true],
			'later logger closes after an earlier error' => [2, true],
			'failed close keeps walking' => [1, false],
		];
	}

	/** A logger that closes the context, then returns, on the first exposure of experiment 1. */
	private static function closingLogger(): MockContextEventLoggerProxy {
		return new class extends MockContextEventLoggerProxy {
			public bool $closed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if (!$this->closed && $event->getEvent() === ContextEventLoggerEvent::Exposure && $event->getData()->id === 1) {
					$this->closed = true;
					$context->close();
				}
			}
		};
	}

	/** Experiment 1, whose UID variant lacks $key, then experiment 2, which assigns it. */
	private static function closeCandidates(string $key): array {
		$first = self::experiment(1, 'exp_close_first_' . $key);
		$first->variants[1]->config = '{"unrelated":"x"}';
		$first->variants[0]->config = json_encode([$key => 'control']);
		$second = self::experiment(2, 'exp_close_second_' . $key);
		$second->variants[1]->config = json_encode([$key => 'from_second']);
		return [$first, $second];
	}

	// Holdouts that cover no experiment leave main's queueing too: the later candidate is still queued after a close.
	public function testHoldoutCoveringNothingLoggerThatClosesTheContextKeepsMainQueueing(): void {
		[$first, $second] = self::closeCandidates('close_uncovered');
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $first, $second),
			[self::UNIT_TYPE => self::UID], [], [], self::closingLogger());

		self::assertSame('from_second', $context->getVariableValue('close_uncovered', 'default'));
		self::assertTrue($context->isClosed());
		self::assertSame(1, $context->getPendingCount());
	}

	// Once the data has covered an experiment, a refresh to data without holdouts keeps refusing post-close exposures.
	public function testCoverageHistoryKeepsRefusingExposuresAfterCloseOnceDataHadHoldouts(): void {
		[$first, $second] = self::closeCandidates('close_history');
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_b', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(3, 'exp_close_covered'), 11)), [self::UNIT_TYPE => self::UID], [], [],
			self::closingLogger());
		$this->dataProvider->next = self::data([], $first, $second);
		$context->refresh();

		self::assertSame('from_second', $context->getVariableValue('close_history', 'default'));
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
	}

	// Without holdouts a logger that closes the context and returns keeps main's (and JS's) queueing: the later
	// candidate's exposure is still queued after the close.
	public function testNoHoldoutLoggerThatClosesTheContextKeepsMainQueueing(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public bool $closed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if (!$this->closed && $event->getEvent() === ContextEventLoggerEvent::Exposure && $event->getData()->id === 1) {
					$this->closed = true;
					$context->close();
				}
			}
		};
		$first = self::experiment(1, 'exp_no_holdout_close_first');
		$first->variants[1]->config = '{"unrelated":"x"}';
		$first->variants[0]->config = '{"close_key":"control"}';
		$second = self::experiment(2, 'exp_no_holdout_close_second');
		$second->variants[1]->config = '{"close_key":"from_second"}';
		$context = $this->createContext(self::data([], $first, $second), [self::UNIT_TYPE => self::UID], [], [], $logger);

		self::assertSame('from_second', $context->getVariableValue('close_key', 'default'));
		self::assertTrue($context->isClosed());
		self::assertSame(1, $context->getPendingCount());
	}

	// An exposure logger that refreshes mid-walk and installs the first coverage hands the unvisited candidates to the
	// holdout-aware path: the later, now held-out candidate gets control and its holdout exposure, not its own.
	public function testRefreshThatInstallsFirstCoverageMidWalkUsesTheHoldoutPathForLaterCandidates(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public ?ContextData $next = null;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->next !== null && $event->getEvent() === ContextEventLoggerEvent::Exposure && $event->getData()->id === 1) {
					$this->next = null;
					$context->refresh();
				}
			}
		};
		[$first, $second] = self::closeCandidates('refresh_epoch');
		$context = $this->createContext(self::data([], $first, $second), [self::UNIT_TYPE => self::UID], [], [], $logger);
		[$first, $second] = self::closeCandidates('refresh_epoch');
		$second->variants[0]->config = '{"refresh_epoch":"control"}';
		$this->dataProvider->next = self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $first,
			self::coveredBy($second, 11));
		$logger->next = $this->dataProvider->next;

		self::assertSame('control', $context->getVariableValue('refresh_epoch', 'default'));
		// The variable read itself queued the holdout exposure; check before any other read can queue it.
		self::assertSame(2, $context->getPendingCount());
		self::assertSame(0, $context->peekTreatment('exp_close_second_refresh_epoch'));
		$this->assertPublished($context, self::exposure(1, 'exp_close_first_refresh_epoch', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_a', 0));
	}

	// A candidate the refresh removed takes no part in the rest of the walk, even when a peek cached its suppressed
	// assignment: it neither supplies its control value nor queues its holdout exposure.
	public function testCandidateRemovedByAMidWalkRefreshIsSkippedEvenWhenItsSuppressedAssignmentIsCached(): void {
		$logger = self::refreshingLogger(1);
		[$first, $second] = self::closeCandidates('refresh_removed');
		$second->variants[0]->config = '{"refresh_removed":"removed-control"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $first,
			self::coveredBy($second, 11)), [self::UNIT_TYPE => self::UID], [], [], $logger);
		self::assertSame(0, $context->peekTreatment('exp_close_second_refresh_removed'));
		[$first] = self::closeCandidates('refresh_removed');
		$this->dataProvider->next = self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $first);
		$logger->next = $this->dataProvider->next;

		self::assertSame('default', $context->getVariableValue('refresh_removed', 'default'));
		self::assertSame(['exp_close_first_refresh_removed'], $context->getExperiments());
		$this->assertPublished($context, self::exposure(1, 'exp_close_first_refresh_removed', self::NORMAL_VARIANT));
	}

	/** A logger that refreshes to $next on experiment $id's exposure, then throws if $throw. */
	private static function refreshingLogger(int $id, bool $throw = false): MockContextEventLoggerProxy {
		return new class($id, $throw) extends MockContextEventLoggerProxy {
			public ?ContextData $next = null;
			private int $id;
			private bool $throw;

			public function __construct(int $id, bool $throw) {
				$this->id = $id;
				$this->throw = $throw;
			}

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->next !== null && $event->getEvent() === ContextEventLoggerEvent::Exposure && $event->getData()->id === $this->id) {
					$this->next = null;
					$context->refresh();
					if ($this->throw) {
						throw new \RuntimeException('boom');
					}
				}
			}
		};
	}

	// When the first coverage arrives during the last candidate's exposure, visited candidates are not re-read: the
	// read returns the caller default and publishes only the two ordinary exposures, as in JS.
	public function testRefreshThatInstallsCoverageOnTheLastCandidateDoesNotRereadVisitedOnes(): void {
		$logger = self::refreshingLogger(2);
		[$first, $second] = self::closeCandidates('refresh_last');
		$second->variants[1]->config = '{"unrelated":"y"}';
		$second->variants[0]->config = '{"refresh_last":"from_second_control"}';
		$context = $this->createContext(self::data([], $first, $second), [self::UNIT_TYPE => self::UID], [], [], $logger);
		[$first, $second] = self::closeCandidates('refresh_last');
		$second->variants[1]->config = '{"unrelated":"y"}';
		$second->variants[0]->config = '{"refresh_last":"from_second_control"}';
		$this->dataProvider->next = self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy($first, 11), $second);
		$logger->next = $this->dataProvider->next;

		self::assertSame('default', $context->getVariableValue('refresh_last', 'default'));
		$this->assertPublished($context, self::exposure(1, 'exp_close_first_refresh_last', self::NORMAL_VARIANT),
			self::exposure(2, 'exp_close_second_refresh_last', self::NORMAL_VARIANT));
	}

	// The candidate whose exposure triggered the refresh is not re-read even if the new data covers it: it keeps the
	// ordinary exposure already queued, and only later candidates use the new coverage.
	public function testRefreshThatCoversTheTriggeringCandidateDoesNotRereadIt(): void {
		$logger = self::refreshingLogger(1);
		[$first, $second] = self::closeCandidates('refresh_self');
		$context = $this->createContext(self::data([], $first, $second), [self::UNIT_TYPE => self::UID], [], [], $logger);
		[$first, $second] = self::closeCandidates('refresh_self');
		$this->dataProvider->next = self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy($first, 11), $second);
		$logger->next = $this->dataProvider->next;

		self::assertSame('from_second', $context->getVariableValue('refresh_self', 'default'));
		$this->assertPublished($context, self::exposure(1, 'exp_close_first_refresh_self', self::NORMAL_VARIANT),
			self::exposure(2, 'exp_close_second_refresh_self', self::NORMAL_VARIANT));
	}

	// A winner whose logger refreshes into coverage and then throws still sends that error to the caller, as on main.
	public function testWinnerLoggerThatRefreshesIntoCoverageThenThrowsReachesTheCaller(): void {
		$logger = self::refreshingLogger(1, true);
		$winner = self::experiment(1, 'exp_refresh_winner');
		$winner->variants[1]->config = '{"refresh_winner":"from_winner"}';
		$context = $this->createContext(self::data([], $winner), [self::UNIT_TYPE => self::UID], [], [], $logger);
		$this->dataProvider->next = self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::experiment(1, 'exp_refresh_winner'), self::coveredBy(self::experiment(2, 'exp_refresh_other'), 11));
		$logger->next = $this->dataProvider->next;

		try {
			$context->getVariableValue('refresh_winner', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}
	}

	// A logger that refreshes into the first coverage and then throws hands its error to the holdout walk: the later
	// held-out candidate's holdout exposure is still queued and the error is re-thrown afterwards.
	public function testRefreshIntoCoverageThenThrowStillQueuesLaterHoldoutExposures(): void {
		$logger = self::refreshingLogger(1, true);
		[$first, $second] = self::closeCandidates('refresh_throw');
		$context = $this->createContext(self::data([], $first, $second), [self::UNIT_TYPE => self::UID], [], [], $logger);
		[$first, $second] = self::closeCandidates('refresh_throw');
		$this->dataProvider->next = self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $first,
			self::coveredBy($second, 11));
		$logger->next = $this->dataProvider->next;

		try {
			$context->getVariableValue('refresh_throw', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		$this->assertPublished($context, self::exposure(1, 'exp_close_first_refresh_throw', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testCoveredTreatmentLoggerThatClosesTheContextQueuesNoHoldoutExposure(): void {
		$context = $this->createCoveredContextWhoseExposureLoggerCloses(
			self::coveredBy(self::experiment(1, 'exp_close_covered_treatment'), 11));

		try {
			$context->getTreatment('exp_close_covered_treatment');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('logger 1', $exception->getMessage());
		}

		$this->assertClosedWithOnlyTheExperimentExposurePublished($context, 'exp_close_covered_treatment');
	}

	public function testCoveredVariableLoggerThatClosesTheContextQueuesNoHoldoutExposure(): void {
		$context = $this->createCoveredContextWhoseExposureLoggerCloses(
			self::coveredBy(self::withVariables(self::experiment(1, 'exp_close_covered_variable'), 'close_covered_key'), 11));

		try {
			$context->getVariableValue('close_covered_key', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('logger 1', $exception->getMessage());
		}

		$this->assertClosedWithOnlyTheExperimentExposurePublished($context, 'exp_close_covered_variable');
	}

	private function createCoveredContextWhoseExposureLoggerCloses(object $covered): Context {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function($exposure) use (&$context) {
			if ($exposure->id === 1) {
				$context->close();
				throw new \RuntimeException('logger 1');
			}
			return false;
		};
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_close_covered', self::HOLDOUT_B_SEED)],
			$covered), [self::UNIT_TYPE => self::UID], [], [], $logger);

		return $context;
	}

	private function assertClosedWithOnlyTheExperimentExposurePublished(Context $context, string $experimentName): void {
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
		self::assertCount(1, $this->eventHandler->submitted);
		self::assertSame([[1, $experimentName]], array_map(static fn($e) => [$e->id, $e->name],
			$this->eventHandler->submitted[0]->exposures));
	}

	// A later candidate whose assignment itself fails must not replace the first deferred logger error.
	public function testLaterCandidateAssignmentFailureDoesNotReplaceTheDeferredLoggerError(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 11;
		$suppressed = self::coveredBy(self::experiment(1, 'exp_var_then_bad_split'), 11);
		$suppressed->variants[1]->config = '{"bad_split_key":"from_suppressed"}';
		$broken = self::experiment(2, 'exp_var_bad_split');
		$broken->split = ['bad', 1];
		$broken->variants[1]->config = '{"bad_split_key":"from_broken"}';
		$broken->variants[0]->config = '{"bad_split_key":"from_broken"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $suppressed,
			$broken), [self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getVariableValue('bad_split_key', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\Throwable $exception) {
			self::assertInstanceOf(\RuntimeException::class, $exception);
			self::assertSame('boom', $exception->getMessage());
		}
	}

	public function testThrowingLoggerDoesNotMakeExperimentOrHoldoutExposureRetryable(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 1;
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_terminal', self::HOLDOUT_B_SEED)],
			self::coveredBy(self::experiment(1, 'exp_throwing_logger_terminal'), 11)), [self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getTreatment('exp_throwing_logger_terminal');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}
		$logger->throwWhen = fn($exposure) => false;

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_throwing_logger_terminal'));
		$this->assertPublished($context, self::exposure(1, 'exp_throwing_logger_terminal', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_terminal', 1));
	}

	// Java checks a flush is scheduled; PHP publishes synchronously, so this pins that the queued exposure is published.
	public function testThrowingLoggerOnSuppressedExperimentsOnlyHoldoutStillPublishes(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => true;
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_stranded_flush'), 11)), [self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getTreatment('exp_stranded_flush');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(1, $context->getPendingCount());
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testNullHoldoutArrayElementIsIgnoredAndExperimentAssignsNormally(): void {
		$context = $this->createContext(self::data([null], self::coveredBy(self::experiment(1, 'exp_null_holdout_entry'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_null_holdout_entry'));
		$this->assertPublished($context, self::exposure(1, 'exp_null_holdout_entry', self::NORMAL_VARIANT));
	}

	public function testHoldoutWithNullSplitIsIgnoredAndExperimentAssignsNormally(): void {
		$holdout = self::holdout(11, 'holdout_null_split', self::HOLDOUT_A_SEED);
		$holdout->split = null;
		$context = $this->createContext(self::data([$holdout], self::coveredBy(self::experiment(1, 'exp_null_split_holdout'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_null_split_holdout'));
		$this->assertPublished($context, self::exposure(1, 'exp_null_split_holdout', self::NORMAL_VARIANT));
	}

	public function testHoldoutWithEmptySplitIsIgnoredAndExperimentAssignsNormally(): void {
		$holdout = self::holdout(11, 'holdout_empty_split', self::HOLDOUT_A_SEED);
		$holdout->split = [];
		$context = $this->createContext(self::data([$holdout], self::coveredBy(self::experiment(1, 'exp_empty_split_holdout'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_empty_split_holdout'));
		$this->assertPublished($context, self::exposure(1, 'exp_empty_split_holdout', self::NORMAL_VARIANT));
	}

	public function testStaleHoldoutReferenceNeverOverwritesNewerPinnedCacheEntry(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_h', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_f'), 11)));

		self::assertSame(0, $context->getTreatment('exp_f'));

		$iteration2 = self::holdout(11, 'holdout_h', self::HOLDOUT_B_SEED);
		$iteration2->iteration = 2;
		$this->refresh($context, self::data([$iteration2], self::coveredBy(self::experiment(1, 'exp_f'), 11),
			self::coveredBy(self::experiment(2, 'exp_g'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_g'));
		self::assertSame(3, $context->getPendingCount());

		$stale = self::holdout(11, 'holdout_h', self::HOLDOUT_A_SEED);
		$resolved = self::invoke($context, 'getHoldoutAssignment', $stale, self::UNIT_TYPE);
		self::assertSame(2, $resolved->iteration);
		self::assertSame(1, $resolved->variant);
		self::assertTrue($resolved->exposed);

		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_h', 0), self::exposure(2, 'exp_g', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_h', 1));
	}

	public function testHoldoutBecomingApplicableAfterRefreshStillFiresForOverriddenExperiment(): void {
		$context = $this->createContext(self::data([], self::experiment(1, 'exp_override_late_holdout')), [self::UNIT_TYPE => self::UID],
			['exp_override_late_holdout' => 3]);

		self::assertSame(3, $context->getTreatment('exp_override_late_holdout'));
		self::assertSame(1, $context->getPendingCount());

		$this->refresh($context, self::data([self::holdout(11, 'holdout_late', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_override_late_holdout'), 11)));

		self::assertSame(3, $context->getTreatment('exp_override_late_holdout'));
		$own = self::exposure(1, 'exp_override_late_holdout', 3, false, true);
		$this->assertPublished($context, $own, $own, self::holdoutExposure(11, 'holdout_late', 0));
	}

	public function testVariablePathHoldoutExposureIsPublishedWithoutAnyOtherEvent(): void {
		$suppressed = self::coveredBy(self::experiment(1, 'exp_var_flush_suppressed'), 11);
		$suppressed->variants[0]->config = '{"flush_key":"control"}';
		$suppressed->variants[1]->config = '{"flush_key":"from_suppressed"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_flush', self::HOLDOUT_A_SEED)], $suppressed));

		self::assertSame(0, $context->getPendingCount());
		self::assertSame('control', $context->getVariableValue('flush_key', 'default'));
		self::assertSame(1, $context->getPendingCount());
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_flush', 0));
	}

	public function testSetDataDoesNotMutateCallerSuppliedHoldoutIdsArray(): void {
		$covered = self::experiment(1, 'exp_holdout_no_mutate_in');
		$covered->holdoutIds = [12, 11];
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_no_mutate', self::HOLDOUT_A_SEED)], $covered,
			self::experiment(2, 'exp_holdout_no_mutate_not_covered')));

		self::assertSame(0, $context->getTreatment('exp_holdout_no_mutate_in'));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_holdout_no_mutate_not_covered'));
		self::assertSame([12, 11], $covered->holdoutIds);
	}

	public function testMatchingAssignmentRuleWinsOverHoldoutSuppression(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_holdout_rules'), 11);
		$experiment->assignmentRules = self::US_RULE;
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_rules_suppression', self::HOLDOUT_A_SEED)], $experiment));
		$context->setAttribute('country', 'US');

		self::assertSame(1, $context->getTreatment('exp_holdout_rules'));
		$this->assertPublished($context, self::exposure(1, 'exp_holdout_rules', 1, false, false, false, false, false, true),
			self::holdoutExposure(11, 'holdout_rules_suppression', 0));
	}

	public function testMatchingSplitRuleWinsOverHoldoutSuppression(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_holdout_split_rule'), 11);
		$experiment->assignmentRules = '{"rules":[{"name":"Everyone","type":"split","conditions":null,"environments":[],"percentages":"0/100"}]}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment));

		self::assertSame(1, $context->peekTreatment('exp_holdout_split_rule'));
		self::assertSame(1, $context->getTreatment('exp_holdout_split_rule'));
		self::assertSame(2, $context->getPendingCount());
	}

	public function testHeldOutUnitIsSuppressedWhenRuleDoesNotMatch(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_holdout_rules'), 11);
		$experiment->assignmentRules = self::US_RULE;
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment));
		$context->setAttribute('country', 'GB');

		self::assertSame(0, $context->getTreatment('exp_holdout_rules'));
		self::assertSame(1, $context->getPendingCount());

		$context->setAttribute('country', 'US');
		self::assertSame(1, $context->getTreatment('exp_holdout_rules'));
		self::assertSame(2, $context->getPendingCount());
	}

	public function testHeldOutUnitIsSuppressedAgainWhenRuleStopsMatching(): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_holdout_rules'), 11);
		$experiment->assignmentRules = self::US_RULE;
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment));
		$context->setAttribute('country', 'US');

		self::assertSame(1, $context->getTreatment('exp_holdout_rules'));
		self::assertSame(2, $context->getPendingCount());

		$context->setAttribute('country', 'GB');
		self::assertSame(0, $context->getTreatment('exp_holdout_rules'));
		self::assertSame(0, $context->peekTreatment('exp_holdout_rules'));
		self::assertSame(2, $context->getPendingCount());
	}

	public function testVariableValueOnHeldOutRuleWonExperiment(): void {
		$experiment = self::withVariables(self::coveredBy(self::experiment(1, 'exp_holdout_rules'), 11), 'color');
		$experiment->assignmentRules = self::US_RULE;
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_rules_suppression', self::HOLDOUT_A_SEED)], $experiment));
		$context->setAttribute('country', 'US');

		self::assertSame('treatment', $context->getVariableValue('color', 'default'));
		$this->assertPublished($context, self::exposure(1, 'exp_holdout_rules', 1, false, false, false, false, false, true),
			self::holdoutExposure(11, 'holdout_rules_suppression', 0));
	}

	public function testSharedVariableKeyPrefersRuleWonOverSuppressedExperiment(): void {
		$heldOut = self::withVariables(self::coveredBy(self::experiment(1, 'exp_held_out'), 11), 'color');
		$ruleWon = self::withVariables(self::coveredBy(self::experiment(2, 'exp_rule_won'), 11), 'color');
		$ruleWon->assignmentRules = self::US_RULE;
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $heldOut, $ruleWon));

		$context->setAttribute('country', 'GB');
		self::assertSame('control', $context->peekVariableValue('color', 'default'));

		$context->setAttribute('country', 'US');
		self::assertSame('treatment', $context->peekVariableValue('color', 'default'));
	}

	public function testSharedVariableKeyPrefersEarlierAssignedOverLaterRuleWonExperiment(): void {
		$assigned = self::withVariables(self::experiment(1, 'exp_assigned'), 'color');
		$assigned->split = [1.0, 0.0];
		$ruleWon = self::withVariables(self::experiment(2, 'exp_rule_won'), 'color');
		$ruleWon->assignmentRules = self::US_RULE;
		$context = $this->createContext(self::data([], $assigned, $ruleWon));
		$context->setAttribute('country', 'US');

		self::assertSame('control', $context->peekVariableValue('color', 'default'));
		self::assertSame(1, $context->peekTreatment('exp_rule_won'));
	}
}

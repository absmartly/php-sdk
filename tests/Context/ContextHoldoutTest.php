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

	/** @return mixed */
	private static function property(Context $context, string $name, ...$value) {
		$reflection = new \ReflectionProperty(Context::class, $name);
		if (PHP_VERSION_ID < 80100) {
			$reflection->setAccessible(true);
		}
		if ($value !== []) {
			$reflection->setValue($context, $value[0]);
		}
		return $reflection->getValue($context);
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
			$context->setAttributeValue('age', 5);

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
		$cached = self::invoke($context, 'getAssignment', 'exp_holdout_custom_cache');
		self::assertSame(0, $context->getTreatment('exp_holdout_custom_cache'));
		self::assertSame($cached, self::invoke($context, 'getAssignment', 'exp_holdout_custom_cache'));
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
		$context->setAttributeValue('age', 5);

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
		$cached = self::invoke($context, 'getAssignment', 'exp_holdout_cache');
		self::assertSame(0, $context->getTreatment('exp_holdout_cache'));
		self::assertSame($cached, self::invoke($context, 'getAssignment', 'exp_holdout_cache'));
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

	public function testRefreshAndThrowDuringHoldoutExposureKeepsEveryPinnedArmInItsDecisionEpoch(): void {
		$nextA = self::holdout(11, 'holdout_epoch_a', self::HOLDOUT_A_SEED);
		$nextB = self::holdout(12, 'holdout_epoch_b', self::HOLDOUT_A_SEED);
		$nextA->iteration = $nextB->iteration = 2;
		$next = self::data([$nextA, $nextB], self::coveredBy(self::experiment(1, 'exp_epoch_siblings'), 11, 12));
		$refreshed = false;
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function ($exposure) use (&$context, &$refreshed, $next): bool {
			if ($exposure->id === 11 && !$refreshed) {
				$refreshed = true;
				$this->refresh($context, $next);
				return true;
			}
			return false;
		};
		$context = $this->createContext(self::data([
			self::holdout(11, 'holdout_epoch_a', self::HOLDOUT_B_SEED),
			self::holdout(12, 'holdout_epoch_b', self::HOLDOUT_B_SEED),
		], self::coveredBy(self::experiment(1, 'exp_epoch_siblings'), 11, 12)),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getTreatment('exp_epoch_siblings');
			self::fail('the first holdout logger error must reach the caller after its sibling is queued');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		$this->assertPublished($context, self::exposure(1, 'exp_epoch_siblings', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_epoch_a', 1), self::holdoutExposure(12, 'holdout_epoch_b', 1));
		self::assertSame(0, $context->getTreatment('exp_epoch_siblings'));
		$this->assertPublished($context, self::exposure(1, 'exp_epoch_siblings', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_epoch_a', 1), self::holdoutExposure(12, 'holdout_epoch_b', 1),
			self::holdoutExposure(11, 'holdout_epoch_a', 0), self::holdoutExposure(12, 'holdout_epoch_b', 0));
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

		self::assertSame('default', $context->getVariableValue('my_var', 'default'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_var_late', 0, 'user_id'));
	}

	/** An attribute object whose getter runs $onRead once, the next time a condition reads it. */
	private static function unitSettingProbe(): object {
		return new class {
			public $score;
			public ?\Closure $onRead = null;

			public function __construct() {
				unset($this->score);
			}

			public function __get(string $name) {
				if ($this->onRead !== null) {
					$onRead = $this->onRead;
					$this->onRead = null;
					$onRead();
				}
				return 1;
			}
		};
	}

	// A condition getter that supplies the covered unit during a peek must not leave a decision cached with the arm
	// drawn while the unit was missing: the next exposing read honours the unit's holdout.
	/** @dataProvider unitSettingDecisions */
	public function testUnitSetByAConditionGetterDuringAPeekIsHonouredByTheHoldout(string $shape, int $variant, array $own): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_getter_unit'), 11);
		$condition = '{"gte":[{"var":"probe/score"},{"value":0}]}';
		if ($shape === 'rule') {
			$experiment->assignmentRules = '{"rules":[{"name":"probe","type":"assign","conditions":{"and":[' . $condition
				. ']},"environments":[],"variant":1}]}';
		}
		elseif ($shape === 'strict mismatch') {
			$experiment->audienceStrict = true;
			$experiment->audience = '{"filter":[{"lt":[{"var":"probe/score"},{"value":0}]}]}';
		}
		else {
			$experiment->audience = '{"filter":[' . $condition . ']}';
			if ($shape === 'full-on') {
				$experiment->fullOnVariant = 1;
			}
		}
		$holdout = self::holdout(11, 'holdout_getter', self::HOLDOUT_A_SEED);
		$holdout->split = [1.0, 0.0];
		$context = $this->createContext(self::data([$holdout], $experiment), []);
		$probe = self::unitSettingProbe();
		$context->setAttributeValue('probe', $probe);
		$probe->onRead = function () use ($context) {
			$context->setUnit(self::UNIT_TYPE, self::UID);
		};

		$context->peekTreatment('exp_getter_unit');
		self::assertSame(0, $context->getPendingCount());

		self::assertSame($variant, $context->getTreatment('exp_getter_unit'));
		self::assertSame($variant, $context->getTreatment('exp_getter_unit'));
		$this->assertPublished($context, ...array_merge($own, [self::holdoutExposure(11, 'holdout_getter', 0)]));
	}

	public static function unitSettingDecisions(): array {
		return [
			'traffic split' => ['audience', 0, []],
			'assignment rule' => ['rule', 1, [self::exposure(1, 'exp_getter_unit', 1, false, false, false, false, false, true)]],
			'full-on' => ['full-on', 0, []],
			'strict mismatch' => ['strict mismatch', 0, []],
		];
	}

	// The same holds when the getter sets the unit while a cached decision is re-checked after an attribute change.
	/** @dataProvider unitSettingRecheckSplits */
	public function testUnitSetByAnAudienceGetterDuringARecheckIsHonouredByTheHoldout(array $split, int $variant, array $expected,
		bool $getterReads = false): void {
		$experiment = self::coveredBy(self::experiment(1, 'exp_getter_unit'), 11);
		$experiment->audience = '{"filter":[{"gte":[{"var":"probe/score"},{"value":0}]}]}';
		$holdout = self::holdout(11, 'holdout_getter', self::HOLDOUT_A_SEED);
		$holdout->split = $split;
		$context = $this->createContext(self::data([$holdout], $experiment), []);
		$probe = self::unitSettingProbe();
		$context->setAttributeValue('probe', $probe);
		$context->peekTreatment('exp_getter_unit');
		$context->setAttributeValue('bump', 1);
		$probe->onRead = function () use ($context, $getterReads) {
			$context->setUnit(self::UNIT_TYPE, self::UID);
			if ($getterReads) {
				$context->peekTreatment('exp_getter_unit');
			}
		};

		self::assertSame($variant, $context->getTreatment('exp_getter_unit'));
		self::assertSame($variant, $context->getTreatment('exp_getter_unit'));
		$this->assertPublished($context, ...$expected);
	}

	public static function unitSettingRecheckSplits(): array {
		return [
			'held out' => [[1.0, 0.0], 0, [self::holdoutExposure(11, 'holdout_getter', 0)]],
			'not held out' => [[0.0, 1.0], self::NORMAL_VARIANT, [self::exposure(1, 'exp_getter_unit', self::NORMAL_VARIANT),
				self::holdoutExposure(11, 'holdout_getter', 1)]],
			'held out, getter reads it again' => [[1.0, 0.0], 0, [self::holdoutExposure(11, 'holdout_getter', 0)], true],
		];
	}

	// A covered candidate that lost an exposing variable read because its unit was missing stays the loser once the unit
	// is set, as without holdouts: the key keeps resolving to the experiment that won it.
	public function testLateUnitDoesNotSwitchTheWinnerOfAnExposedVariableRead(): void {
		$first = self::coveredBy(self::withVariables(self::experiment(1, 'exp_first', 'user_id'), 'late_key'), 11);
		$first->variants[1]->config = '{"late_key":"from_first"}';
		$second = self::withVariables(self::experiment(2, 'exp_second'), 'late_key');
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_late', self::HOLDOUT_B_SEED, 'user_id')],
			$first, $second));

		self::assertSame('treatment', $context->getVariableValue('late_key', 'default'));
		$context->setUnit('user_id', self::UID);
		self::assertSame('treatment', $context->getVariableValue('late_key', 'default'));
		self::assertSame('treatment', $context->getVariableValue('late_key', 'default'));
		$this->assertPublished($context, self::exposure(2, 'exp_second', self::NORMAL_VARIANT));
		self::assertSame(0, $context->peekTreatment('exp_first'));
	}

	// A peek pins nothing: the unit set after it re-resolves the covered candidate, which then wins the key.
	public function testLateUnitAfterAPeekLetsTheCoveredCandidateWin(): void {
		$first = self::coveredBy(self::withVariables(self::experiment(1, 'exp_first', 'user_id'), 'late_key'), 11);
		$first->variants[1]->config = '{"late_key":"from_first"}';
		$second = self::withVariables(self::experiment(2, 'exp_second'), 'late_key');
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_late', self::HOLDOUT_B_SEED, 'user_id')],
			$first, $second));

		self::assertSame('treatment', $context->peekVariableValue('late_key', 'default'));
		$context->setUnit('user_id', self::UID);
		self::assertSame('from_first', $context->getVariableValue('late_key', 'default'));
		$this->assertPublished($context, self::exposure(1, 'exp_first', self::NORMAL_VARIANT, true, false, false, false, false,
			false, 'user_id'), self::holdoutExposure(11, 'holdout_late', 1, 'user_id'));
	}

	// A refresh that rebuilds a passed-over candidate unpins it: the rebuilt assignment is re-resolved for a late unit.
	public function testRebuiltLoserIsNoLongerPinnedForALateUnit(): void {
		$first = self::coveredBy(self::withVariables(self::experiment(1, 'exp_first', 'user_id'), 'late_key'), 11);
		$second = self::withVariables(self::experiment(2, 'exp_second'), 'late_key');
		$holdout = self::holdout(11, 'holdout_late', self::HOLDOUT_A_SEED, 'user_id');
		$holdout->split = [1.0, 0.0];
		$context = $this->createContext(self::data([$holdout], $first, $second));
		self::assertSame('treatment', $context->getVariableValue('late_key', 'default'));
		$bumped = clone $first;
		$bumped->iteration = 2;
		$this->refresh($context, self::data([$holdout], $bumped, $second));

		self::assertSame('treatment', $context->peekVariableValue('late_key', 'default'));
		$context->setUnit('user_id', self::UID);
		self::assertSame(0, $context->getTreatment('exp_first'));
		$this->assertPublished($context, self::exposure(2, 'exp_second', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_late', 0, 'user_id'));
	}

	// A refresh that leaves a passed-over candidate's assignment as it was keeps it pinned.
	public function testLoserStaysPinnedAcrossARefreshThatKeepsItsAssignment(): void {
		$first = self::coveredBy(self::withVariables(self::experiment(1, 'exp_first', 'user_id'), 'late_key'), 11);
		$first->variants[1]->config = '{"late_key":"from_first"}';
		$second = self::withVariables(self::experiment(2, 'exp_second'), 'late_key');
		$data = self::data([self::holdout(11, 'holdout_late', self::HOLDOUT_B_SEED, 'user_id')], $first, $second);
		$context = $this->createContext($data);

		self::assertSame('treatment', $context->getVariableValue('late_key', 'default'));
		$this->refresh($context, $data);
		$context->setUnit('user_id', self::UID);
		self::assertSame('treatment', $context->getVariableValue('late_key', 'default'));
		$this->assertPublished($context, self::exposure(2, 'exp_second', self::NORMAL_VARIANT));
	}

	public function testOverrideValueAndExposuresSurviveALateUnitAndItsHoldoutResolves(): void {
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

	// A covered winner whose variant lacks the key ends the walk (main's shared key rule) even when its holdout logger
	// throws: both of its exposures are queued, the later candidate is not, and the error reaches the caller.
	public function testThrowingHoldoutLoggerOnCoveredWinnerWithoutTheKeyQueuesItsExposuresAndStops(): void {
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

		$logger->throwWhen = fn($exposure) => false;
		$this->assertPublished($context, self::exposure(1, 'exp_var_first_covered_assigned', self::NORMAL_VARIANT),
			self::holdoutExposure(12, 'holdout_b', 1));
	}

	// An overridden covered winner whose variant lacks the key also ends the walk after queuing its holdout exposure.
	public function testThrowingHoldoutLoggerOnOverriddenCoveredWinnerQueuesItsExposuresAndStops(): void {
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

		$logger->throwWhen = fn($exposure) => false;
		$this->assertPublished($context, self::exposure(1, 'exp_var_first_covered_overridden', 1, false, true),
			self::holdoutExposure(12, 'holdout_b', 1));
	}

	// Any Throwable is deferred, not only an Exception: a held-out candidate's logger TypeError still lets the later
	// winner be queued, and is re-thrown afterwards.
	public function testThrowingHoldoutLoggerErrorIsDeferredOnTheVariablePath(): void {
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function($exposure) {
			if ($exposure->id === 11) {
				throw new \TypeError('logger type error');
			}
			return false;
		};
		[$first, $second] = self::candidateWithoutKeyThenOneWithKey('covered_type_error');
		$first->holdoutIds = [11];
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $first, $second),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		try {
			$context->getVariableValue('covered_type_error', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\TypeError $exception) {
			self::assertSame('logger type error', $exception->getMessage());
		}

		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0),
			self::exposure(2, 'exp_var_second_covered_type_error', self::NORMAL_VARIANT));
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
		$cached = self::property($context, 'assignmentCache')['exp_no_holdouts_cached'];
		self::property($context, 'experimentHoldouts', ['exp_no_holdouts_cached' => [self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)]]);

		self::assertSame(self::NORMAL_VARIANT, $context->peekTreatment('exp_no_holdouts_cached'));
		self::assertSame($cached, self::property($context, 'assignmentCache')['exp_no_holdouts_cached']);
	}

	// A holdout that covers no experiment leaves the context on main's exposure and variable lookup: an arm planted on
	// the cached assignment and marked dropped is never queued by either read.
	public function testHoldoutCoveringNothingKeepsTheNoHoldoutReadPath(): void {
		$experiment = self::experiment(1, 'exp_fast_path');
		$experiment->variants[1]->config = '{"fast_path":"treatment"}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)], $experiment));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_fast_path'));

		$assignment = self::property($context, 'assignmentCache')['exp_fast_path'];
		$arm = new \ABSmartly\SDK\Assignment();
		$arm->id = 11;
		$arm->unitType = self::UNIT_TYPE;
		$arm->assigned = true;
		$arm->eligible = true;
		$assignment->holdouts = [self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)];
		$assignment->holdoutAssignments = [$arm];
		self::property($context, 'droppedHoldoutArms', [spl_object_id($arm) => $arm]);

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_fast_path'));
		self::assertSame('treatment', $context->getVariableValue('fast_path', 'default'));
		self::assertSame(1, $context->getPendingCount());
	}

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
		$first = self::withVariables(self::experiment(1, 'exp_refresh_first'), 'refresh_key');
		$second = self::withVariables(self::experiment(2, 'exp_refresh_second'), 'refresh_key');
		$oldData = self::data([], $first, $second);
		$holdout = self::holdout(11, 'holdout_refresh', self::HOLDOUT_A_SEED);
		$covered = self::coveredBy(self::experiment(3, 'exp_refresh_covered'), 11);
		$context = $this->createContext($hadCoverage ? self::data([$holdout], $covered) : $oldData,
			[self::UNIT_TYPE => self::UID], [], [], $logger);
		if ($hadCoverage) {
			$this->refresh($context, $oldData);
		}

		$failure = $typeError ? new \TypeError('mistyped field') : new \JsonException('malformed');
		$this->dataProvider->error = $failure;
		$logger->clear();
		$context->refresh();
		$this->dataProvider->error = null;

		self::assertSame($oldData, $context->getContextData());
		self::assertTrue($context->isReady());
		self::assertFalse($context->isFailed());
		self::assertSame($hadCoverage, self::property($context, 'hasHoldouts'));
		self::assertCount(1, $logger->events);
		self::assertSame(ContextEventLoggerEvent::Error, $logger->events[0]->getEvent());
		self::assertSame($failure, $logger->events[0]->getData());
		try {
			$context->getVariableValue('refresh_key', 'default');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertSame(1, $context->getPendingCount());
		$this->assertPublished($context, self::exposure(1, 'exp_refresh_first', self::NORMAL_VARIANT));
	}

	/**
	 * A logger that throws once on the Error event of a malformed experiment, so the refresh fails partway through
	 * installing the data, after setData() has cleared the holdout index.
	 */
	private static function throwOnceOnErrorLogger(): MockContextEventLoggerProxy {
		return new class extends MockContextEventLoggerProxy {
			public bool $armed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Error) {
					$this->armed = false;
					throw new \RuntimeException('logger rejected the malformed experiment');
				}
			}
		};
	}

	private static function malformedExperiment(int $id): object {
		$malformed = self::experiment($id, 'exp_malformed_' . $id);
		$malformed->audience = '{';
		return $malformed;
	}

	// On the first load an Error logger for a malformed experiment can read the context, which then loads the rest.
	public function testErrorLoggerDuringTheFirstLoadCanReadTheContext(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public ?array $seen = null;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($event->getEvent() === ContextEventLoggerEvent::Error) {
					$this->seen = [$context->getExperiments(), count($context->getContextData()->experiments)];
				}
			}
		};
		$context = $this->createContext(self::data([], self::malformedExperiment(1), self::experiment(2, 'exp_valid')),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		self::assertSame([[], 2], $logger->seen);
		self::assertTrue($context->isReady());
		self::assertFalse($context->isFailed());
		self::assertSame(['exp_valid'], $context->getExperiments());
	}

	// An Error logger reads the experiments the first load installed before the malformed one, and their holdouts.
	/** @dataProvider firstLoadCoverage */
	public function testErrorLoggerDuringTheFirstLoadReadsTheExperimentsLoadedBeforeIt(bool $covered): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public ?array $seen = null;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($event->getEvent() === ContextEventLoggerEvent::Error && $this->seen === null) {
					$this->seen = [$context->getExperiments(), $context->peekVariableValue('key', 'default'),
						$context->getTreatment('exp_valid')];
				}
			}
		};
		$valid = self::withVariables(self::experiment(1, 'exp_valid'), 'key');
		$holdouts = [];
		if ($covered) {
			$valid = self::coveredBy($valid, 11);
			$holdout = self::holdout(11, 'holdout_first_load', self::HOLDOUT_A_SEED);
			$holdout->split = [1.0, 0.0];
			$holdouts[] = $holdout;
		}
		$context = $this->createContext(self::data($holdouts, $valid, self::malformedExperiment(2)),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		$variant = $covered ? 0 : self::NORMAL_VARIANT;
		self::assertSame([['exp_valid'], $covered ? 'control' : 'treatment', $variant], $logger->seen);
		self::assertSame($variant, $context->getTreatment('exp_valid'));
		$this->assertPublished($context, $covered ? self::holdoutExposure(11, 'holdout_first_load', 0)
			: self::exposure(1, 'exp_valid', self::NORMAL_VARIANT));
	}

	// A refresh() an Error logger calls during the first load does nothing, since the rest of that load would replace
	// the refreshed data; the first load completes and emits Ready, not Refresh.
	public function testRefreshFromAnErrorLoggerDuringTheFirstLoadIsIgnored(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public bool $refreshed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($event->getEvent() === ContextEventLoggerEvent::Error && !$this->refreshed) {
					$this->refreshed = true;
					$context->refresh();
				}
			}
		};
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$this->dataProvider = new HoldoutDataProvider();
		$this->dataProvider->next = self::data([], self::experiment(3, 'exp_refreshed'));
		$config->setContextDataProvider($this->dataProvider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits([self::UNIT_TYPE => self::UID]);
		$contextConfig->setEventLogger($logger);
		$data = self::data([], self::malformedExperiment(1), self::experiment(2, 'exp_valid'));
		$context = (new SDK($config))->createContextWithData($contextConfig, $data);

		self::assertTrue($logger->refreshed);
		self::assertSame([ContextEventLoggerEvent::Error, ContextEventLoggerEvent::Ready],
			array_map(static fn($event) => $event->getEvent(), $logger->events));
		self::assertSame($data, $context->getContextData());
		self::assertSame(['exp_valid'], $context->getExperiments());

		$context->refresh();
		self::assertSame(['exp_refreshed'], $context->getExperiments());
	}

	// A first load that a throwing Error logger fails still lets a later refresh() run.
	public function testRefreshRunsAfterAFirstLoadThatFailed(): void {
		$logger = self::throwOnceOnErrorLogger();
		$logger->armed = true;
		$context = $this->createContext(self::data([], self::malformedExperiment(1), self::experiment(2, 'exp_valid')),
			[self::UNIT_TYPE => self::UID], [], [], $logger);
		self::assertTrue($context->isFailed());

		$this->refresh($context, self::data([], self::experiment(3, 'exp_refreshed')));
		self::assertSame(['exp_refreshed'], $context->getExperiments());
	}

	public static function firstLoadCoverage(): array {
		return ['without holdouts' => [false], 'held out by a holdout' => [true]];
	}

	// On the pending path an Error logger reads the data being loaded too; reading experiments there fails the context,
	// as in 1.1.0, since it isn't ready yet.
	public function testErrorLoggerDuringAPendingLoadCanReadTheData(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public ?int $seen = null;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($event->getEvent() === ContextEventLoggerEvent::Error && $this->seen === null) {
					$this->seen = count($context->getContextData()->experiments);
				}
			}
		};
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$this->eventHandler = new ContextEventHandlerMock($client);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits([self::UNIT_TYPE => self::UID]);
		$contextConfig->setEventLogger($logger);
		$context = Context::createPending(new SDK(new Config($client)), $contextConfig, new HoldoutDataProvider(), $this->eventHandler);

		$context->setContextData(self::data([], self::malformedExperiment(1), self::experiment(2, 'exp_valid')));

		self::assertSame(2, $logger->seen);
		self::assertFalse($context->isFailed());
		self::assertSame(['exp_valid'], $context->getExperiments());
	}

	public function testRefreshFailingMidInstallKeepsTheExperimentsHoldouts(): void {
		$logger = self::throwOnceOnErrorLogger();
		$holdout = self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED);
		$context = $this->createContext(self::data([$holdout], self::coveredBy(self::experiment(2, 'exp_covered'), 11)),
			[self::UNIT_TYPE => self::UID], [], [], $logger);

		$logger->armed = true;
		$this->refresh($context, self::data([$holdout], self::malformedExperiment(1),
			self::coveredBy(self::experiment(2, 'exp_covered'), 11)));

		self::assertSame(['exp_covered'], $context->getExperiments());
		self::assertSame(0, $context->getTreatment('exp_covered'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testRefreshFailingMidInstallDoesNotKeepItsFirstCoverage(): void {
		$logger = self::throwOnceOnErrorLogger();
		$data = self::data([], self::withVariables(self::experiment(1, 'exp_first'), 'key'),
			self::withVariables(self::experiment(2, 'exp_second'), 'key'));
		$context = $this->createContext($data, [self::UNIT_TYPE => self::UID], [], [], $logger);

		$logger->armed = true;
		$this->refresh($context, self::data([self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(3, 'exp_covered'), 11), self::malformedExperiment(4)));

		self::assertSame($data, $context->getContextData());
		self::assertFalse(self::property($context, 'hasHoldouts'));
	}

	// An Error logger peeks at an experiment while a refresh whose holdout split would release the unit is being
	// installed, then throws. The logger sees the previous data, and so does every later read: the unit stays held out.
	/** @dataProvider rejectedPeekIterations */
	public function testErrorLoggerDuringARejectedRefreshSeesAndKeepsThePreviousData(int $incomingIteration): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public bool $armed = false;
			public ?int $peek = null;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Error) {
					$this->armed = false;
					$this->peek = $context->peekTreatment('exp_covered');
					throw new \RuntimeException('reject install');
				}
			}
		};
		$covered = self::coveredBy(self::withVariables(self::experiment(1, 'exp_covered'), 'key'), 11);
		$winner = self::withVariables(self::experiment(3, 'exp_winner'), 'key');
		$holdout = self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED);
		$holdout->split = [1.0, 0.0];
		$oldData = self::data([$holdout], $covered, $winner);
		$context = $this->createContext($oldData, [self::UNIT_TYPE => self::UID], [], [], $logger);
		$incoming = clone $covered;
		$incoming->iteration = $incomingIteration;
		$incomingHoldout = clone $holdout;
		$incomingHoldout->split = [0.0, 1.0];
		$logger->armed = true;

		$this->refresh($context, self::data([$incomingHoldout], $incoming, self::malformedExperiment(2), $winner));

		self::assertSame(0, $logger->peek);
		self::assertSame(0, $context->getPendingCount());
		self::assertSame($oldData, $context->getContextData());
		self::assertSame('treatment', $context->getVariableValue('key', 'default'));
		self::assertSame(0, $context->peekTreatment('exp_covered'));
		self::assertSame('treatment', $context->getVariableValue('key', 'default'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0),
			self::exposure(3, 'exp_winner', self::NORMAL_VARIANT));
	}

	/** @dataProvider rejectedExposureCoverage */
	public function testRejectedRefreshKeepsExposuresAnErrorLoggerQueuedDeduplicated(bool $covered): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public bool $armed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Error) {
					$this->armed = false;
					$context->getTreatment('exp_same');
					throw new \RuntimeException('reject install');
				}
			}
		};
		$same = self::experiment(1, 'exp_same');
		$holdouts = [];
		if ($covered) {
			$same = self::coveredBy($same, 11);
			$holdouts[] = self::holdout(11, 'holdout_b', self::HOLDOUT_B_SEED);
		}
		$context = $this->createContext(self::data($holdouts, $same), [self::UNIT_TYPE => self::UID], [], [], $logger);
		$logger->armed = true;

		$this->refresh($context, self::data($holdouts, clone $same, self::malformedExperiment(2)));

		$pending = $context->getPendingCount();
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_same'));
		self::assertSame($pending, $context->getPendingCount());
		$expected = [self::exposure(1, 'exp_same', self::NORMAL_VARIANT)];
		if ($covered) {
			$expected[] = self::holdoutExposure(11, 'holdout_b', 1);
		}
		$this->assertPublished($context, ...$expected);
	}

	// The holdout arm an Error logger exposed is kept too, so another experiment it covers doesn't expose it again.
	public function testRejectedRefreshKeepsAHoldoutArmAnErrorLoggerExposed(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public bool $armed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Error) {
					$this->armed = false;
					$context->getTreatment('exp_same');
					throw new \RuntimeException('reject install');
				}
			}
		};
		$holdouts = [self::holdout(11, 'holdout_b', self::HOLDOUT_B_SEED)];
		$same = self::coveredBy(self::experiment(1, 'exp_same'), 11);
		$other = self::coveredBy(self::experiment(3, 'exp_other'), 11);
		$context = $this->createContext(self::data($holdouts, $same, $other), [self::UNIT_TYPE => self::UID], [], [], $logger);
		$logger->armed = true;

		$this->refresh($context, self::data($holdouts, clone $same, self::malformedExperiment(2), clone $other));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_other'));
		$this->assertPublished($context, self::exposure(1, 'exp_same', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_b', 1), self::exposure(3, 'exp_other', self::NORMAL_VARIANT));
	}

	// A decision exposed before the refresh wins over one an Error logger exposed from the rejected data: after the
	// rollback the restored definitions match it, so later reads queue nothing again.
	public function testRejectedRefreshKeepsTheDecisionExposedBeforeIt(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public bool $armed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Error) {
					$this->armed = false;
					$context->getTreatment('exp_same');
					throw new \RuntimeException('reject install');
				}
			}
		};
		$holdout = self::holdout(11, 'holdout_b', self::HOLDOUT_B_SEED);
		$same = self::coveredBy(self::experiment(1, 'exp_same'), 11);
		$other = self::coveredBy(self::experiment(3, 'exp_other'), 11);
		$context = $this->createContext(self::data([$holdout], $same, $other), [self::UNIT_TYPE => self::UID], [], [], $logger);
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_same'));
		$bumped = clone $holdout;
		$bumped->iteration = 2;
		$logger->armed = true;

		$this->refresh($context, self::data([$bumped], clone $same, self::malformedExperiment(2), clone $other));

		$pending = $context->getPendingCount();
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_same'));
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_other'));
		self::assertSame($pending + 1, $context->getPendingCount());
	}

	// A decision an Error logger exposed from the rejected data replaces a restored one that was only peeked, so its
	// queued exposure is not queued again by the next read.
	public function testRejectedRefreshKeepsAnExposedDecisionOverARestoredPeek(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public bool $armed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Error) {
					$this->armed = false;
					$context->getTreatment('exp_same');
					throw new \RuntimeException('reject install');
				}
			}
		};
		$same = self::experiment(1, 'exp_same');
		$same->audience = '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}';
		$context = $this->createContext(self::data([], $same), [self::UNIT_TYPE => self::UID], [], [], $logger);
		self::assertSame(self::NORMAL_VARIANT, $context->peekTreatment('exp_same'));
		$context->setAttributeValue('age', 30);
		$incoming = clone $same;
		$incoming->audience = '{"filter":[{"gte":[{"var":"age"},{"value":10}]}]}';
		$logger->armed = true;

		$this->refresh($context, self::data([], $incoming, self::malformedExperiment(2)));

		self::assertSame(1, $context->getPendingCount());
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_same'));
		$this->assertPublished($context, self::exposure(1, 'exp_same', self::NORMAL_VARIANT));
	}

	public static function rejectedExposureCoverage(): array {
		return ['without holdouts' => [false], 'covered by a holdout' => [true]];
	}

	// An Error logger that exposes the experiment during a rejected refresh whose iteration differs exposes it from the
	// previous data, so the next read reuses that decision and nothing is queued twice.
	public function testErrorLoggerExposureDuringARejectedRefreshIsNotQueuedAgain(): void {
		$logger = new class extends MockContextEventLoggerProxy {
			public bool $armed = false;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Error) {
					$this->armed = false;
					$context->getTreatment('exp_covered');
					throw new \RuntimeException('reject install');
				}
			}
		};
		$covered = self::coveredBy(self::withVariables(self::experiment(1, 'exp_covered'), 'key'), 11);
		$winner = self::withVariables(self::experiment(3, 'exp_winner'), 'key');
		$holdout = self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED);
		$holdout->split = [1.0, 0.0];
		$context = $this->createContext(self::data([$holdout], $covered, $winner), [self::UNIT_TYPE => self::UID], [], [], $logger);
		$incoming = clone $covered;
		$incoming->iteration = 2;
		$incomingHoldout = clone $holdout;
		$incomingHoldout->split = [0.0, 1.0];
		$logger->armed = true;

		$this->refresh($context, self::data([$incomingHoldout], $incoming, self::malformedExperiment(2), $winner));

		self::assertSame('treatment', $context->getVariableValue('key', 'default'));
		self::assertSame(0, $context->getTreatment('exp_covered'));
		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_a', 0),
			self::exposure(3, 'exp_winner', self::NORMAL_VARIANT));
	}

	public static function rejectedPeekIterations(): array {
		return ['same experiment iteration' => [1], 'changed experiment iteration' => [2]];
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

		self::assertFalse(self::property($context, 'hasHoldouts'));
		self::assertSame('treatment', $context->getVariableValue('unknown_fast_path', 'default'));
		self::assertSame(1, $context->getPendingCount());
	}

	/** @dataProvider loggerCloseOutcomes */
	public function testVariableWalkStopsOnlyAfterASuccessfulLoggerClose(int $closeAt, bool $closeSucceeds, bool $closeThrows): void {
		$order = [11 => 0, 12 => 1, 3 => 2];
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = function($exposure) use (&$context, $order, $closeAt, $closeThrows) {
			$at = $order[$exposure->id];
			if ($at === $closeAt) {
				$context->close();
			}
			if ($at < $closeAt || ($at === $closeAt && $closeThrows)) {
				throw new \RuntimeException('logger ' . $exposure->id);
			}
			return false;
		};
		$first = self::coveredBy(self::withVariables(self::experiment(1, 'exp_close_first'), 'close_key'), 11);
		$second = self::coveredBy(self::withVariables(self::experiment(2, 'exp_close_second'), 'close_key'), 12);
		$third = self::withVariables(self::experiment(3, 'exp_close_third'), 'close_key');
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_close_a', self::HOLDOUT_A_SEED),
			self::holdout(12, 'holdout_close_b', self::HOLDOUT_A_SEED)], $first, $second, $third),
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
			self::assertSame($closeSucceeds || $closeAt > 0 ? 'logger 11' : 'publish failed', $exception->getMessage());
		}

		self::assertSame($closeSucceeds, $context->isClosed());
		self::assertSame($closeSucceeds ? 0 : 3, $context->getPendingCount());
		self::assertSame($closeSucceeds ? array_slice(['exp_close_first', 'exp_close_second', 'exp_close_third'], 0, $closeAt + 1)
			: ['exp_close_first', 'exp_close_second', 'exp_close_third'], array_keys(self::property($context, 'assignmentCache')));
		$this->eventHandler->prerun = null;
		$context->close();
		self::assertSame(0, $context->getPendingCount());
		self::assertCount(1, $this->eventHandler->submitted);
		self::assertSame($closeSucceeds ? array_slice([11, 12], 0, $closeAt + 1) : [11, 12, 3],
			array_column($this->eventHandler->submitted[0]->exposures, 'id'));
	}

	// An attribute getter that closes the context while a logger error is pending also ends the walk, even though the
	// closing candidate (no unit, so not a winner) dispatches nothing.
	public function testVariableWalkStopsWhenAnAudienceCallbackClosesWithALoggerErrorPending(): void {
		$probe = static function () {
			return new class {
				public $score;
				public ?\Closure $onRead = null;
				public int $reads = 0;

				public function __construct() {
					unset($this->score);
				}

				public function __get(string $name) {
					++$this->reads;
					if ($this->onRead !== null) {
						($this->onRead)();
					}
					return 1;
				}
			};
		};
		$two = $probe();
		$three = $probe();
		$logger = new ThrowingExposureLogger();
		$logger->throwWhen = fn($exposure) => $exposure->id === 11;
		$first = self::coveredBy(self::withVariables(self::experiment(1, 'exp_callback_first'), 'callback_key'), 11);
		$second = self::withVariables(self::experiment(2, 'exp_callback_second', 'user_id'), 'callback_key');
		$second->audience = '{"filter":[{"gte":[{"var":"two/score"},{"value":0}]}]}';
		$third = self::withVariables(self::experiment(3, 'exp_callback_third'), 'callback_key');
		$third->audience = '{"filter":[{"gte":[{"var":"three/score"},{"value":0}]}]}';
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_callback', self::HOLDOUT_A_SEED)],
			$first, $second, $third), [self::UNIT_TYPE => self::UID], [], [], $logger);
		$context->setAttributeValue('two', $two);
		$context->setAttributeValue('three', $three);
		$two->onRead = function () use ($context, $two) {
			$two->onRead = null;
			$context->close();
		};

		try {
			$context->getVariableValue('callback_key', 'default');
			self::fail('the first logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('boom', $exception->getMessage());
		}

		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(1, $two->reads);
		self::assertSame(0, $three->reads);
		self::assertSame([11], array_column($this->eventHandler->submitted[0]->exposures, 'id'));
	}

	public static function loggerCloseOutcomes(): array {
		return [
			'first logger closes' => [0, true, true],
			'later logger closes after an earlier error' => [1, true, true],
			'later logger closes and returns after an earlier error' => [1, true, false],
			'failed close keeps walking' => [0, false, true],
		];
	}

	/**
	 * Experiment 1, held out by holdout 12 (its exposure is the refresh trigger) and defining $key only in variant 1,
	 * so its control supplies no fallback; then experiment 2 covered by holdout 11, whose UID variant (1) has $key.
	 */
	private static function heldOutThenCovered(string $key): array {
		$first = self::coveredBy(self::experiment(1, 'exp_first_' . $key), 12);
		$first->variants[0]->config = '{"unrelated":"first-control"}';
		$first->variants[1]->config = json_encode([$key => 'first-treatment']);
		$second = self::coveredBy(self::experiment(2, 'exp_second_' . $key), 11);
		$second->variants[1]->config = json_encode([$key => 'from_second']);
		return [$first, $second];
	}

	private static function midWalkHoldouts(): array {
		return [self::holdout(11, 'holdout_a', self::HOLDOUT_A_SEED), self::holdout(12, 'holdout_b', self::HOLDOUT_A_SEED),
			self::holdout(13, 'holdout_c', self::HOLDOUT_A_SEED)];
	}

	// An overridden candidate a mid-walk refresh removed keeps its override, as on main and in JS: it wins with the
	// override's value, its own (overridden) exposure and its holdout's exposure.
	public function testOverriddenCandidateRemovedByAMidWalkRefreshKeepsItsOverride(): void {
		$logger = self::refreshingLogger(12);
		[$first, $second] = self::heldOutThenCovered('refresh_override');
		$context = $this->createContext(self::data(self::midWalkHoldouts(), $first, $second), [self::UNIT_TYPE => self::UID],
			['exp_second_refresh_override' => 1], [], $logger);
		self::assertSame(1, $context->peekTreatment('exp_second_refresh_override'));
		[$first] = self::heldOutThenCovered('refresh_override');
		$this->dataProvider->next = self::data(self::midWalkHoldouts(), $first);
		$logger->next = $this->dataProvider->next;

		self::assertSame('from_second', $context->getVariableValue('refresh_override', 'default'));
		$this->assertPublished($context, self::holdoutExposure(12, 'holdout_b', 0),
			self::exposure(2, 'exp_second_refresh_override', 1, false, true), self::holdoutExposure(11, 'holdout_a', 0));
	}

	public function testWalkContinuesPastACandidateRemovedByAMidWalkRefresh(): void {
		$logger = self::refreshingLogger(12);
		[$first, $second] = self::heldOutThenCovered('refresh_skip');
		$third = self::withVariables(self::experiment(3, 'exp_third_refresh_skip'), 'refresh_skip');
		$context = $this->createContext(self::data(self::midWalkHoldouts(), $first, $second, $third),
			[self::UNIT_TYPE => self::UID], [], [], $logger);
		self::assertSame(0, $context->peekTreatment('exp_second_refresh_skip'));
		[$first] = self::heldOutThenCovered('refresh_skip');
		$third = self::withVariables(self::experiment(3, 'exp_third_refresh_skip'), 'refresh_skip');
		$this->dataProvider->next = self::data(self::midWalkHoldouts(), $first, $third);
		$logger->next = $this->dataProvider->next;

		self::assertSame('treatment', $context->getVariableValue('refresh_skip', 'default'));
		$this->assertPublished($context, self::holdoutExposure(12, 'holdout_b', 0),
			self::exposure(3, 'exp_third_refresh_skip', self::NORMAL_VARIANT));
	}

	public function testCandidateThatLosesTheKeyInAMidWalkRefreshIsSkipped(): void {
		$logger = self::refreshingLogger(12);
		[$first, $second] = self::heldOutThenCovered('refresh_lost');
		$third = self::withVariables(self::experiment(3, 'exp_third_refresh_lost'), 'refresh_lost');
		$context = $this->createContext(self::data(self::midWalkHoldouts(), $first, $second, $third),
			[self::UNIT_TYPE => self::UID], [], [], $logger);
		[$first, $second] = self::heldOutThenCovered('refresh_lost');
		$second->iteration = 2;
		$second->variants[1]->config = '{"other":"b-treatment"}';
		$third = self::withVariables(self::experiment(3, 'exp_third_refresh_lost'), 'refresh_lost');
		$this->dataProvider->next = self::data(self::midWalkHoldouts(), $first, $second, $third);
		$logger->next = $this->dataProvider->next;

		self::assertSame('treatment', $context->getVariableValue('refresh_lost', 'default'));
		$this->assertPublished($context, self::holdoutExposure(12, 'holdout_b', 0),
			self::exposure(3, 'exp_third_refresh_lost', self::NORMAL_VARIANT));
	}

	/** A logger that refreshes to each of $steps in turn, on the exposure of the experiment id each is keyed by. */
	private static function multiRefreshingLogger(): MockContextEventLoggerProxy {
		return new class extends MockContextEventLoggerProxy {
			/** @var array<int, ContextData> */
			public array $steps = [];
			public ?HoldoutDataProvider $provider = null;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				parent::handleEvent($context, $event);
				if ($event->getEvent() === ContextEventLoggerEvent::Exposure && isset($this->steps[$event->getData()->id])) {
					$this->provider->next = $this->steps[$event->getData()->id];
					unset($this->steps[$event->getData()->id]);
					$context->refresh();
				}
			}
		};
	}

	/**
	 * Experiments 1-4 for $key: 1 and 2 held out by holdouts 12 and 13, 3 covered by 11 (without $key unless
	 * $thirdKeepsKey), and 4, which wins.
	 */
	private static function fourCandidates(string $key, bool $thirdKeepsKey): array {
		$candidates = [];
		foreach ([1 => 12, 2 => 13] as $id => $holdoutId) {
			$candidates[] = self::coveredBy(self::withVariables(self::experiment($id, 'exp_' . $id . '_' . $key), $key), $holdoutId);
		}
		$third = self::coveredBy(self::experiment(3, 'exp_3_' . $key), 11);
		$third->variants[0]->config = json_encode($thirdKeepsKey ? [$key => 'c3'] : ['other' => 'c3']);
		$third->variants[1]->config = json_encode($thirdKeepsKey ? [$key => 't3'] : ['other' => 't3']);
		if (!$thirdKeepsKey) {
			$third->iteration = 2;
		}
		$candidates[] = $third;
		$fourth = self::experiment(4, 'exp_4_' . $key);
		$fourth->variants[1]->config = json_encode([$key => 't4']);
		$candidates[] = $fourth;
		return $candidates;
	}

	public function testLiveCandidatesAreRebuiltOnEachMidWalkRefresh(): void {
		$logger = self::multiRefreshingLogger();
		$holdouts = self::midWalkHoldouts();
		$context = $this->createContext(self::data($holdouts, ...self::fourCandidates('twice', true)),
			[self::UNIT_TYPE => self::UID], [], [], $logger);
		$logger->provider = $this->dataProvider;
		$logger->steps = [
			12 => self::data($holdouts, ...self::fourCandidates('twice', true)),
			13 => self::data($holdouts, ...self::fourCandidates('twice', false)),
		];

		self::assertSame('t4', $context->getVariableValue('twice', 'default'));
		$this->assertPublished($context, self::holdoutExposure(12, 'holdout_b', 0),
			self::holdoutExposure(13, 'holdout_c', 0), self::exposure(4, 'exp_4_twice', self::NORMAL_VARIANT));
	}

	// A candidate the refresh removed takes no part in the rest of the walk, even when a peek cached its suppressed
	// assignment: it queues no holdout exposure.
	public function testCandidateRemovedByAMidWalkRefreshIsSkippedEvenWhenItsSuppressedAssignmentIsCached(): void {
		$logger = self::refreshingLogger(12);
		[$first, $second] = self::heldOutThenCovered('refresh_removed');
		$context = $this->createContext(self::data(self::midWalkHoldouts(), $first, $second), [self::UNIT_TYPE => self::UID],
			[], [], $logger);
		self::assertSame(0, $context->peekTreatment('exp_second_refresh_removed'));
		[$first] = self::heldOutThenCovered('refresh_removed');
		$this->dataProvider->next = self::data(self::midWalkHoldouts(), $first);
		$logger->next = $this->dataProvider->next;

		self::assertSame('default', $context->getVariableValue('refresh_removed', 'default'));
		self::assertSame(['exp_first_refresh_removed'], $context->getExperiments());
		$this->assertPublished($context, self::holdoutExposure(12, 'holdout_b', 0));
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

	public function testReevaluatingOriginalExperimentAfterRefreshUsesInstalledHoldoutEpoch(): void {
		$context = $this->createContext(self::data([self::holdout(11, 'holdout_h', self::HOLDOUT_A_SEED)],
			self::coveredBy(self::experiment(1, 'exp_f'), 11)));

		self::assertSame(0, $context->getTreatment('exp_f'));

		$iteration2 = self::holdout(11, 'holdout_h', self::HOLDOUT_B_SEED);
		$iteration2->iteration = 2;
		$this->refresh($context, self::data([$iteration2], self::coveredBy(self::experiment(1, 'exp_f'), 11),
			self::coveredBy(self::experiment(2, 'exp_g'), 11)));

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_g'));
		self::assertSame(3, $context->getPendingCount());

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_f'));
		self::assertSame(4, $context->getPendingCount());

		$this->assertPublished($context, self::holdoutExposure(11, 'holdout_h', 0), self::exposure(2, 'exp_g', self::NORMAL_VARIANT),
			self::holdoutExposure(11, 'holdout_h', 1), self::exposure(1, 'exp_f', self::NORMAL_VARIANT));
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

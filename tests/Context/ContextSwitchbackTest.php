<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Switchback\SwitchbackAssigner;
use ABSmartly\SDK\Utils;
use ABSmartly\SDK\VariantAssigner;
use PHPUnit\Framework\TestCase;

/**
 * Ports java-sdk ContextSwitchbackTest (SWITCHBACK.md). The java concurrency and refresh-from-audience deadlock cases
 * have no PHP counterpart: a PHP context runs on one thread and holds no lock across the audience matcher.
 */
class ContextSwitchbackTest extends TestCase {
	use SwitchbackTestSupport;

	private const ASSIGN_VARIANT_1 = '{"rules":[{"type":"assign","environments":[],"variant":1}]}';
	private const ASSIGN_VARIANT_2 = '{"rules":[{"type":"assign","environments":[],"variant":2}]}';
	private const ASSIGN_VARIANT_1_TO_US = '{"rules":[{"type":"assign","environments":[],'
		. '"conditions":{"and":[{"eq":[{"var":"country"},{"value":"US"}]}]},"variant":1}]}';
	// An audience the default context never matches (no "tier" attribute).
	private const STRICT_GOLD_AUDIENCE = '{"filter":[{"eq":[{"var":"tier"},{"value":"gold"}]}]}';

	private static function seed(): int {
		return (self::$SEED_HI << 32) | (self::$SEED_LO & 0xFFFFFFFF);
	}

	private function peekSchedule(Context $context, string $name, int $periodMs, int $periods): array {
		$result = [];
		for ($i = 0; $i < $periods; ++$i) {
			$this->now = self::$ANCHOR + $i * $periodMs;
			$result[] = $context->peekTreatment($name);
		}
		return $result;
	}

	private static function sbExposure(int $variant, int $exposedAt, bool $assigned = true, bool $eligible = true, int $id = 1, string $name = 'exp_sb'): array {
		return self::exposure($id, $name, self::$UNIT_TYPE, $variant, $exposedAt, $assigned, $eligible);
	}

	private static function ruleExposure(string $name, int $variant, int $exposedAt, int $id = 1): array {
		return self::exposure($id, $name, self::$UNIT_TYPE, $variant, $exposedAt, false, true, false, false, false, false, true);
	}

	private static function strictMismatchExposure(int $exposedAt): array {
		return self::exposure(1, 'exp_sb', self::$UNIT_TYPE, 0, $exposedAt, false, true, false, false, false, true, false);
	}

	private static function holdout(array $split): object {
		return (object) [
			'id' => 11, 'name' => 'holdout_a', 'unitType' => self::$UNIT_TYPE, 'iteration' => 1, 'seedHi' => 13,
			'seedLo' => 111, 'split' => $split, 'trafficSeedHi' => 0, 'trafficSeedLo' => 0, 'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => 0, 'applications' => [],
			'variants' => [(object) ['name' => 'A', 'config' => null], (object) ['name' => 'B', 'config' => null]],
			'audience' => null, 'audienceStrict' => false, 'holdoutType' => 'full',
		];
	}

	/** The cluster identity is the raw attribute value, neither hashed nor the unit uid. */
	public function testClusterIdentityIsTheRawClusterValue(): void {
		self::assertSame(self::$UID_HASH, Utils::hashUnit(self::$UID));

		$context = $this->createContext(self::dataOf(self::switchback(3)));
		$served = $this->peekSchedule($context, 'exp_sb', self::$HOUR, 12);

		self::assertSame(self::$EXPECTED_3_ARMS, $served);
		self::assertNotSame(self::$UID_HASH_3_ARMS, $served);
		self::assertNotSame(self::$UID_3_ARMS, $served);
		$hashedKey = Utils::hashUnit(self::$CLUSTER_KEY);
		$hashedDiffers = false;
		for ($i = 0; $i < 12; ++$i) {
			self::assertSame(SwitchbackAssigner::assign(self::$CLUSTER_KEY, $i, self::seed(), 3), $served[$i]);
			$hashedDiffers = $hashedDiffers || SwitchbackAssigner::assign($hashedKey, $i, self::seed(), 3) !== $served[$i];
		}
		self::assertTrue($hashedDiffers);
	}

	public function testServesTwoThreeAndTenArmPermutations(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2, null, 1, 'exp_2'),
			self::switchback(3, null, 2, 'exp_3'), self::switchback(10, null, 3, 'exp_10')));

		self::assertSame(self::$EXPECTED_2_ARMS, $this->peekSchedule($context, 'exp_2', self::$HOUR, 12));
		self::assertSame(self::$EXPECTED_3_ARMS, $this->peekSchedule($context, 'exp_3', self::$HOUR, 12));
		self::assertSame(self::$EXPECTED_10_ARMS, $this->peekSchedule($context, 'exp_10', self::$HOUR, 12));
	}

	public function testOneHourBoundaryAtExactEdgeMilliseconds(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$hour = self::$HOUR;
		foreach ([[-1, 0], [0, self::$EXPECTED_2_ARMS[0]], [$hour - 1, self::$EXPECTED_2_ARMS[0]],
			[$hour, self::$EXPECTED_2_ARMS[1]], [3 * $hour - 1, self::$EXPECTED_2_ARMS[2]], [3 * $hour, self::$EXPECTED_2_ARMS[3]]] as [$offset, $variant]) {
			$this->now = self::$ANCHOR + $offset;
			self::assertSame($variant, $context->peekTreatment('exp_sb'), (string) $offset);
		}
	}

	public function testTwentyFourHourBoundaryAtExactEdgeMilliseconds(): void {
		$day = 24 * self::$HOUR;
		$context = $this->createContext(self::dataOf(self::switchback(2, $day)));
		foreach ([[-1, 0], [0, self::$EXPECTED_2_ARMS[0]], [self::$HOUR, self::$EXPECTED_2_ARMS[0]],
			[$day - 1, self::$EXPECTED_2_ARMS[0]], [$day, self::$EXPECTED_2_ARMS[1]], [4 * $day - 1, self::$EXPECTED_2_ARMS[3]],
			[4 * $day, self::$EXPECTED_2_ARMS[4]]] as [$offset, $variant]) {
			$this->now = self::$ANCHOR + $offset;
			self::assertSame($variant, $context->peekTreatment('exp_sb'), (string) $offset);
		}
	}

	/**
	 * Before the anchor the experiment is an ineligible control that records no exposure. An anchor that is not hour
	 * aligned is unservable, never served as-is and never rounded: ineligible control in every period, no exposure.
	 */
	public function testUnalignedStartIsUnservableAndPreAnchorIsIneligible(): void {
		$aligned = self::switchback(2, null, 1, 'exp_aligned');
		$aligned->switchbackStartAt = self::$ANCHOR + self::$HOUR;
		$unaligned = self::switchback(2, null, 2, 'exp_unaligned');
		$start = self::$ANCHOR + 1234567;
		$unaligned->switchbackStartAt = $start;
		$context = $this->createContext(self::dataOf($aligned, $unaligned));

		foreach ([self::$ANCHOR, $start - 1, $start, self::$ANCHOR + self::$HOUR - 1] as $now) {
			$this->now = $now;
			self::assertSame(0, $context->getTreatment('exp_aligned'));
			self::assertSame(0, $context->getTreatment('exp_unaligned'));
		}
		self::assertSame(0, $context->getPendingCount());
		for ($i = 1; $i < 4; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(self::$EXPECTED_2_ARMS[$i - 1], $context->getTreatment('exp_aligned'));
			self::assertSame(0, $context->getTreatment('exp_unaligned'));
			$this->now = $start + $i * self::$HOUR;
			self::assertSame(0, $context->getTreatment('exp_unaligned'));
		}

		self::assertSame([
			self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR + self::$HOUR, true, true, 1, 'exp_aligned'),
			self::sbExposure(self::$EXPECTED_2_ARMS[1], self::$ANCHOR + 2 * self::$HOUR, true, true, 1, 'exp_aligned'),
			self::sbExposure(self::$EXPECTED_2_ARMS[2], self::$ANCHOR + 3 * self::$HOUR, true, true, 1, 'exp_aligned'),
		], $this->publishExposures($context));
	}

	/** The Registry's anchor bounds: hour aligned and in [0, 10000-01-01T00:00:00Z); anything else is unservable. */
	public function testAnchorNotOnAnHourBoundaryIsUnservableOnGetAndPost(): void {
		$hour = self::$HOUR;
		$maxAnchor = 253402300800000;
		foreach ([self::$ANCHOR + 1, self::$ANCHOR - 1, self::$ANCHOR + 30 * 60000, self::$ANCHOR + $hour - 1, -$hour, -1,
			$maxAnchor, $maxAnchor + $hour, PHP_INT_MAX - (PHP_INT_MAX % $hour), PHP_INT_MIN, PHP_INT_MAX] as $startAt) {
			$experiment = self::switchback(2);
			$experiment->switchbackStartAt = $startAt;
			// The cluster attribute is set at context creation, before every resolution below.
			$this->now = 0;
			$context = $this->createContext(self::dataOf($experiment));
			foreach ([0, self::$ANCHOR, self::$ANCHOR + 2 * $hour + 1, $startAt, $maxAnchor + 24 * $hour, PHP_INT_MAX, PHP_INT_MIN] as $now) {
				$this->now = $now;
				self::assertSame(0, $context->getTreatment('exp_sb'), "startAt $startAt now $now");
				self::assertSame(0, $context->getVariableValue('sb.arm', -1));
			}
			self::assertSame(0, $context->getPendingCount(), "startAt $startAt");
			$context->publish();
			self::assertSame([], $this->eventHandler->submitted);
		}

		foreach ([0, self::$ANCHOR, $maxAnchor - $hour] as $startAt) {
			$experiment = self::switchback(2);
			$experiment->switchbackStartAt = $startAt;
			$this->now = $startAt + $hour;
			$context = $this->createContext(self::dataOf($experiment));
			self::assertSame(self::$EXPECTED_2_ARMS[1], $context->getTreatment('exp_sb'), "startAt $startAt");
			self::assertSame(1, $context->getPendingCount());
		}
	}

	/** A clock reading far from the anchor never overflows: the period index uses exact 64-bit floor division. */
	public function testExtremeClockReadingsServeTheFlooredPeriod(): void {
		$experiment = self::switchback(3);
		$experiment->switchbackStartAt = 0;
		$this->now = 0;
		$context = $this->createContext(self::dataOf($experiment));
		$this->now = PHP_INT_MAX;
		$period = intdiv(PHP_INT_MAX, self::$HOUR);
		$expected = SwitchbackAssigner::assign(self::$CLUSTER_KEY, $period, self::seed(), 3);
		self::assertSame($expected, $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
		// Exposure::$exposedAt is a float, so only the variant is compared at this instant.
		self::assertSame($expected, $this->logger->events[1]->getData()->variant);
		$this->now = PHP_INT_MIN;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testNullStartAtIsIneligibleAndNeverFallsBackToUnitAssignment(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackStartAt = null;
		$context = $this->createContext(self::dataOf($experiment));

		for ($i = 0; $i < 4; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(0, $context->getTreatment('exp_sb'));
			// Variant 0's own config, like any ineligible assignment.
			self::assertSame(0, $context->getVariableValue('sb.arm', -1));
		}
		self::assertSame(0, $context->getPendingCount());
	}

	public function testSamePeriodIsCachedAndNextPeriodRollsOverWithOneExposureEach(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));

		$this->now = self::$ANCHOR + 10;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
		$this->now = self::$ANCHOR + self::$HOUR - 1;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->peekTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());

		$this->now = self::$ANCHOR + self::$HOUR;
		self::assertSame(self::$EXPECTED_2_ARMS[1], $context->peekTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(self::$EXPECTED_2_ARMS[1], $context->getTreatment('exp_sb'));
		self::assertSame(2, $context->getPendingCount());
		$this->now = self::$ANCHOR + self::$HOUR + 30 * 60000;
		self::assertSame(self::$EXPECTED_2_ARMS[1], $context->getTreatment('exp_sb'));
		self::assertSame(2, $context->getPendingCount());

		self::assertSame([
			self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR + 10),
			self::sbExposure(self::$EXPECTED_2_ARMS[1], self::$ANCHOR + self::$HOUR),
		], $this->publishExposures($context));
	}

	public function testVariableLookupsFollowThePeriodAndExposeOncePerPeriod(): void {
		$context = $this->createContext(self::dataOf(self::switchback(3)));

		self::assertSame(['sb.arm' => 'exp_sb'], $context->getVariableKeys());
		self::assertSame(['exp_sb'], $context->getExperiments());

		for ($i = 0; $i < 3; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(self::$EXPECTED_3_ARMS[$i], $context->peekVariableValue('sb.arm', -1));
			self::assertSame(self::$EXPECTED_3_ARMS[$i], $context->getVariableValue('sb.arm', -1));
			self::assertSame(self::$EXPECTED_3_ARMS[$i], $context->getVariableValue('sb.arm', -1));
			self::assertSame(self::$EXPECTED_3_ARMS[$i], $context->getTreatment('exp_sb'));
		}
		self::assertSame(3, $context->getPendingCount());
	}

	/** No unit of a linked type: the experiment does not apply and records nothing. unitType is never a fallback. */
	public function testMissingClusterUnitIsUnassignedWithoutFallback(): void {
		$experiment = self::switchback(2);
		$experiment->unitType = 'user_id';
		$context = $this->createContext(self::dataOf($experiment), ['user_id' => self::$UID]);

		for ($i = 0; $i < 3; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(0, $context->peekTreatment('exp_sb'));
			self::assertSame(0, $context->getTreatment('exp_sb'));
			self::assertSame(-1, $context->getVariableValue('sb.arm', -1));
		}
		self::assertSame(0, $context->getPendingCount());

		$context->setUnit(self::$UNIT_TYPE, self::$UID);
		self::assertSame(self::$EXPECTED_2_ARMS[2], $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(self::$EXPECTED_2_ARMS[2], self::$ANCHOR + 2 * self::$HOUR)], $this->publishExposures($context));
	}

	/** A late linked unit re-resolves a slot even without a period change, and an exposed override is kept. */
	public function testSetUnitReresolvesAnAbsentSlotInTheSamePeriod(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2, null, 1, 'exp_sb'), self::switchback(2, null, 2, 'exp_override')), ['user_id' => 'u']);
		$context->setOverride('exp_override', 1);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getTreatment('exp_override'));
		self::assertSame(1, $context->getPendingCount());

		$context->setUnit(self::$UNIT_TYPE, self::$UID);
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getTreatment('exp_override'));
		self::assertSame([
			self::exposure(2, 'exp_override', self::$UNIT_TYPE, 1, self::$ANCHOR + 10, false, true, true),
			self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR + 10),
		], $this->publishExposures($context));
		// The override was queued with no linked unit, so it is published with the one set since, which the Collector
		// needs to record it.
		self::assertSame([[['user_id', self::$UNIT_TYPE], ['exp_override', 'exp_sb'], 0]], $this->publishedBatches());
	}

	/** A slot resolved with no linked unit is never reused as the serving resolution once the unit is set. */
	public function testAnAbsentResolutionIsNotReusedAfterSetUnit(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters('device_id', 'device_key', self::$UNIT_TYPE, self::$CLUSTER_PATH);
		// No linked unit: absent, and the override is recorded under the first declared type.
		$context = $this->createContext(self::dataOf($experiment), ['user_id' => 'u1'], []);
		$context->setOverride('exp_sb', 1);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(1, $context->getTreatment('exp_sb'));
		$context->setUnit(self::$UNIT_TYPE, self::$UID);
		$context->setAttributeValue(self::$CLUSTER_PATH, self::$CLUSTER_KEY);
		$context->setOverride('exp_sb', 0);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(['device_id', self::$UNIT_TYPE], array_column($this->publishExposures($context), 2));
	}

	public function testFullOnServesTheFullOnVariantInEveryPeriod(): void {
		$experiment = self::switchback(3);
		$experiment->fullOnVariant = 2;
		$context = $this->createContext(self::dataOf($experiment));

		for ($i = 0; $i < 4; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(2, $context->getTreatment('exp_sb'));
		}
		$exposures = $this->publishExposures($context);
		self::assertCount(4, $exposures);
		foreach ($exposures as $exposure) {
			self::assertSame(2, $exposure[3]);
			self::assertTrue($exposure[8]);
		}
	}

	public function testOverrideAndCustomAssignmentWinInEveryPeriod(): void {
		$context = $this->createContext(self::dataOf(self::switchback(3, null, 1, 'exp_override'), self::switchback(3, null, 2, 'exp_custom')));
		$context->setOverride('exp_override', 7);
		$context->setCustomAssignment('exp_custom', 2);

		for ($i = 0; $i < 3; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(7, $context->getTreatment('exp_override'));
			self::assertSame(2, $context->getTreatment('exp_custom'));
		}
		$exposures = $this->publishExposures($context);
		self::assertCount(6, $exposures);
		foreach ($exposures as $exposure) {
			if ($exposure[1] === 'exp_override') {
				self::assertSame(self::exposure(1, 'exp_override', self::$UNIT_TYPE, 7, $exposure[4], false, true, true), $exposure);
			}
			else {
				self::assertSame(self::exposure(2, 'exp_custom', self::$UNIT_TYPE, 2, $exposure[4], true, true, false, false, true), $exposure);
			}
		}
	}

	/** Traffic comes before the custom assignment: a traffic miss serves control, recorded eligible=false. */
	public function testTrafficMissWinsOverACustomAssignment(): void {
		$experiment = self::switchback(3);
		$experiment->trafficSplit = [1.0, 0.0];
		$context = $this->createContext(self::dataOf($experiment));
		$context->setCustomAssignment('exp_sb', 2);
		$this->now = self::$ANCHOR + self::$HOUR + 1;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR + self::$HOUR + 1, true, false)], $this->publishExposures($context));
	}

	/** A custom assignment set or changed within a period re-resolves the slot, as for a unit experiment. */
	public function testCustomAssignmentChangeWithinAPeriodReresolves(): void {
		$context = $this->createContext(self::dataOf(self::switchback(3)));
		$this->now = self::$ANCHOR + self::$HOUR + 1;
		self::assertSame(self::$EXPECTED_3_ARMS[1], $context->getTreatment('exp_sb'));
		$context->setCustomAssignment('exp_sb', 0);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(2, $context->getPendingCount());
	}

	/** Traffic eligibility uses the per-unit traffic hash, matching a unit-randomized twin, fixed across periods. */
	public function testTrafficEligibilityIsClusterSticky(): void {
		$eligibleCount = 0;
		$ineligibleCount = 0;
		for ($u = 0; $u < 24; ++$u) {
			$sb = self::switchback(2, null, 1, 'exp_sb');
			$sb->trafficSplit = [0.5, 0.5];
			$twin = self::unitExperiment(2, 2, 'exp_unit');
			$twin->trafficSplit = [0.5, 0.5];

			$this->now = self::$ANCHOR;
			$context = $this->createContext(self::dataOf($sb, $twin), [self::$UNIT_TYPE => 'store_' . $u]);
			$context->getTreatment('exp_unit');
			for ($i = 0; $i < 4; ++$i) {
				$this->now = self::$ANCHOR + $i * self::$HOUR;
				$context->getTreatment('exp_sb');
			}

			$exposures = $this->publishExposures($context);
			self::assertCount(5, $exposures);
			$twinEligible = $exposures[0][6];
			for ($i = 1; $i < 5; ++$i) {
				self::assertSame('exp_sb', $exposures[$i][1]);
				self::assertSame($twinEligible, $exposures[$i][6]);
				if (!$twinEligible) {
					self::assertSame(0, $exposures[$i][3]);
				}
			}
			$twinEligible ? ++$eligibleCount : ++$ineligibleCount;
		}
		self::assertGreaterThan(0, $eligibleCount);
		self::assertGreaterThan(0, $ineligibleCount);
	}

	public function testUnitExperimentsAreUnaffected(): void {
		$unit = self::switchback(2);
		$unit->randomizationType = 'unit';
		$absent = self::switchback(2, null, 2, 'exp_absent');
		unset($absent->randomizationType);
		$other = self::switchback(2, null, 3, 'exp_other');
		$other->randomizationType = 'Switchback';

		$context = $this->createContext(self::dataOf($unit, $absent, $other));
		$variants = [];
		foreach (['exp_sb', 'exp_absent', 'exp_other'] as $name) {
			$variants[$name] = $context->getTreatment($name);
			self::assertSame((new VariantAssigner(self::$UID))->assign([0.5, 0.5], self::$SEED_HI, self::$SEED_LO), $variants[$name]);
		}
		for ($i = 1; $i < 6; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			foreach ($variants as $name => $variant) {
				self::assertSame($variant, $context->getTreatment($name));
			}
		}
		self::assertSame(3, $context->getPendingCount());
	}

	public function testRefreshInvalidatesWhenGridFieldsChangeButNotForAnalysisOnlyFields(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());

		$burnsChanged = self::switchback(2);
		$burnsChanged->switchbackBurnInDurationMs = 600000;
		$burnsChanged->switchbackBurnOutDurationMs = 300000;
		$burnsChanged->switchbackCarryoverDurationMs = 900000;
		$burnsChanged->switchbackEstimand = 'other';
		// Seed and split edits keep a cached slot too.
		$burnsChanged->seedHi = 99;
		$this->refreshTo($context, $burnsChanged);
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());

		// Same period index 0 under both grids, so only the config comparison can invalidate.
		$this->refreshTo($context, self::switchback(2, 2 * self::$HOUR));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(2, $context->getPendingCount());

		$designChanged = self::switchback(2, 2 * self::$HOUR);
		$designChanged->switchbackDesignType = 'other_design';
		$this->refreshTo($context, $designChanged);
		$context->getTreatment('exp_sb');
		self::assertSame(3, $context->getPendingCount());

		$toUnit = self::switchback(2, 2 * self::$HOUR);
		$toUnit->randomizationType = 'unit';
		$this->refreshTo($context, $toUnit);
		$context->getTreatment('exp_sb');
		self::assertSame(4, $context->getPendingCount());

		$this->refreshTo($context, self::switchback(2, 2 * self::$HOUR));
		$context->getTreatment('exp_sb');
		self::assertSame(5, $context->getPendingCount());

		// The cluster map (types, paths and their order) and the allow-list are identity.
		$pathChanged = self::switchback(2, 2 * self::$HOUR);
		$pathChanged->switchbackClusters = self::clusters(self::$UNIT_TYPE, "'store_key'");
		$this->refreshTo($context, $pathChanged);
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(6, $context->getPendingCount());

		$linkAdded = self::switchback(2, 2 * self::$HOUR);
		$linkAdded->switchbackClusters = self::clusters(self::$UNIT_TYPE, "'store_key'", 'device_id', 'city');
		$this->refreshTo($context, $linkAdded);
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(7, $context->getPendingCount());

		$linksReordered = self::switchback(2, 2 * self::$HOUR);
		$linksReordered->switchbackClusters = self::clusters('device_id', 'city', self::$UNIT_TYPE, "'store_key'");
		$this->refreshTo($context, $linksReordered);
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(8, $context->getPendingCount());

		$valuesSet = self::switchback(2, 2 * self::$HOUR);
		$valuesSet->switchbackClusters = $linksReordered->switchbackClusters;
		$valuesSet->switchbackClusterValues = [self::$CLUSTER_KEY];
		$this->refreshTo($context, $valuesSet);
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(9, $context->getPendingCount());

		// An unrelated unitType edit is not identity: switchbacks never read it.
		$unitTypeChanged = self::switchback(2, 2 * self::$HOUR);
		$unitTypeChanged->switchbackClusters = $valuesSet->switchbackClusters;
		$unitTypeChanged->switchbackClusterValues = $valuesSet->switchbackClusterValues;
		$unitTypeChanged->unitType = 'anything';
		$this->refreshTo($context, $unitTypeChanged);
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(9, $context->getPendingCount());

		// Nor are the id, the iteration or full-on when unchanged; a new iteration is.
		$iteration = clone $unitTypeChanged;
		$iteration->iteration = 2;
		$this->refreshTo($context, $iteration);
		$context->getTreatment('exp_sb');
		self::assertSame(10, $context->getPendingCount());
	}

	public function testRefreshSettingStartAtStartsTheGrid(): void {
		$pending = self::switchback(2);
		$pending->switchbackStartAt = null;
		$context = $this->createContext(self::dataOf($pending));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->getTreatment('exp_sb'));

		$this->refreshTo($context, self::switchback(2));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
	}

	/** Holdouts never apply to a switchback: neither suppression nor the holdout's own exposure. */
	public function testHoldoutSuppressionAndExposureSpanPeriods(): void {
		// split [0.1, 0.9] -> arm 0 (held out) for UID.
		$covered = self::switchback(2);
		$covered->holdoutIds = [11];
		$context = $this->createContext(new ContextData([$covered], [self::holdout([0.1, 0.9])]));
		for ($i = 0; $i < 3; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(self::$EXPECTED_2_ARMS[$i], $context->getTreatment('exp_sb'));
			self::assertSame(self::$EXPECTED_2_ARMS[$i], $context->getVariableValue('sb.arm', -1));
		}

		self::assertSame([
			self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR),
			self::sbExposure(self::$EXPECTED_2_ARMS[1], self::$ANCHOR + self::$HOUR),
			self::sbExposure(self::$EXPECTED_2_ARMS[2], self::$ANCHOR + 2 * self::$HOUR),
		], $this->publishExposures($context));
		// Not even resolved: no holdout state exists for a switchback, so unit reads keep their holdout-free path.
		self::assertSame([], self::privateValue($context, 'experimentHoldouts'));
		self::assertFalse(self::privateValue($context, 'hasHoldouts'));
	}

	/** A holdout covering both a unit experiment and a switchback is exposed through the unit one only. */
	public function testAHoldoutSharedWithAUnitExperimentFiresOnlyThroughIt(): void {
		$covered = self::switchback(2);
		$covered->holdoutIds = [11];
		$unit = self::unitExperiment(2, 2, 'exp_unit');
		$unit->holdoutIds = [11];
		$context = $this->createContext(new ContextData([$covered, $unit], [self::holdout([0.0, 1.0])]));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertCount(1, $this->loggedExposures());
		$context->getTreatment('exp_unit');
		$names = array_column($this->publishExposures($context), 1);
		self::assertSame(['exp_sb', 'exp_unit', 'holdout_a'], $names);
	}

	/** A matching rule assigns its variant even before the anchor, with ruleOverride set and the exposure recorded. */
	public function testAssignmentRuleOverridesTheSchedule(): void {
		$experiment = self::switchback(2);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		for ($u = 0; $u < 10; ++$u) {
			$this->now = self::$ANCHOR - 1;
			$context = $this->createContext(self::dataOf($experiment), [self::$UNIT_TYPE => 'user-' . $u]);
			self::assertSame(1, $context->getTreatment('exp_sb'));
			self::assertSame(1, $context->getVariableValue('sb.arm', -1));
			// The rule also beats every period's schedule draw.
			for ($i = 0; $i < 4; ++$i) {
				$this->now = self::$ANCHOR + $i * self::$HOUR;
				self::assertSame(1, $context->getTreatment('exp_sb'));
			}
			$exposures = $this->publishExposures($context);
			self::assertCount(5, $exposures);
			self::assertSame(self::ruleExposure('exp_sb', 1, self::$ANCHOR - 1), $exposures[0]);
			for ($i = 0; $i < 4; ++$i) {
				self::assertSame(self::ruleExposure('exp_sb', 1, self::$ANCHOR + $i * self::$HOUR), $exposures[$i + 1]);
			}
		}
	}

	/** An unservable schedule only replaces the draw: full-on and a matching rule still apply and are recorded. */
	public function testUnservableOnlyReplacesTheVariantDrawNotFullOnOrRules(): void {
		$fullOn = self::switchback(2, null, 1, 'sb_full_on');
		$fullOn->switchbackStartAt = null;
		$fullOn->fullOnVariant = 1;
		$rules = self::switchback(2, null, 2, 'sb_rules');
		$rules->switchbackPeriodMs = 0;
		$rules->assignmentRules = self::ASSIGN_VARIANT_1;
		$unalignedRules = self::switchback(2, null, 3, 'sb_unaligned_rules');
		$unalignedRules->switchbackStartAt = self::$ANCHOR + 1;
		$unalignedRules->assignmentRules = self::ASSIGN_VARIANT_1;
		$context = $this->createContext(self::dataOf($fullOn, $rules, $unalignedRules), [self::$UNIT_TYPE => '15']);

		self::assertSame(1, $context->getTreatment('sb_full_on'));
		self::assertSame(1, $context->getTreatment('sb_rules'));
		self::assertSame(1, $context->getTreatment('sb_unaligned_rules'));
		self::assertSame([
			self::exposure(1, 'sb_full_on', self::$UNIT_TYPE, 1, self::$ANCHOR, true, true, false, true),
			self::ruleExposure('sb_rules', 1, self::$ANCHOR, 2),
			self::ruleExposure('sb_unaligned_rules', 1, self::$ANCHOR, 3),
		], $this->publishExposures($context));
	}

	/** Rules are evaluated only for an eligible cluster. */
	public function testAssignmentRulesDoNotApplyToAnIneligibleCluster(): void {
		$experiment = self::switchback(2);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		$experiment->switchbackClusterValues = ['other-store'];
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getPendingCount());
	}

	/** Traffic on the serving unit uses the platform murmur3 on every runtime (store-32476 differs on PHP 7.4/8.0). */
	public function testTrafficOnTheServingUnitMatchesThePlatformOnEveryRuntime(): void {
		$experiment = self::switchback(2);
		$experiment->trafficSplit = [0.5, 0.5];
		$context = $this->createContext(self::dataOf($experiment), [self::$UNIT_TYPE => 'store-32476']);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR, true, false)], $this->publishExposures($context));

		$rule = self::switchback(2);
		$rule->assignmentRules = '{"rules":[{"type":"split","environments":[],"percentages":"50/50"}]}';
		$rule->seedHi = 1;
		$rule->seedLo = 2;
		$context = $this->createContext(self::dataOf($rule), [self::$UNIT_TYPE => 'store-32476']);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		// Rule-won: a skipped rule would publish the same arm without ruleOverride.
		self::assertSame([self::ruleExposure('exp_sb', 0, self::$ANCHOR)], $this->publishExposures($context));

		foreach ([['not-a-number', 1], [null, 1], [false, true]] as $trafficSplit) {
			$malformed = self::switchback(2);
			$malformed->trafficSplit = $trafficSplit;
			$context = $this->createContext(self::dataOf($malformed), [self::$UNIT_TYPE => 'store-32476']);
			try {
				$context->getTreatment('exp_sb');
				self::fail('expected InvalidArgumentException for ' . json_encode($trafficSplit));
			}
			catch (\InvalidArgumentException $exception) {
				self::assertStringContainsString('must be an array of float values', $exception->getMessage());
			}
			self::assertSame(0, $context->getPendingCount());
		}

		// Unit experiments keep VariantAssigner, so the same unit is bucketed as before on each runtime: lastguest's
		// draw on PHP 7.4/8.0, native murmur3 on 8.1+.
		$legacy = PHP_VERSION_ID >= 80100 ? 0 : 1;
		$unit = self::unitExperiment();
		$unit->trafficSplit = [0.5, 0.5];
		$unit->trafficSeedHi = 1;
		$unit->trafficSeedLo = 2;
		$context = $this->createContext(self::dataOf($unit), [self::$UNIT_TYPE => 'store-32476']);
		$context->getTreatment('exp_unit');
		self::assertSame($legacy === 1, $this->publishExposures($context)[0][6]);
		$unitRule = self::unitExperiment();
		$unitRule->assignmentRules = $rule->assignmentRules;
		$unitRule->seedHi = 1;
		$unitRule->seedLo = 2;
		$context = $this->createContext(self::dataOf($unitRule), [self::$UNIT_TYPE => 'store-32476']);
		self::assertSame($legacy, $context->getTreatment('exp_unit'));
	}

	/** A split rule randomizes over the serving unit, not over the payload's unitType. */
	public function testSplitRuleRandomizesOverTheServingUnit(): void {
		$experiment = self::switchback(2);
		$experiment->unitType = 'user_id';
		$experiment->assignmentRules = '{"rules":[{"type":"split","environments":[],"percentages":"50/50"}]}';
		$ones = 0;
		for ($u = 0; $u < 32; ++$u) {
			$uid = 'store-' . $u;
			$expected = (new VariantAssigner($uid))->assign([0.5, 0.5], self::$SEED_HI, self::$SEED_LO);
			$context = $this->createContext(self::dataOf($experiment), [self::$UNIT_TYPE => $uid, 'user_id' => 'fixed-user']);
			self::assertSame($expected, $context->getTreatment('exp_sb'), $uid);
			self::assertSame([self::ruleExposure('exp_sb', $expected, self::$ANCHOR)], $this->publishExposures($context), $uid);
			$ones += $expected;
		}
		self::assertTrue($ones > 0 && $ones < 32);
	}

	public function testAudienceStrictMismatchIsRecordedAsControl(): void {
		$experiment = self::switchback(2);
		$experiment->audience = self::STRICT_GOLD_AUDIENCE;
		$experiment->audienceStrict = true;
		$context = $this->createContext(self::dataOf($experiment));
		$this->now = self::$ANCHOR + self::$HOUR;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::strictMismatchExposure(self::$ANCHOR + self::$HOUR)], $this->publishExposures($context));
	}

	/** Exact wire form; period 1 draws 1, so variant 0 below is the strict mismatch's control. */
	public function testAudienceStrictMismatchExposureSerializesExactly(): void {
		$experiment = self::switchback(2);
		$experiment->audience = self::STRICT_GOLD_AUDIENCE;
		$experiment->audienceStrict = true;
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(1, self::$EXPECTED_2_ARMS[1]);
		$this->now = self::$ANCHOR + self::$HOUR + 5;
		$context->getTreatment('exp_sb');
		$this->now = self::$ANCHOR + self::$HOUR + 9;

		self::assertSame('{"hashed":true,"publishedAt":' . (self::$ANCHOR + self::$HOUR + 9) . ','
			. '"exposures":[{"id":1,"name":"exp_sb","unit":"store_id","variant":0,"exposedAt":' . (self::$ANCHOR + self::$HOUR + 5)
			. ',"assigned":false,"eligible":true,"overridden":false,"fullOn":false,"custom":false,"audienceMismatch":true,"ruleOverride":false}],'
			. '"units":[{"type":"store_id","uid":"' . self::$UID_HASH . '"}],'
			. '"attributes":[{"name":"' . self::$CLUSTER_PATH . '","value":"' . self::$CLUSTER_KEY . '","setAt":' . self::$ANCHOR . '}]}',
			$this->publishedJson($context));
	}

	/** A strict mismatch before the anchor is still recorded, as in the Collector. */
	public function testAudienceStrictMismatchBeforeTheAnchorIsRecorded(): void {
		$experiment = self::switchback(2);
		$experiment->audience = self::STRICT_GOLD_AUDIENCE;
		$experiment->audienceStrict = true;
		$this->now = self::$ANCHOR - 1;
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::strictMismatchExposure(self::$ANCHOR - 1)], $this->publishExposures($context));
	}

	public function testAudienceStrictMismatchWithAnUnservableScheduleIsRecorded(): void {
		$experiment = self::switchback(2);
		$experiment->audience = self::STRICT_GOLD_AUDIENCE;
		$experiment->audienceStrict = true;
		$experiment->switchbackStartAt = null;
		$this->now = self::$ANCHOR + self::$HOUR + 3;
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::strictMismatchExposure(self::$ANCHOR + self::$HOUR + 3)], $this->publishExposures($context));
	}

	/** The legacy path returns an ineligible control before the audience, so a strict mismatch there records nothing. */
	public function testAudienceStrictMismatchWithAMissingOrUnreadableClusterMapRecordsNothing(): void {
		foreach ([null, self::clusters(self::$UNIT_TYPE, "'geo")] as $clusters) {
			$experiment = self::switchback(2);
			$experiment->audience = self::STRICT_GOLD_AUDIENCE;
			$experiment->audienceStrict = true;
			$experiment->switchbackClusters = $clusters;
			$this->now = self::$ANCHOR + self::$HOUR + 3;
			$context = $this->createContext(self::dataOf($experiment));
			self::assertSame(0, $context->getTreatment('exp_sb'), json_encode($clusters));
			self::assertSame(0, $context->getPendingCount(), json_encode($clusters));
		}
	}

	/**
	 * A strict-mismatched switchback is not assigned, so on a shared variable key it gives way to the next experiment
	 * that declares the key, as a unit experiment does. As in java-sdk, only the winner is exposed.
	 */
	public function testStrictMismatchedSwitchbackGivesWayOnASharedVariableKey(): void {
		$sb = self::switchback(2);
		$sb->audience = self::STRICT_GOLD_AUDIENCE;
		$sb->audienceStrict = true;
		$unit = self::unitExperiment(2, 2, 'exp_unit');
		$unit->variants = [(object) ['name' => 'A', 'config' => '{"sb.arm":10}'], (object) ['name' => 'B', 'config' => '{"sb.arm":11}']];
		$unit->fullOnVariant = 1;
		$this->now = self::$ANCHOR + self::$HOUR + 3;
		$context = $this->createContext(self::dataOf($sb, $unit));
		self::assertSame(['sb.arm' => 'exp_sb'], $context->getVariableKeys());

		self::assertSame(11, $context->peekVariableValue('sb.arm', -1));
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(11, $context->getVariableValue('sb.arm', -1));
		self::assertSame([
			self::exposure(2, 'exp_unit', self::$UNIT_TYPE, 1, self::$ANCHOR + self::$HOUR + 3, true, true, false, true),
		], $this->publishExposures($context));
		// getTreatment() still records the switchback's own strict mismatch.
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::strictMismatchExposure(self::$ANCHOR + self::$HOUR + 3)], array_slice($this->publishExposures($context), 1));
	}

	/** PHP 1.2.0 exposes only the winning unit experiment on a shared key. */
	public function testAStrictMismatchedUnitExperimentWalkedPastIsNotExposed(): void {
		$first = self::unitExperiment(2, 1, 'exp_first');
		$first->audience = self::STRICT_GOLD_AUDIENCE;
		$first->audienceStrict = true;
		$second = self::unitExperiment(2, 2, 'exp_second');
		$second->variants = [(object) ['name' => 'A', 'config' => '{"sb.arm":10}'], (object) ['name' => 'B', 'config' => '{"sb.arm":11}']];
		$second->fullOnVariant = 1;
		$context = $this->createContext(self::dataOf($first, $second));
		self::assertSame(11, $context->getVariableValue('sb.arm', -1));
		self::assertSame(['exp_second'], array_column($this->publishExposures($context), 1));
	}

	/** A same-outcome rules refresh is validated once, then the cached switchback slot stops re-evaluating rules. */
	public function testASameOutcomeRuleRefreshAdvancesTheRuleKey(): void {
		$experiment = self::switchback(2);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(1, $context->getTreatment('exp_sb'));
		$counts = self::countEvaluations($context);
		$renamed = clone $experiment;
		$renamed->assignmentRules = '{"rules":[{"name":"renamed","type":"assign","environments":[],"variant":1}]}';
		$this->refreshTo($context, $renamed);
		for ($i = 0; $i < 3; ++$i) {
			self::assertSame(1, $context->getTreatment('exp_sb'));
		}
		self::assertSame(1, $counts->rules);
		self::assertSame(1, $context->getPendingCount());
	}

	/** Peek reads serve a rule-won switchback's arm without queuing an exposure. */
	public function testPeekReadsServeARuleWonSwitchbackWithoutExposure(): void {
		$experiment = self::switchback(2);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(1, $context->peekVariableValue('sb.arm', -1));
		self::assertSame(0, $context->getPendingCount());
	}

	/** A switchback won by a rule or an override and read only through a variable key is exposed. */
	public function testARuleOrOverrideWonSwitchbackIsExposedThroughAVariableKey(): void {
		$rule = self::switchback(2);
		$rule->assignmentRules = self::ASSIGN_VARIANT_1;
		$context = $this->createContext(self::dataOf($rule));
		self::assertSame(1, $context->getVariableValue('sb.arm', -1));
		self::assertSame([self::ruleExposure('exp_sb', 1, self::$ANCHOR)], $this->publishExposures($context));

		$overridden = $this->createContext(self::dataOf(self::switchback(2)));
		$overridden->setOverride('exp_sb', 1);
		self::assertSame(1, $overridden->getVariableValue('sb.arm', -1));
		self::assertSame([self::exposure(1, 'exp_sb', self::$UNIT_TYPE, 1, self::$ANCHOR, false, true, true)], $this->publishExposures($overridden));
	}

	/**
	 * As in java-sdk, an assigned switchback the key reaches is exposed even when its served arm lacks the key (a control
	 * arm with no config): otherwise its control periods would go unrecorded. The winner returns the default rather than falling through.
	 */
	public function testAnAssignedSwitchbackWhoseArmLacksTheKeyIsStillExposed(): void {
		$sb = self::switchback(2);
		$sb->variants[0]->config = '{"unrelated":0}';
		$unit = self::unitExperiment(2, 2, 'exp_unit');
		$unit->variants = [(object) ['name' => 'A', 'config' => '{"sb.arm":10}'], (object) ['name' => 'B', 'config' => '{"sb.arm":11}']];
		$unit->fullOnVariant = 1;
		self::assertSame(0, self::$EXPECTED_2_ARMS[0]);
		$alone = $this->createContext(self::dataOf($sb));
		self::assertSame(-1, $alone->getVariableValue('sb.arm', -1));
		self::assertSame([self::sbExposure(0, self::$ANCHOR)], $this->publishExposures($alone));

		$sb->variants[0]->config = null;
		$nullControl = $this->createContext(self::dataOf($sb));
		self::assertSame(-1, $nullControl->getVariableValue('sb.arm', -1));
		self::assertSame(1, $nullControl->getPendingCount());

		$sb->variants[0]->config = '{"unrelated":0}';
		$context = $this->createContext(self::dataOf($sb, $unit));
		self::assertSame(-1, $context->getVariableValue('sb.arm', -1));
		self::assertSame(['exp_sb'], array_column($this->publishExposures($context), 1));
	}

	/** Alone on the key, a strict-mismatched switchback yields the default value. */
	public function testStrictMismatchedSwitchbackAloneOnAVariableKeyReturnsTheDefault(): void {
		$sb = self::switchback(2);
		$sb->audience = self::STRICT_GOLD_AUDIENCE;
		$sb->audienceStrict = true;
		$this->now = self::$ANCHOR + self::$HOUR + 3;
		$context = $this->createContext(self::dataOf($sb));
		self::assertSame(-1, $context->peekVariableValue('sb.arm', -1));
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(-1, $context->getVariableValue('sb.arm', -1));
		self::assertSame(0, $context->getPendingCount());
	}

	/** A holdout covering a strict-mismatched switchback never makes it a held-out fallback on a variable key. */
	public function testACoveringHoldoutNeverMakesASwitchbackAVariableFallback(): void {
		$sb = self::switchback(2);
		$sb->audience = self::STRICT_GOLD_AUDIENCE;
		$sb->audienceStrict = true;
		$sb->holdoutIds = [11];
		// split [1, 0]: arm 0, which holds every unit out of a unit experiment.
		$context = $this->createContext(new ContextData([$sb], [self::holdout([1.0, 0.0])]));
		self::assertSame(-1, $context->peekVariableValue('sb.arm', -1));
		self::assertSame(-1, $context->getVariableValue('sb.arm', -1));
		self::assertSame(0, $context->getPendingCount());
	}

	/**
	 * An ineligible cluster is listed as an assigned control (as in java-sdk), so on a shared key it serves variant 0's
	 * value like a unit experiment's traffic miss, and records nothing.
	 */
	public function testIneligibleClusterServesControlOnASharedVariableKeyAndRecordsNothing(): void {
		$sb = self::switchback(2);
		$sb->switchbackClusterValues = ['other-store'];
		$unit = self::unitExperiment(2, 2, 'exp_unit');
		$unit->variants = [(object) ['name' => 'A', 'config' => '{"sb.arm":10}'], (object) ['name' => 'B', 'config' => '{"sb.arm":11}']];
		$unit->fullOnVariant = 1;
		$context = $this->createContext(self::dataOf($sb, $unit));
		self::assertSame(0, $context->peekVariableValue('sb.arm', -1));
		self::assertSame(0, $context->getVariableValue('sb.arm', -1));
		self::assertSame(0, $context->getPendingCount());
	}

	/** @return array<string, array{string, int, bool[]}> branch => [branch, expected variant, flags] */
	public function parityBranches(): array {
		// assigned, eligible, overridden, fullOn, custom, audienceMismatch, ruleOverride (SWITCHBACK.md "Exposure flags").
		// Period 2's 3-arm draw is 1; forced variants use 2, which differs from control and the draw.
		return [
			'OVERRIDE' => ['OVERRIDE', 2, [false, true, true, false, false, false, false]],
			'OVERRIDE_OVER_STRICT_MISMATCH' => ['OVERRIDE_OVER_STRICT_MISMATCH', 2, [false, true, true, false, false, false, false]],
			'RULE' => ['RULE', 2, [false, true, false, false, false, false, true]],
			'RULE_OVER_STRICT_MISMATCH' => ['RULE_OVER_STRICT_MISMATCH', 2, [false, true, false, false, false, false, true]],
			'STRICT_MISMATCH' => ['STRICT_MISMATCH', 0, [false, true, false, false, false, true, false]],
			'STRICT_MISMATCH_OVER_FULL_ON' => ['STRICT_MISMATCH_OVER_FULL_ON', 0, [false, true, false, false, false, true, false]],
			'STRICT_MISMATCH_OVER_TRAFFIC_MISS' => ['STRICT_MISMATCH_OVER_TRAFFIC_MISS', 0, [false, true, false, false, false, true, false]],
			'NON_STRICT_MISMATCH' => ['NON_STRICT_MISMATCH', self::$EXPECTED_3_ARMS[2], [true, true, false, false, false, true, false]],
			'FULL_ON' => ['FULL_ON', 2, [true, true, false, true, false, false, false]],
			'TRAFFIC_MISS' => ['TRAFFIC_MISS', 0, [true, false, false, false, false, false, false]],
			'CUSTOM' => ['CUSTOM', 2, [true, true, false, false, true, false, false]],
			'DRAW' => ['DRAW', self::$EXPECTED_3_ARMS[2], [true, true, false, false, false, false, false]],
		];
	}

	private function exposeOnce(object $experiment, string $branch): array {
		if (in_array($branch, ['OVERRIDE_OVER_STRICT_MISMATCH', 'RULE_OVER_STRICT_MISMATCH', 'STRICT_MISMATCH',
			'STRICT_MISMATCH_OVER_FULL_ON', 'STRICT_MISMATCH_OVER_TRAFFIC_MISS'], true)) {
			$experiment->audienceStrict = true;
		}
		if (strpos($branch, 'MISMATCH') !== false) {
			$experiment->audience = self::STRICT_GOLD_AUDIENCE;
		}
		if ($branch === 'STRICT_MISMATCH_OVER_FULL_ON' || $branch === 'FULL_ON') {
			$experiment->fullOnVariant = 2;
		}
		if ($branch === 'STRICT_MISMATCH_OVER_TRAFFIC_MISS' || $branch === 'TRAFFIC_MISS') {
			$experiment->trafficSplit = [1.0, 0.0];
		}
		if ($branch === 'RULE' || $branch === 'RULE_OVER_STRICT_MISMATCH') {
			$experiment->assignmentRules = self::ASSIGN_VARIANT_2;
		}
		$context = $this->createContext(self::dataOf($experiment));
		if ($branch === 'OVERRIDE' || $branch === 'OVERRIDE_OVER_STRICT_MISMATCH') {
			$context->setOverride('exp_parity', 2);
		}
		elseif ($branch === 'CUSTOM') {
			$context->setCustomAssignment('exp_parity', 2);
		}
		$context->getTreatment('exp_parity');
		$exposures = $this->publishExposures($context);
		self::assertCount(1, $exposures, $branch);
		return $exposures[0];
	}

	/**
	 * The same branch on a unit experiment and on an eligible-cluster switchback publishes the same exposure, apart from
	 * the variant where the unit draws, and both match the SWITCHBACK.md table.
	 *
	 * @dataProvider parityBranches
	 */
	public function testExposureFlagsMatchAUnitExperimentForEveryBranch(string $branch, int $expectedVariant, array $flags): void {
		$this->now = self::$ANCHOR + 2 * self::$HOUR + 3;
		self::assertSame(1, self::$EXPECTED_3_ARMS[2]);

		$unit = self::exposeOnce(self::unitExperiment(3, 1, 'exp_parity'), $branch);
		$switchback = self::exposeOnce(self::switchback(3, null, 1, 'exp_parity'), $branch);

		self::assertSame($flags, array_slice($unit, 5), "unit $branch");
		self::assertSame($flags, array_slice($switchback, 5), "switchback $branch");
		self::assertSame($expectedVariant, $switchback[3], "switchback variant $branch");
		if ($branch !== 'DRAW' && $branch !== 'NON_STRICT_MISMATCH') {
			self::assertSame($expectedVariant, $unit[3], "unit variant $branch");
		}
		self::assertSame(array_slice($unit, 0, 3), array_slice($switchback, 0, 3));
		self::assertSame($unit[4], $switchback[4]);
	}

	/** A traffic miss on an eligible cluster is recorded eligible=false; an ineligible cluster records nothing. */
	public function testTrafficMissOnAnEligibleClusterPublishesAnIneligibleExposure(): void {
		$miss = self::switchback(2, null, 1, 'exp_miss');
		$miss->trafficSplit = [1.0, 0.0];
		$ineligibleCluster = self::switchback(2, null, 2, 'exp_cluster_ineligible');
		$ineligibleCluster->trafficSplit = [1.0, 0.0];
		$ineligibleCluster->switchbackClusterValues = ['other-store'];
		$context = $this->createContext(self::dataOf($miss, $ineligibleCluster));
		$this->now = self::$ANCHOR + 2 * self::$HOUR + 3;
		self::assertSame(0, $context->getTreatment('exp_miss'));
		self::assertSame(0, $context->getTreatment('exp_cluster_ineligible'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR + 2 * self::$HOUR + 3, true, false, 1, 'exp_miss')], $this->publishExposures($context));
	}

	public function testCustomFieldValuesAndUnitLookupsWork(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		self::assertSame(self::$UID, $context->getUnit(self::$UNIT_TYPE));
		self::assertSame([], $context->getCustomFieldKeys());
		self::assertNull($context->customFieldValue('exp_sb', 'missing'));
	}

	/** Each experiment keeps entries for at most its two most recent periods. */
	public function testPerPeriodCacheIsBounded(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		for ($i = 0; $i < 50; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			$context->getTreatment('exp_sb');
		}
		self::assertSame(2, self::cacheSize($context));
		self::assertSame(50, $context->getPendingCount());
		self::assertSame([48, 49], self::privateValue($context, 'switchbackEpochs')['exp_sb']['periods']);
	}

	/** Periods are retained by index, not by recency or insertion: the lowest index other than the new one goes. */
	public function testRetentionIsByPeriodIndexNotInsertionOrder(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		foreach ([5, 3, 4] as $period) {
			$this->now = self::$ANCHOR + $period * self::$HOUR;
			$context->getTreatment('exp_sb');
		}
		self::assertSame([4, 5], self::privateValue($context, 'switchbackEpochs')['exp_sb']['periods']);
		self::assertSame(3, $context->getPendingCount());
		// Period 5 was installed first but has the highest index, so it is still deduplicated.
		$this->now = self::$ANCHOR + 5 * self::$HOUR;
		$context->getTreatment('exp_sb');
		self::assertSame(3, $context->getPendingCount());
	}

	/** unitType on a switchback payload is never used for assignment: the cluster map decides. */
	public function testUnitTypeIsNotUsedForSwitchbackAssignment(): void {
		$experiment = self::switchback(2);
		$experiment->unitType = 'user_id';
		$context = $this->createContext(self::dataOf($experiment), [self::$UNIT_TYPE => self::$UID, 'user_id' => 'u1']);
		for ($i = 0; $i < 3; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(self::$EXPECTED_2_ARMS[$i], $context->getTreatment('exp_sb'));
		}
		$exposures = $this->publishExposures($context);
		self::assertCount(3, $exposures);
		foreach ($exposures as $exposure) {
			self::assertSame(self::$UNIT_TYPE, $exposure[2]);
		}
	}

	private function publishedJson(Context $context): string {
		$captured = null;
		$this->eventHandler->prerun = null;
		$handler = $this->eventHandler;
		$context->publish();
		$client = new \ABSmartly\SDK\Client\Client(new \ABSmartly\SDK\Client\ClientConfig('https://x.io/v1', 'k', 'a', 'e'));
		self::assertCount(1, $handler->submitted);
		// Re-encode the decoded payload with the client's encoder: the bytes the collector receives.
		return $client->encode($handler->submitted[0]);
	}

	/** The publish payload carries no switchback-specific fields; the cluster attribute is published timestamped. */
	public function testPublishPayloadHasNoNewFields(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 5;
		$context->getTreatment('exp_sb');
		$this->now = self::$ANCHOR + self::$HOUR + 7;
		$context->getTreatment('exp_sb');
		$this->now = self::$ANCHOR + self::$HOUR + 9;

		$exposure = static function (int $variant, int $exposedAt): string {
			return '{"id":1,"name":"exp_sb","unit":"store_id","variant":' . $variant . ',"exposedAt":' . $exposedAt
				. ',"assigned":true,"eligible":true,"overridden":false,"fullOn":false,"custom":false,"audienceMismatch":false,"ruleOverride":false}';
		};
		self::assertSame('{"hashed":true,"publishedAt":' . (self::$ANCHOR + self::$HOUR + 9) . ','
			. '"exposures":[' . $exposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR + 5) . ',' . $exposure(self::$EXPECTED_2_ARMS[1], self::$ANCHOR + self::$HOUR + 7) . '],'
			. '"units":[{"type":"store_id","uid":"' . self::$UID_HASH . '"}],'
			. '"attributes":[{"name":"' . self::$CLUSTER_PATH . '","value":"' . self::$CLUSTER_KEY . '","setAt":' . self::$ANCHOR . '}]}',
			$this->publishedJson($context));
		self::assertNotSame(self::$EXPECTED_2_ARMS[0], self::$EXPECTED_2_ARMS[1]);
	}

	public function testPeriodsPublishedAcrossFlushesStayOnePerPeriod(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR;
		$context->getTreatment('exp_sb');
		$context->publish();
		$this->now = self::$ANCHOR + 1;
		$context->getTreatment('exp_sb');
		self::assertSame(0, $context->getPendingCount());
		$this->now = self::$ANCHOR + self::$HOUR;
		$context->getTreatment('exp_sb');

		self::assertSame([self::$EXPECTED_2_ARMS[0], self::$EXPECTED_2_ARMS[1]], array_column($this->publishExposures($context), 3));
		self::assertCount(2, $this->eventHandler->submitted);
	}

	/** The clock crosses into period 1 after resolution; the exposure still carries the instant that chose period 0. */
	public function testTreatmentExposureIsStampedWithTheResolutionInstant(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + self::$HOUR;
		$this->nextRead = self::$ANCHOR + self::$HOUR - 1;

		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR + self::$HOUR - 1)], $this->publishExposures($context));
	}

	public function testVariableExposureIsStampedWithTheResolutionInstant(): void {
		$covered = self::switchback(2);
		$covered->holdoutIds = [11];
		$context = $this->createContext(new ContextData([$covered], [self::holdout([0.0, 1.0])]));
		$this->now = self::$ANCHOR + self::$HOUR;
		$this->nextRead = self::$ANCHOR + self::$HOUR - 1;

		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getVariableValue('sb.arm', -1));
		self::assertSame([self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR + self::$HOUR - 1)], $this->publishExposures($context));
	}

	/** A peek caches the period's assignment without exposing; the later getTreatment exposes at its own instant. */
	public function testExposureAfterPeekUsesTheExposingCallsInstant(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->peekTreatment('exp_sb'));
		$this->now = self::$ANCHOR + 20 * 60000;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR + 20 * 60000)], $this->publishExposures($context));
	}

	/**
	 * Before the anchor there is no period to bound the stamp: a rule winner peeked and exposed later carries the
	 * exposing call's reading, so it never precedes a goal tracked in between.
	 */
	public function testAnExposureNeverPrecedesAGoalTrackedBeforeIt(): void {
		$experiment = self::switchback(2);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		$this->now = self::$ANCHOR - 3 * 86400000;
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		$this->now = self::$ANCHOR - 2 * 86400000;
		$context->trackWithProperties('goal');
		$this->now = self::$ANCHOR - 86400000;
		self::assertSame(1, $context->getTreatment('exp_sb'));
		$context->publish();
		$event = $this->eventHandler->submitted[0];
		self::assertSame(self::$ANCHOR - 86400000, $event->exposures[0]->exposedAt);
		self::assertGreaterThan($event->goals[0]->achievedAt, $event->exposures[0]->exposedAt);
	}

	/**
	 * Read once per period, as in java-sdk: a peek in period 0 with cluster store-42 (arm 0), the cluster attribute then
	 * set to Faro (arm 1 in period 0), and an exposure later in the period serves arm 0, stamped with the exposing call's
	 * reading; Faro serves from period 1. A cluster attribute change within a period is not supported (a caller
	 * constraint, as in every SDK): this pins that the cached arm is kept and the change waits for the next period.
	 */
	public function testAnAttributeChangeAfterAPeekKeepsTheServedArmAsInJava(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->peekTreatment('exp_sb'));
		$this->now = self::$ANCHOR + 20;
		$context->setAttributeValue(self::$CLUSTER_PATH, 'Faro');
		self::assertSame(1, SwitchbackAssigner::assign('Faro', 0, self::seed(), 2));
		$this->now = self::$ANCHOR + 30;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR + 30)], $this->publishExposures($context));

		// Period 1 resolves afresh under Faro and exposes again; a stale period-0 slot would expose nothing.
		$this->now = self::$ANCHOR + self::$HOUR + 1;
		$faro = SwitchbackAssigner::assign('Faro', 1, self::seed(), 2);
		self::assertNotSame(SwitchbackAssigner::assign(self::$CLUSTER_KEY, 1, self::seed(), 2), $faro);
		self::assertSame($faro, $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR + 30), self::sbExposure($faro, self::$ANCHOR + self::$HOUR + 1)],
			$this->publishExposures($context));
	}

	/** A nested SDK call (from a JsonSerializable cluster attribute) cannot move an outer exposure to another period. */
	public function testANestedReadCannotChangeTheOuterExposuresTimestamp(): void {
		$experiment = self::switchback(3);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'geo/city');
		$other = self::switchback(2, null, 2, 'exp_other');
		$context = $this->createContext(self::dataOf($experiment, $other));
		$test = $this;
		$geo = new class($context, $test) implements \JsonSerializable {
			private Context $context;
			private $test;

			public function __construct(Context $context, $test) {
				$this->context = $context;
				$this->test = $test;
			}

			public function jsonSerialize(): array {
				// Crosses into the next period while the outer read is between resolving and exposing.
				$this->test->now = $this->test->now + 1;
				$this->context->peekTreatment('exp_other');
				return ['city' => 'Porto'];
			}
		};
		$this->now = self::$ANCHOR + self::$HOUR - 1;
		$this->setReadAttribute($context, 'geo', $geo);
		$variant = $context->getTreatment('exp_sb');
		self::assertSame(SwitchbackAssigner::assign('Porto', 0, self::seed(), 3), $variant);
		self::assertSame([self::sbExposure($variant, self::$ANCHOR + self::$HOUR - 1)], $this->loggedExposures());
	}

	public function cachedUnitContextProvider(): array {
		return ['ordinary context' => [false], 'mixed context' => [true]];
	}

	/** @dataProvider cachedUnitContextProvider */
	public function testCachedUnitAssignmentIsSharedByAllReadApis(bool $withSwitchback): void {
		$unit = self::unitExperiment();
		foreach ($unit->variants as $i => $arm) {
			$arm->config = '{"unit.arm":' . $i . '}';
		}
		$data = $withSwitchback ? self::dataOf($unit, self::switchback()) : self::dataOf($unit);
		$context = $this->createContext($data);
		$counts = self::countEvaluations($context);
		$this->nextRead = 123;

		// The first peek resolves an uninitialized cache without exposing or reading the clock.
		$variant = $context->peekTreatment('exp_unit');
		$assignment = self::privateValue($context, 'assignmentCache')['exp_unit'];
		self::assertSame($variant, $context->peekVariableValue('unit.arm', -1));
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(123, $this->nextRead);
		self::assertSame($variant, $context->getVariableValue('unit.arm', -1));
		self::assertNull($this->nextRead);

		$this->nextRead = 456;
		for ($i = 0; $i < 3; ++$i) {
			self::assertSame($variant, $context->getTreatment('exp_unit'));
			self::assertSame($variant, $context->peekTreatment('exp_unit'));
			self::assertSame($variant, $context->getVariableValue('unit.arm', -1));
			self::assertSame($variant, $context->peekVariableValue('unit.arm', -1));
		}
		self::assertSame(456, $this->nextRead);
		self::assertSame($assignment, self::privateValue($context, 'assignmentCache')['exp_unit']);
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(0, $counts->rules);
		self::assertSame(0, $counts->audiences);
		self::assertSame([self::exposure(2, 'exp_unit', self::$UNIT_TYPE, $variant, 123)], $this->loggedExposures());
	}

	public function testCachedUnitIdentityIsCheckedBeforeRuleRevalidation(): void {
		$unit = self::unitExperiment();
		$unit->assignmentRules = self::ASSIGN_VARIANT_1;
		$context = $this->createContext(self::dataOf($unit));
		$counts = self::countEvaluations($context);
		self::assertSame(1, $context->getTreatment('exp_unit'));
		$assignment = self::privateValue($context, 'assignmentCache')['exp_unit'];
		self::assertSame(1, $counts->rules);

		$context->setAttributeValue('unrelated', true);
		++$unit->iteration;
		$this->refreshTo($context, $unit);
		self::assertSame(1, $context->getTreatment('exp_unit'));
		self::assertNotSame($assignment, self::privateValue($context, 'assignmentCache')['exp_unit']);
		// Only the rebuild evaluates the rule; the stale assignment must be rejected before revalidation.
		self::assertSame(2, $counts->rules);
		self::assertSame(1, $context->peekTreatment('exp_unit'));
		self::assertSame(2, $counts->rules);
		self::assertSame(2, $context->getPendingCount());
	}

	public function testCachedMissingExperimentStillRevalidatesAZeroOverride(): void {
		$context = $this->createContext(self::dataOf(self::unitExperiment()));
		self::assertSame(0, $context->peekTreatment('missing'));
		$missing = self::privateValue($context, 'assignmentCache')['missing'];
		self::assertSame(0, $context->getTreatment('missing'));
		self::assertSame($missing, self::privateValue($context, 'assignmentCache')['missing']);
		self::assertSame(1, $context->getPendingCount());

		$context->setOverride('missing', 0);
		self::assertSame(0, $context->getTreatment('missing'));
		$overridden = self::privateValue($context, 'assignmentCache')['missing'];
		self::assertNotSame($missing, $overridden);
		self::assertTrue($overridden->overridden);
		self::assertSame(0, $context->getTreatment('missing'));
		self::assertSame($overridden, self::privateValue($context, 'assignmentCache')['missing']);
		self::assertSame(2, $context->getPendingCount());
	}

	/**
	 * Unit experiments keep stamping at enqueue time, with the one clock reading they took before switchbacks existed,
	 * even next to a switchback: the first reading after resolving (nextRead) is the enqueue's.
	 */
	public function testUnitExposureTimingIsUnchanged(): void {
		$unit = self::switchback(2);
		$unit->randomizationType = 'unit';
		$context = $this->createContext(self::dataOf($unit, self::switchback(2, null, 2, 'exp_other')));
		$this->now = self::$ANCHOR + self::$HOUR;
		$this->nextRead = self::$ANCHOR + self::$HOUR - 1;

		$variant = $context->getTreatment('exp_sb');
		self::assertNull($this->nextRead);
		self::assertSame([self::sbExposure($variant, self::$ANCHOR + self::$HOUR - 1)], $this->publishExposures($context));

		$this->nextRead = 1;
		$context->peekTreatment('exp_sb');
		self::assertSame(1, $this->nextRead);
	}

	/** A grid change that lowers the current index below the retained ones must not evict the slot it just installed. */
	public function testGridRefreshToALowerIndexExposesOnce(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 99 * self::$HOUR;
		$context->getTreatment('exp_sb');
		$this->now = self::$ANCHOR + 100 * self::$HOUR;
		$context->getTreatment('exp_sb');
		self::assertSame(2, $context->getPendingCount());

		$restarted = self::switchback(2);
		$restarted->switchbackStartAt = self::$ANCHOR + 100 * self::$HOUR;
		$this->refreshTo($context, $restarted);
		for ($i = 0; $i < 3; ++$i) {
			self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		}
		self::assertSame(3, $context->getPendingCount());
	}

	public function testPeriodLengthRefreshToALowerIndexExposesOnce(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 100 * self::$HOUR;
		$context->getTreatment('exp_sb');
		$this->now = self::$ANCHOR + 101 * self::$HOUR;
		$context->getTreatment('exp_sb');

		$this->refreshTo($context, self::switchback(2, 200 * self::$HOUR));
		$context->getTreatment('exp_sb');
		$context->getTreatment('exp_sb');
		self::assertSame(3, $context->getPendingCount());
	}

	/** Rewinding the clock re-exposes each re-entered evicted period at most once, never once per call. */
	public function testClockRewindReexposesAnEvictedPeriodAtMostOnce(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		for ($i = 0; $i < 3; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			$context->getTreatment('exp_sb');
		}
		self::assertSame(3, $context->getPendingCount());

		$this->now = self::$ANCHOR;
		for ($i = 0; $i < 3; ++$i) {
			self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		}
		self::assertSame(4, $context->getPendingCount());

		$this->now = self::$ANCHOR + 2 * self::$HOUR;
		$context->getTreatment('exp_sb');
		self::assertSame(4, $context->getPendingCount());
		$this->now = self::$ANCHOR + self::$HOUR;
		for ($i = 0; $i < 3; ++$i) {
			self::assertSame(self::$EXPECTED_2_ARMS[1], $context->getTreatment('exp_sb'));
		}
		self::assertSame(5, $context->getPendingCount());

		$exposures = $this->publishExposures($context);
		self::assertSame(self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR), $exposures[3]);
		self::assertSame(self::sbExposure(self::$EXPECTED_2_ARMS[1], self::$ANCHOR + self::$HOUR), $exposures[4]);
	}

	/** An overridden switchback slot is re-resolved on a grid change; the override still wins, the holdout never fires. */
	public function testOverrideIsReresolvedWhenTheGridChanges(): void {
		$original = self::switchback(3);
		$original->holdoutIds = [11];
		$context = $this->createContext(new ContextData([$original], [self::holdout([0.0, 1.0])]));
		$context->setOverride('exp_sb', 2);

		$this->now = self::$ANCHOR + 10;
		self::assertSame(2, $context->getTreatment('exp_sb'));
		self::assertSame(2, $context->getVariableValue('sb.arm', -1));
		self::assertSame(1, $context->getPendingCount());

		// Same index 0 under the new period length; variant 2 now carries a different value.
		$regridded = self::switchback(3, 2 * self::$HOUR);
		$regridded->holdoutIds = [11];
		$regridded->variants[2] = (object) ['name' => 'C', 'config' => '{"sb.arm":22}'];
		$this->dataProvider->next = new ContextData([$regridded], [self::holdout([0.0, 1.0])]);
		$context->refresh();

		self::assertSame(2, $context->getTreatment('exp_sb'));
		self::assertSame(22, $context->getVariableValue('sb.arm', -1));
		self::assertSame(2, $context->getPendingCount());

		$override = self::exposure(1, 'exp_sb', self::$UNIT_TYPE, 2, self::$ANCHOR + 10, false, true, true);
		self::assertSame([$override, $override], $this->publishExposures($context));
	}

	/** Each identity transition is a new epoch: returning to an identical grid re-resolves. */
	public function testSwitchbackToUnitToIdenticalSwitchbackReresolves(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());

		$toUnit = self::switchback(2);
		$toUnit->randomizationType = 'unit';
		$this->refreshTo($context, $toUnit);
		$context->getTreatment('exp_sb');
		self::assertSame(2, $context->getPendingCount());

		$this->refreshTo($context, self::switchback(2));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(3, $context->getPendingCount());
	}

	public function testRemovedAndRestoredSwitchbackReresolves(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');

		$this->refreshTo($context);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(2, $context->getPendingCount());

		$this->refreshTo($context, self::switchback(2));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(3, $context->getPendingCount());
	}

	/** The pre-anchor bare-name slot shares the lifecycle of the period slots. */
	public function testPreAnchorSlotIsDroppedWhenTheGridChanges(): void {
		$gridA = self::switchback(2);
		$gridA->switchbackStartAt = self::$ANCHOR + 10 * self::$HOUR;
		$context = $this->createContext(self::dataOf($gridA));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getPendingCount());

		$this->refreshTo($context, self::switchback(2));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());

		$this->refreshTo($context, $gridA);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(0, $context->getVariableValue('sb.arm', -1));
		self::assertSame(1, $context->getPendingCount());
	}

	/** The pre-anchor slot shares the experiment's name as its cache key; a unit experiment never reuses it. */
	public function testAPreAnchorSlotIsNotServedToAUnitExperiment(): void {
		$preAnchor = self::switchback(2);
		$preAnchor->switchbackStartAt = self::$ANCHOR + 10 * self::$HOUR;
		// store-1's unit draw is 1, so a stale control slot would be visible.
		$context = $this->createContext(self::dataOf($preAnchor), [self::$UNIT_TYPE => 'store-1']);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$this->now = self::$ANCHOR + 11 * self::$HOUR;
		$context->peekTreatment('exp_sb');
		self::assertSame(0, $context->getPendingCount());

		$toUnit = clone $preAnchor;
		$toUnit->randomizationType = 'unit';
		$this->refreshTo($context, $toUnit);
		$unitVariant = (new VariantAssigner('store-1'))->assign([0.5, 0.5], self::$SEED_HI, self::$SEED_LO);
		self::assertSame(1, $unitVariant);
		self::assertSame($unitVariant, $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
		// Every slot of the old epoch is gone: only the unit assignment is cached.
		self::assertSame(1, self::cacheSize($context));

		// And back: the unit assignment on the bare-name key is not a switchback slot.
		$this->refreshTo($context, $preAnchor);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getVariableValue('sb.arm', -1));
		self::assertSame(1, $context->getPendingCount());
	}

	/** Epochs are reconciled lazily; an intermediate state that is never looked up still ends the epoch. */
	public function testTransitionsWithoutAnInterveningLookupStillReresolve(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		self::assertSame(1, $context->getPendingCount());

		$toUnit = self::switchback(2);
		$toUnit->randomizationType = 'unit';
		$this->refreshTo($context, $toUnit);
		$this->refreshTo($context, self::switchback(2));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(2, $context->getPendingCount());

		$this->refreshTo($context);
		$this->refreshTo($context, self::switchback(2));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(3, $context->getPendingCount());

		$regridded = self::switchback(2);
		$regridded->switchbackDesignType = 'other';
		$this->refreshTo($context, $regridded);
		$this->refreshTo($context, self::switchback(2));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(4, $context->getPendingCount());

		$this->refreshTo($context, self::switchback(2));
		$context->getTreatment('exp_sb');
		self::assertSame(4, $context->getPendingCount());
	}

	/** A failed first load leaves no switchback behind: the failed context serves control and the default value. */
	public function testAFailedLoadAfterASwitchbackLeavesNoSwitchbackState(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$failure = new \ReflectionMethod(Context::class, 'setDataFailed');
		if (PHP_VERSION_ID < 80100) { $failure->setAccessible(true); }
		$failure->invoke($context, new \RuntimeException('initial load failed'));
		self::assertTrue($context->isFailed());
		self::assertSame([], self::privateValue($context, 'switchbackSources'));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(-1, $context->getVariableValue('sb.arm', -1));
		self::assertSame(0, $context->peekTreatment('exp_sb'));
	}

	/** A failed refresh keeps the switchback definitions and their epoch. */
	public function testFailedRefreshKeepsTheEpoch(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		// A failing provider leaves the installed period grid untouched.
		$provider = new class extends \ABSmartly\SDK\Context\ContextDataProvider {
			public function __construct() {}
			public function getContextData(): ContextData { throw new \RuntimeException('refresh failed'); }
		};
		$property = new \ReflectionProperty(Context::class, 'dataProvider');
		if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
		$property->setValue($context, $provider);
		@$context->refresh();
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
	}

	/** A switchback whose rule outcome changes after setAttribute is evaluated once to reject and once to rebuild. */
	public function testStaleRuleTransitionEvaluatesTheRulesTwice(): void {
		$experiment = self::switchback(2);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1_TO_US;
		$context = $this->createContext(self::dataOf($experiment));
		$counts = self::countEvaluations($context);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->peekTreatment('exp_sb'));
		self::assertSame(1, $counts->rules);

		$context->setAttributeValue('country', 'US');
		$counts->rules = 0;
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(2, $counts->rules);
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(2, $counts->rules);

		$context->setAttributeValue('other', 1);
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(3, $counts->rules);
	}

	/** A switchback without rules or an audience never evaluates either, even after setAttribute. */
	public function testARuleFreeSwitchbackNeverEvaluatesRulesOrAudience(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$counts = self::countEvaluations($context);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$context->setAttributeValue('country', 'US');
		$context->getTreatment('exp_sb');
		$context->getVariableValue('sb.arm', -1);
		self::assertSame([0, 0], [$counts->rules, $counts->audiences]);
	}

	/** An audience is re-evaluated once per attribute change, not on every read. */
	public function testAudienceIsRevalidatedOncePerAttributeChange(): void {
		$experiment = self::switchback(2);
		$experiment->audience = self::STRICT_GOLD_AUDIENCE;
		$context = $this->createContext(self::dataOf($experiment));
		$counts = self::countEvaluations($context);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		self::assertSame(1, $counts->audiences);
		$context->setAttributeValue('other', 1);
		$context->getTreatment('exp_sb');
		$context->getTreatment('exp_sb');
		self::assertSame(2, $counts->audiences);
		self::assertSame(1, $context->getPendingCount());
	}

	/** A legacy switchback serves under its declared unitType, so a unitType change is identity for it. */
	public function testLegacySwitchbackUnitTypeChangeIsIdentity(): void {
		$legacy = self::switchback(2);
		$legacy->switchbackClusters = null;
		$context = $this->createContext(self::dataOf($legacy), [self::$UNIT_TYPE => self::$UID, 'user_id' => 'u1']);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getVariableValue('sb.arm', -1));
		self::assertSame(self::$UNIT_TYPE, $this->resolution($context, 'exp_sb')['unitType']);

		$other = self::switchback(2);
		$other->switchbackClusters = null;
		$other->unitType = 'other';
		$this->refreshTo($context, $other);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(-1, $context->getVariableValue('sb.arm', -1));

		$user = self::switchback(2);
		$user->switchbackClusters = null;
		$user->unitType = 'user_id';
		$this->refreshTo($context, $user);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getVariableValue('sb.arm', -1));
		$resolution = $this->resolution($context, 'exp_sb');
		self::assertSame('user_id', $resolution['unitType']);
		self::assertFalse($resolution['eligible']);
		self::assertTrue($resolution['silent']);
		self::assertSame(0, $context->getPendingCount());
	}

	/** An ineligible or absent slot never consulted its rules: neither setAttribute nor a rules refresh re-evaluates them. */
	public function testRulesAreNotReevaluatedForAnIneligibleOrAbsentSlot(): void {
		$ineligible = self::switchback(2, null, 1, 'exp_ineligible');
		$ineligible->assignmentRules = self::ASSIGN_VARIANT_1;
		$ineligible->switchbackClusterValues = ['other-store'];
		$absent = self::switchback(2, null, 2, 'exp_absent');
		$absent->assignmentRules = self::ASSIGN_VARIANT_1;
		$absent->switchbackClusters = self::clusters('device_id', self::$CLUSTER_PATH);
		$context = $this->createContext(self::dataOf($ineligible, $absent));
		$counts = self::countEvaluations($context);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->getTreatment('exp_ineligible'));
		self::assertSame(0, $context->getTreatment('exp_absent'));
		self::assertSame(0, $counts->rules);

		$context->setAttributeValue('x', 1);
		$changed = '{"rules":[{"type":"assign","environments":[],"variant":1,"name":"changed"}]}';
		$ineligibleRefreshed = clone $ineligible;
		$ineligibleRefreshed->assignmentRules = $changed;
		$absentRefreshed = clone $absent;
		$absentRefreshed->assignmentRules = $changed;
		$this->refreshTo($context, $ineligibleRefreshed, $absentRefreshed);

		self::assertSame(0, $context->getTreatment('exp_ineligible'));
		self::assertSame(0, $context->getTreatment('exp_absent'));
		self::assertSame(0, $counts->rules);
		self::assertSame(0, $context->getPendingCount());
	}

	/** A rule-won switchback re-validated in the same period keeps its variant and is exposed once. */
	public function testInPeriodRuleRefreshKeepsTheVariantAndExposesOnce(): void {
		$experiment = self::switchback(2);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		$context = $this->createContext(self::dataOf($experiment));
		$this->now = self::$ANCHOR + 5;
		self::assertSame(1, $context->getTreatment('exp_sb'));

		$context->setAttributeValue('tier', 'x');
		$refreshed = self::switchback(2);
		$refreshed->assignmentRules = '{"rules":[{"type":"assign","environments":[],"variant":1,'
			. '"conditions":{"and":[{"eq":[{"var":"tier"},{"value":"x"}]}]}}]}';
		$this->refreshTo($context, $refreshed);
		$this->now = self::$ANCHOR + 6;
		self::assertSame(1, $context->getTreatment('exp_sb'));
		self::assertSame([self::ruleExposure('exp_sb', 1, self::$ANCHOR + 5)], $this->publishExposures($context));
	}

	/** A rule that stops matching within the period re-resolves to the draw and exposes it. */
	public function testInPeriodRuleRemovalReresolvesToTheDraw(): void {
		$experiment = self::switchback(2);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1_TO_US;
		$context = $this->createContext(self::dataOf($experiment));
		$context->setAttributeValue('country', 'US');
		$this->now = self::$ANCHOR + self::$HOUR * 2 + 5;
		self::assertSame(1, $context->getTreatment('exp_sb'));
		self::assertSame(0, self::$EXPECTED_2_ARMS[2]);
		$this->refreshTo($context, self::switchback(2));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([
			self::ruleExposure('exp_sb', 1, self::$ANCHOR + self::$HOUR * 2 + 5),
			self::sbExposure(0, self::$ANCHOR + self::$HOUR * 2 + 5),
		], $this->publishExposures($context));
	}

	/** Before the anchor nothing is recorded, a traffic miss included; from the anchor the same miss is recorded. */
	public function testPreAnchorTrafficMissIsSilent(): void {
		$experiment = self::switchback(2);
		$experiment->trafficSplit = [1.0, 0.0];
		$this->now = self::$ANCHOR - 1;
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getPendingCount());

		$this->now = self::$ANCHOR;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR, true, false)], $this->publishExposures($context));
	}

	/** An override on a legacy switchback with no serving unit is exposed under the declared unitType. */
	public function testOverrideWithoutAServingUnitOnALegacySwitchbackUsesTheDeclaredUnitType(): void {
		$missing = self::switchback(2, null, 1, 'exp_missing_map');
		$missing->unitType = 'user_id';
		$missing->switchbackClusters = null;
		$unreadable = self::switchback(2, null, 2, 'exp_unreadable_map');
		$unreadable->unitType = 'user_id';
		$unreadable->switchbackClusters = self::clusters(self::$UNIT_TYPE, "'unterminated");
		$noLink = self::switchback(2, null, 3, 'exp_no_linked_unit');
		$noLink->switchbackClusters = self::clusters('device_id', 'x', 'user_id', 'y');
		$context = $this->createContext(self::dataOf($missing, $unreadable, $noLink));
		$context->setOverride('exp_missing_map', 1);
		$context->setOverride('exp_unreadable_map', 1);
		$context->setOverride('exp_no_linked_unit', 1);
		$this->now = self::$ANCHOR + 5;
		self::assertSame(1, $context->getTreatment('exp_missing_map'));
		self::assertSame(1, $context->getTreatment('exp_unreadable_map'));
		self::assertSame(1, $context->getTreatment('exp_no_linked_unit'));

		self::assertSame([
			self::exposure(1, 'exp_missing_map', 'user_id', 1, self::$ANCHOR + 5, false, true, true),
			self::exposure(2, 'exp_unreadable_map', 'user_id', 1, self::$ANCHOR + 5, false, true, true),
			// No linked unit: the first declared type of a readable map.
			self::exposure(3, 'exp_no_linked_unit', 'device_id', 1, self::$ANCHOR + 5, false, true, true),
		], $this->publishExposures($context));
	}

	/** Exact wire form of a rule-won and a traffic-miss switchback exposure. */
	public function testRuleWonAndTrafficMissExposuresSerializeExactly(): void {
		$rule = self::switchback(2, null, 1, 'exp_rule');
		$rule->assignmentRules = self::ASSIGN_VARIANT_1;
		$miss = self::switchback(2, null, 2, 'exp_miss');
		$miss->trafficSplit = [1.0, 0.0];
		$context = $this->createContext(self::dataOf($rule, $miss));
		$this->now = self::$ANCHOR + 5;
		$context->getTreatment('exp_rule');
		$context->getTreatment('exp_miss');
		$this->now = self::$ANCHOR + 9;

		self::assertSame('{"hashed":true,"publishedAt":' . (self::$ANCHOR + 9) . ','
			. '"exposures":[{"id":1,"name":"exp_rule","unit":"store_id","variant":1,"exposedAt":' . (self::$ANCHOR + 5)
			. ',"assigned":false,"eligible":true,"overridden":false,"fullOn":false,"custom":false,"audienceMismatch":false,"ruleOverride":true},'
			. '{"id":2,"name":"exp_miss","unit":"store_id","variant":0,"exposedAt":' . (self::$ANCHOR + 5)
			. ',"assigned":true,"eligible":false,"overridden":false,"fullOn":false,"custom":false,"audienceMismatch":false,"ruleOverride":false}],'
			. '"units":[{"type":"store_id","uid":"' . self::$UID_HASH . '"}],'
			. '"attributes":[{"name":"' . self::$CLUSTER_PATH . '","value":"' . self::$CLUSTER_KEY . '","setAt":' . self::$ANCHOR . '}]}',
			$this->publishedJson($context));
	}

	/**
	 * PHP-only: an exposure the retained-event cap dropped is queued again by the next exposing read of its period slot,
	 * with that read's clock reading, and never by another period's.
	 */
	public function testAnExposureDroppedByTheRetainedEventCapIsQueuedAgain(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$context->maxRetainedEvents = 1;
		$this->eventHandler->prerun = static function () {
			throw new \RuntimeException('collector down');
		};
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$this->now = self::$ANCHOR + self::$HOUR + 10;
		$context->getTreatment('exp_sb');
		@$context->publish();
		self::assertSame(1, $context->getPendingCount());

		$this->eventHandler->prerun = null;
		$this->now = self::$ANCHOR + self::$HOUR + 20;
		$context->getTreatment('exp_sb');
		self::assertSame(1, $context->getPendingCount());
		$this->now = self::$ANCHOR + 20;
		$context->getTreatment('exp_sb');
		self::assertSame(2, $context->getPendingCount());
		self::assertSame([
			self::sbExposure(self::$EXPECTED_2_ARMS[1], self::$ANCHOR + self::$HOUR + 10),
			self::sbExposure(self::$EXPECTED_2_ARMS[0], self::$ANCHOR + 20),
		], $this->publishExposures($context));
	}

	/** A re-queue after a same-period cluster attribute change re-sends the cached arm: the change waits for the next period. */
	public function testARequeueAfterAnAttributeChangeKeepsTheCachedArm(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$context->maxRetainedEvents = 1;
		$this->eventHandler->prerun = static function () {
			throw new \RuntimeException('collector down');
		};
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		$this->now = self::$ANCHOR + 15;
		$context->trackWithProperties('goal');
		@$context->publish();
		self::assertSame(1, $context->getPendingCount());

		$this->eventHandler->prerun = null;
		$this->now = self::$ANCHOR + 20;
		$context->setAttributeValue(self::$CLUSTER_PATH, 'Faro');
		self::assertSame(1, SwitchbackAssigner::assign('Faro', 0, self::seed(), 2));
		$this->now = self::$ANCHOR + 30;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR + 30)], $this->publishExposures($context));
	}

	/** The unit types of each published batch, in order, with each batch's exposure names. */
	private function publishedBatches(): array {
		$batches = [];
		foreach ($this->eventHandler->submitted as $event) {
			$batches[] = [array_map(static function ($unit) {
				return $unit->type;
			}, $event->units ?? []), array_map(static function ($exposure) {
				return $exposure->name;
			}, $event->exposures ?? []), count($event->goals ?? [])];
		}
		return $batches;
	}

	/**
	 * The Collector resolves a switchback's serving unit again from the publish's units, so a linked unit added after an
	 * exposure is queued (here with a disagreeing cluster value, which would drop it) must not reach that exposure: it is
	 * published first, in its own batch with the units of its read, and the rest follow with the current units.
	 */
	public function testAUnitAddedAfterAQueuedExposureIsNotPublishedWithIt(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters('user_id', 'user_store', self::$UNIT_TYPE, self::$CLUSTER_PATH);
		$context = $this->createContext(self::dataOf($experiment, self::unitExperiment()), null,
			[self::$CLUSTER_PATH => self::$CLUSTER_KEY, 'user_store' => 'Faro']);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		$this->now = self::$ANCHOR + self::$HOUR + 10;
		$context->setUnit('user_id', 'u1');
		$context->getTreatment('exp_unit');
		$context->trackWithProperties('goal');
		self::assertSame(3, $context->getPendingCount());

		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'user_id'], ['exp_unit'], 1],
		], $this->publishedBatches());
		self::assertSame(self::$ANCHOR + 10, (int) $this->eventHandler->submitted[0]->exposures[0]->exposedAt);
	}

	/** A batch that fails keeps every event queued; the retry still publishes the switchback with its own units. */
	public function testAUnitAddedBeforeARetryIsNotPublishedWithTheQueuedExposure(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters('user_id', 'user_store', self::$UNIT_TYPE, self::$CLUSTER_PATH);
		$context = $this->createContext(self::dataOf($experiment), null, [self::$CLUSTER_PATH => self::$CLUSTER_KEY, 'user_store' => 'Faro']);
		$this->eventHandler->prerun = static function () {
			throw new \RuntimeException('collector down');
		};
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$context->trackWithProperties('goal');
		@$context->publish();
		self::assertSame(2, $context->getPendingCount());

		$context->setUnit('user_id', 'u1');
		@$context->publish();
		self::assertSame(2, $context->getPendingCount());

		$this->eventHandler->prerun = null;
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'user_id'], [], 1],
		], $this->publishedBatches());
	}

	/** With no unit added, everything goes out in the one batch it always did. */
	public function testExposuresWithTheSameUnitsShareOneBatch(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2), self::unitExperiment()));
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$context->getTreatment('exp_unit');
		$context->setUnit(self::$UNIT_TYPE, self::$UID);
		$context->trackWithProperties('goal');
		$context->publish();
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb', 'exp_unit'], 1]], $this->publishedBatches());
	}

	/** A context whose exp_sb exposure, served by store_id, is queued before user_id is added; user_id would disagree. */
	private function contextWithAnEarlierUnitExposure(): Context {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters('user_id', 'user_store', self::$UNIT_TYPE, self::$CLUSTER_PATH);
		$context = $this->createContext(self::dataOf($experiment, self::unitExperiment()), null,
			[self::$CLUSTER_PATH => self::$CLUSTER_KEY, 'user_store' => 'Faro']);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$context->setUnit('user_id', 'u1');
		return $context;
	}

	/** Fails the handler's first $failures calls. */
	private function failFirst(int $failures): void {
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $failures) {
			if (++$calls <= $failures) {
				throw new \RuntimeException('collector down');
			}
		};
	}

	private function publishEventCount(): int {
		return count(array_filter($this->logger->events ?? [], static function ($event) {
			return $event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Publish;
		}));
	}

	/** A failed earlier batch stops the publish: the later batch is not sent ahead of it, and the retry keeps the order. */
	public function testAFailedEarlierBatchHoldsBackTheRest(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$context->getTreatment('exp_unit');
		$context->trackWithProperties('goal');
		$this->failFirst(1);
		@$context->publish();
		self::assertSame(3, $context->getPendingCount());
		self::assertSame([], $this->publishedBatches());

		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'user_id'], ['exp_unit'], 1],
		], $this->publishedBatches());
	}

	/** An earlier batch that succeeds is cleared even when the later batch fails. */
	public function testASentEarlierBatchIsClearedWhenTheLaterOneFails(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$context->trackWithProperties('goal');
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls) {
			if (++$calls === 2) {
				throw new \RuntimeException('collector down');
			}
		};
		@$context->publish();
		self::assertSame(1, $context->getPendingCount());
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0]], $this->publishedBatches());
	}

	public function testAnEarlierBatchCarriesTheAttributesAndIsLogged(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0]], $this->publishedBatches());
		self::assertSame([[self::$CLUSTER_PATH, self::$CLUSTER_KEY], ['user_store', 'Faro']],
			array_map(static function ($attribute) {
				return [$attribute->name, $attribute->value];
			}, $this->eventHandler->submitted[0]->attributes));
		self::assertSame(1, $this->publishEventCount());
	}

	/** Exposures read with different units go out in one batch per unit set, in queue order. */
	public function testEachEarlierUnitSetGetsItsOwnBatch(): void {
		$other = self::switchback(2, null, 2, 'exp_other');
		$other->switchbackClusters = self::clusters(self::$UNIT_TYPE, self::$CLUSTER_PATH);
		$context = $this->createContext(self::dataOf(
			(function () {
				$experiment = self::switchback(2);
				$experiment->switchbackClusters = self::clusters('user_id', 'user_store', self::$UNIT_TYPE, self::$CLUSTER_PATH);
				return $experiment;
			})(), $other), null, [self::$CLUSTER_PATH => self::$CLUSTER_KEY, 'user_store' => 'Faro']);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$context->setUnit('user_id', 'u1');
		$context->getTreatment('exp_other');
		$context->setUnit('device_id', 'd1');
		$context->publish();
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'user_id'], ['exp_other'], 0],
		], $this->publishedBatches());
		self::assertSame(2, $this->publishEventCount());
	}

	/** A slot rebuilt from its cached serving keeps the units that serving was read with. */
	public function testARebuiltSlotKeepsItsServedUnits(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters('user_id', 'user_store', self::$UNIT_TYPE, self::$CLUSTER_PATH);
		$context = $this->createContext(self::dataOf($experiment), null, [self::$CLUSTER_PATH => self::$CLUSTER_KEY, 'user_store' => 'Faro']);
		$this->now = self::$ANCHOR + 10;
		$context->peekTreatment('exp_sb');
		$context->setUnit('user_id', 'u1');
		$context->setCustomAssignment('exp_sb', 1);
		$context->getTreatment('exp_sb');
		$context->publish();
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0]], $this->publishedBatches());
	}

	/** An earlier batch's failure applies the retained-event cap, as the main batch's does: its oldest exposure goes first. */
	public function testAFailedEarlierBatchAppliesTheRetainedEventCap(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$context->maxRetainedEvents = 1;
		$context->trackWithProperties('goal');
		$context->trackWithProperties('goal');
		$this->failFirst(1);
		@$context->publish();
		self::assertSame(1, $context->getPendingCount());
		$context->publish();
		self::assertSame([[[self::$UNIT_TYPE, 'user_id'], [], 1]], $this->publishedBatches());
	}

	/** close() re-throws an earlier batch's failure and stays open with the events queued. */
	public function testCloseRethrowsAFailedEarlierBatch(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$this->failFirst(1);
		try {
			@$context->close();
			self::fail('close() did not throw');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('collector down', $exception->getMessage());
		}
		self::assertFalse($context->isClosed());
		self::assertSame(1, $context->getPendingCount());
	}

	private function setLogger(Context $context, \ABSmartly\SDK\Context\ContextEventLogger $logger): void {
		$property = new \ReflectionProperty(Context::class, 'eventLogger');
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		$property->setValue($context, $logger);
	}

	/** A Publish logger that calls $then once, on the first Publish. */
	private static function publishCallback(callable $then): \ABSmartly\SDK\Context\ContextEventLogger {
		return new class($then) implements \ABSmartly\SDK\Context\ContextEventLogger {
			private $then;
			private bool $called = false;

			public function __construct(callable $then) {
				$this->then = $then;
			}

			public function handleEvent(Context $context, \ABSmartly\SDK\Context\ContextEventLoggerEvent $event): void {
				if ($event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Publish && !$this->called) {
					$this->called = true;
					($this->then)($context);
				}
			}
		};
	}

	/** Without switchbacks, a goal a Publish logger tracks and publishes is sent, as before. */
	public function testAGoalPublishedFromAPublishLoggerIsSent(): void {
		$context = $this->createContext(self::dataOf(self::unitExperiment()));
		$context->getTreatment('exp_unit');
		$this->setLogger($context, self::publishCallback(static function (Context $context) {
			$context->trackWithProperties('from_logger');
			$context->publish();
		}));
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertCount(2, $this->eventHandler->submitted);
	}

	/** Without switchbacks, a goal a Publish logger tracks before close() is sent and the context closes. */
	public function testAGoalClosedFromAPublishLoggerIsSent(): void {
		$context = $this->createContext(self::dataOf(self::unitExperiment()));
		$context->getTreatment('exp_unit');
		$this->setLogger($context, self::publishCallback(static function (Context $context) {
			$context->trackWithProperties('from_logger');
			$context->close();
		}));
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertCount(2, $this->eventHandler->submitted);
		self::assertTrue($context->isClosed());
	}

	/** A switchback exposure queued from a Publish callback during the earlier batches still gets its served units. */
	public function testASwitchbackExposureQueuedFromACallbackKeepsItsUnits(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters('user_id', 'user_store', self::$UNIT_TYPE, self::$CLUSTER_PATH);
		$other = self::switchback(2, null, 2, 'exp_other');
		$other->switchbackClusters = $experiment->switchbackClusters;
		$context = $this->createContext(self::dataOf($experiment, $other), null,
			[self::$CLUSTER_PATH => self::$CLUSTER_KEY, 'user_store' => 'Faro']);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$context->peekTreatment('exp_other');
		$context->setUnit('user_id', 'u1');
		$this->setLogger($context, self::publishCallback(static function (Context $context) {
			$context->getTreatment('exp_other');
		}));
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE], ['exp_other'], 0],
		], $this->publishedBatches());
	}

	public function testPublishLoggerCannotDrainTheOuterBatchPlan(): void {
		$other = self::switchback(2, null, 2, 'exp_other');
		$context = $this->createContext(self::dataOf(self::switchback(2), $other));
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$context->setUnit('user_id', 'u1');
		$context->getTreatment('exp_other');
		$context->setUnit('device_id', 'd1');
		$logger = new class implements \ABSmartly\SDK\Context\ContextEventLogger {
			private bool $called = false;
			public function handleEvent(Context $context, \ABSmartly\SDK\Context\ContextEventLoggerEvent $event): void {
				if ($event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Publish && !$this->called) {
					$this->called = true;
					$context->trackWithProperties('from_logger');
					$context->publish();
				}
			}
		};
		$this->setLogger($context, $logger);
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'user_id'], ['exp_other'], 0],
			[[self::$UNIT_TYPE, 'user_id', 'device_id'], [], 1],
		], $this->publishedBatches());
	}

	public function testThrowingEarlierPublishLoggerDoesNotRetainAcceptedEvents(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$logger = new class implements \ABSmartly\SDK\Context\ContextEventLogger {
			public function handleEvent(Context $context, \ABSmartly\SDK\Context\ContextEventLoggerEvent $event): void {
				if ($event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Publish) {
					throw new \RuntimeException('logger down');
				}
			}
		};
		$this->setLogger($context, $logger);
		@$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0]], $this->publishedBatches());
		$context->trackWithProperties('later');
		@$context->publish();
		self::assertCount(2, $this->eventHandler->submitted);
		self::assertSame(0, $context->getPendingCount());
	}

	/** An event handler that publishes and then queues an exposure during an early batch keeps that exposure queued. */
	public function testAnExposureQueuedFromTheHandlerDuringAnEarlierBatchIsKept(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 1) {
				$context->publish();
				$context->getTreatment('exp_unit');
			}
		};
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(['exp_unit'], array_column(end($this->eventHandler->submitted)->exposures, 'name'));
	}

	/** An absent override never rides in an earlier batch, even one whose units match its own. */
	public function testAnAbsentOverrideStaysOutOfAMatchingEarlierBatch(): void {
		$device = self::switchback(2, null, 2, 'exp_dev');
		$device->switchbackClusters = self::clusters('device_id', 'device_key');
		$context = $this->createContext(self::dataOf(self::switchback(2), $device), null,
			[self::$CLUSTER_PATH => self::$CLUSTER_KEY, 'device_key' => 'Porto']);
		$context->setOverride('exp_dev', 1);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$context->getTreatment('exp_dev');
		$context->setUnit('device_id', 'd1');
		$context->publish();
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'device_id'], ['exp_dev'], 0],
		], $this->publishedBatches());
	}

	/** After an earlier batch, the main batch hands the handler a list of exposures, with no holes. */
	public function testTheMainBatchExposuresAreAListAfterAnEarlierBatch(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$context->getTreatment('exp_unit');
		$context->publish();
		self::assertSame([[0], [0]], $this->eventHandler->exposureKeys);
	}

	/** An absent slot queued first, under other units, does not stop a later exposure from getting its earlier batch. */
	public function testAnAbsentSlotQueuedFirstDoesNotHideALaterEarlierBatch(): void {
		$device = self::switchback(2, null, 2, 'exp_dev');
		$device->switchbackClusters = self::clusters('device_id', 'device_key');
		$context = $this->createContext(self::dataOf(self::switchback(2), $device), null,
			[self::$CLUSTER_PATH => self::$CLUSTER_KEY, 'device_key' => 'Porto']);
		$context->setOverride('exp_dev', 1);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_dev');
		$context->setUnit('other_id', 'o1');
		$context->getTreatment('exp_sb');
		$context->setUnit('device_id', 'd1');
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE, 'other_id'], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'other_id', 'device_id'], ['exp_dev'], 0],
		], $this->publishedBatches());
	}

	/**
	 * A split rule is revalidated on the serving unit, not the declared unitType the context may not have, and on an
	 * attribute change: here one that makes an earlier conditional 0/100 split win re-exposes under its arm.
	 */
	public function testASplitRuleIsRevalidatedOnTheServingUnit(): void {
		$experiment = self::switchback(2);
		$experiment->unitType = 'user_id';
		$experiment->assignmentRules = '{"rules":[{"type":"split","environments":[],'
			. '"conditions":{"and":[{"eq":[{"var":"tier"},{"value":"x"}]}]},"percentages":"0/100"},'
			. '{"type":"split","environments":[],"percentages":"50/50"}]}';
		$switched = 0;
		for ($u = 0; $u < 16; ++$u) {
			$uid = 'store-' . $u;
			$expected = (new VariantAssigner($uid))->assign([0.5, 0.5], self::$SEED_HI, self::$SEED_LO);
			$context = $this->createContext(self::dataOf($experiment), [self::$UNIT_TYPE => $uid]);
			self::assertSame($expected, $context->getTreatment('exp_sb'), $uid);
			$context->setAttributeValue('unrelated', true);
			self::assertSame($expected, $context->getTreatment('exp_sb'), $uid);
			self::assertSame(1, $context->getPendingCount(), $uid);
			$context->setAttributeValue('tier', 'x');
			self::assertSame(1, $context->getTreatment('exp_sb'), $uid);
			self::assertSame($expected === 1 ? 1 : 2, $context->getPendingCount(), $uid);
			$switched += $expected === 0 ? 1 : 0;
		}
		self::assertGreaterThan(0, $switched);
	}

	/**
	 * The slot keeps the units the cluster was resolved with, even when a cluster attribute's serializer sets a unit
	 * during the resolution: the exposure is published with those units, so the Collector serves the same cluster.
	 */
	public function testAUnitSetWhileResolvingTheClusterIsNotPublishedWithTheExposure(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters('user_id', 'user_store', self::$UNIT_TYPE, 'geo/city');
		$context = $this->createContext(self::dataOf($experiment), null, ['user_store' => 'Faro']);
		$geo = new class($context) implements \JsonSerializable {
			private Context $context;

			public function __construct(Context $context) {
				$this->context = $context;
			}

			public function jsonSerialize(): array {
				$this->context->setUnit('user_id', 'u1');
				return ['city' => 'store-42'];
			}
		};
		$this->setReadAttribute($context, 'geo', $geo);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertSame('u1', $context->getUnit('user_id'));
		$this->failFirst(1);
		@$context->publish();
		self::assertSame([], $this->publishedBatches());
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0]], $this->publishedBatches());
	}

	/** A handler that publishes during an early batch does not send that batch's exposure again; its goal goes out. */
	public function testANestedPublishDoesNotResendTheEarlierBatchInFlight(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 1) {
				$context->trackWithProperties('goal');
				$context->publish();
				$context->publish();
			}
		};
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE, 'user_id'], [], 1],
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
		], $this->publishedBatches());
	}

	/** An early batch that fails after a nested publish stays queued and is retried once, with its units. */
	public function testAnEarlierBatchFailingAfterANestedPublishIsRetried(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 1) {
				$context->trackWithProperties('goal');
				$context->publish();
				throw new \RuntimeException('collector down');
			}
		};
		@$context->publish();
		self::assertSame(1, $context->getPendingCount());
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE, 'user_id'], [], 1],
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
		], $this->publishedBatches());
	}

	/**
	 * A nested publish that fails and hits the retained-event cap drops only events retained for retry: the early batch
	 * being sent neither counts toward the cap nor is dropped, so the goal that fits is kept and sent by the outer
	 * publish, and a later read does not queue the accepted batch again.
	 */
	public function testTheRetainedEventCapKeepsAnEarlierBatchInFlight(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$context->maxRetainedEvents = 1;
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 2) {
				throw new \RuntimeException('collector down');
			}
			if ($calls === 1) {
				$context->trackWithProperties('purchase');
				$context->trackWithProperties('purchase');
				@$context->publish();
			}
		};
		@$context->publish();
		self::assertSame(0, $context->getPendingCount());
		$context->getTreatment('exp_sb');
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'user_id'], [], 1],
		], $this->publishedBatches());
	}

	/** A goal-only nested publish that fails keeps its goal under a cap the early batch in flight would exceed. */
	public function testAnEarlierBatchInFlightDoesNotEvictARetryableGoal(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$context->maxRetainedEvents = 1;
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 2) {
				throw new \RuntimeException('collector down');
			}
			if ($calls === 1) {
				$context->trackWithProperties('purchase');
				@$context->publish();
			}
		};
		$log = tempnam(sys_get_temp_dir(), 'sdklog');
		$previous = ini_set('error_log', $log);
		try {
			$context->publish();
		}
		finally {
			ini_set('error_log', $previous === false ? '' : $previous);
			$logged = file_get_contents($log);
			unlink($log);
		}
		// The failed nested send held only the goal; the early exposure was not part of it.
		self::assertStringContainsString('Failed to publish 0 exposures and 1 goals', $logged);
		self::assertStringNotContainsString('Retained event queue exceeded', $logged);
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
			[[self::$UNIT_TYPE, 'user_id'], [], 1],
		], $this->publishedBatches());
	}

	/** A refresh that only changes a switchback's id starts a new epoch, so the next read exposes under the new id. */
	public function testARefreshChangingTheIdExposesUnderTheNewId(): void {
		$context = $this->createContext(self::dataOf(self::switchback(3)));
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		$this->refreshTo($context, self::switchback(3, null, 9));
		$context->getTreatment('exp_sb');
		self::assertSame([1, 9], array_column($this->publishExposures($context), 0));
	}

	/** A rule-won slot never read the custom assignment, so a later setCustomAssignment() keeps it and its exposure. */
	public function testARuleWonSlotIgnoresALaterCustomAssignment(): void {
		$experiment = self::switchback(3);
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		$context = $this->createContext(self::dataOf($experiment));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(1, $context->getTreatment('exp_sb'));
		$context->setCustomAssignment('exp_sb', 2);
		self::assertSame(1, $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());
	}

	/** A refresh that only changes the iteration gives the switchback a new generation. */
	public function testARefreshChangingTheIterationStartsANewGeneration(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$before = self::privateValue($context, 'switchbackSources')['exp_sb']->generation;
		$this->refreshTo($context, self::switchback(2));
		self::assertSame($before, self::privateValue($context, 'switchbackSources')['exp_sb']->generation);
		$next = self::switchback(2);
		$next->iteration = 2;
		$this->refreshTo($context, $next);
		self::assertNotSame($before, self::privateValue($context, 'switchbackSources')['exp_sb']->generation);
	}

	/** The retained-event cap leaves a list of exposures, so the next publish hands the handler one with no holes. */
	public function testTheRetainedEventCapLeavesTheExposuresAList(): void {
		$context = $this->createContext(self::dataOf(self::unitExperiment(), self::unitExperiment(2, 3, 'exp_other')));
		$context->maxRetainedEvents = 1;
		$context->getTreatment('exp_unit');
		$context->getTreatment('exp_other');
		$this->failFirst(1);
		@$context->publish();
		$context->publish();
		self::assertSame([[0]], $this->eventHandler->exposureKeys);
		self::assertSame([[[self::$UNIT_TYPE], ['exp_other'], 0]], $this->publishedBatches());
	}

	/**
	 * A logger that refreshes the first switchback in mid-walk hands the unvisited candidates to the switchback-aware
	 * walk, so a candidate that is now a strict-mismatched switchback records nothing.
	 */
	public function testAVariableWalkHandsOffWhenALoggerInstallsTheFirstSwitchback(): void {
		$first = self::unitExperiment(2, 1, 'exp_a');
		$first->unitType = 'user_id';
		$first->variants = [(object) ['name' => 'A', 'config' => '{"k":1}'], (object) ['name' => 'B', 'config' => '{"k":2}']];
		$second = self::unitExperiment(2, 2, 'exp_b');
		$second->variants = $first->variants;
		$second->audience = self::STRICT_GOLD_AUDIENCE;
		$second->audienceStrict = true;
		$switchback = self::switchback(2, null, 2, 'exp_b');
		$switchback->variants = $first->variants;
		$switchback->audience = self::STRICT_GOLD_AUDIENCE;
		$switchback->audienceStrict = true;
		$context = $this->createContext(self::dataOf($first, $second));
		$this->now = self::$ANCHOR + 10;
		$this->dataProvider->next = self::dataOf($first, $switchback);
		$this->setLogger($context, new class implements \ABSmartly\SDK\Context\ContextEventLogger {
			private bool $refreshed = false;

			public function handleEvent(Context $context, \ABSmartly\SDK\Context\ContextEventLoggerEvent $event): void {
				if ($event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Exposure && !$this->refreshed) {
					$this->refreshed = true;
					$context->refresh();
				}
			}
		});
		self::assertSame(-1, $context->getVariableValue('k', -1));
		self::assertSame(0, $context->getPendingCount());
		self::assertFalse(self::privateValue($context, 'hasSwitchbacks'));
	}

	/**
	 * A logger that refreshes the first switchback in and then throws hands its error to the switchback-aware walk: the
	 * later switchback is still exposed before the error is re-thrown.
	 */
	public function testAThrowingLoggerThatInstallsTheFirstSwitchbackStillExposesItBeforeRethrowing(): void {
		$first = self::unitExperiment(2, 1, 'exp_a');
		$first->unitType = 'user_id';
		$first->variants = [(object) ['name' => 'A', 'config' => '{"k":1}'], (object) ['name' => 'B', 'config' => '{"k":2}']];
		$second = self::unitExperiment(2, 2, 'exp_b');
		$second->variants = $first->variants;
		$switchback = self::switchback(2, null, 2, 'exp_b');
		$switchback->variants = $first->variants;
		$context = $this->createContext(self::dataOf($first, $second));
		$this->now = self::$ANCHOR + 10;
		$this->dataProvider->next = self::dataOf($first, $switchback);
		$this->setLogger($context, new class implements \ABSmartly\SDK\Context\ContextEventLogger {
			private bool $refreshed = false;

			public function handleEvent(Context $context, \ABSmartly\SDK\Context\ContextEventLoggerEvent $event): void {
				if ($event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Exposure && !$this->refreshed) {
					$this->refreshed = true;
					$context->refresh();
					throw new \RuntimeException('logger failed');
				}
			}
		});
		try {
			$context->getVariableValue('k', -1);
			self::fail('the logger error is re-thrown');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('logger failed', $exception->getMessage());
		}
		self::assertSame(1, $context->getPendingCount());
	}

	public function testAVariableWalkHandOffDoesNotReplayAVisitedCandidate(): void {
		$variants = [(object) ['name' => 'A', 'config' => '{"k":1}'], (object) ['name' => 'B', 'config' => '{"k":2}']];
		$first = self::unitExperiment(2, 1, 'exp_a');
		$first->unitType = 'user_id';
		$first->variants = $variants;
		$second = self::unitExperiment(2, 2, 'exp_b');
		$second->variants = $variants;
		$refreshedFirst = self::switchback(2, null, 1, 'exp_a');
		$refreshedFirst->variants = $variants;
		$refreshedFirst->fullOnVariant = 1;
		$refreshedSecond = self::switchback(2, null, 2, 'exp_b');
		$refreshedSecond->variants = $variants;
		$context = $this->createContext(self::dataOf($first, $second));
		$this->now = self::$ANCHOR + 10;
		$this->dataProvider->next = self::dataOf($refreshedFirst, $refreshedSecond);
		$this->setLogger($context, self::refreshOnFirstExposure());
		self::assertSame(self::$EXPECTED_2_ARMS[0] + 1, $context->getVariableValue('k', -1));
		self::assertSame(['exp_b'], array_column($this->publishExposures($context), 1));
	}

	public function testARejectedRefreshMidWalkKeepsThePreviousWalk(): void {
		$variants = [(object) ['name' => 'A', 'config' => '{"k":1}'], (object) ['name' => 'B', 'config' => '{"k":2}']];
		$first = self::unitExperiment(2, 1, 'exp_a');
		$first->unitType = 'user_id';
		$first->variants = $variants;
		$second = self::unitExperiment(2, 2, 'exp_b');
		$second->variants = $variants;
		$broken = self::unitExperiment(2, 3, 'exp_c');
		unset($broken->id);
		$context = $this->createContext(self::dataOf($first, $second));
		$this->now = self::$ANCHOR + 10;
		$this->dataProvider->next = self::dataOf(self::switchback(2, null, 4, 'exp_sb'), $broken);
		$this->setLogger($context, self::onContextEvent(static function ($context, $event) {
			if ($event === 'Error') { throw new \RuntimeException('logger failed'); }
			if ($event === 'Exposure') { $context->refresh(); throw new \RuntimeException('logger failed'); }
		}));
		try {
			@$context->getVariableValue('k', -1);
			self::fail('the logger error is re-thrown');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('logger failed', $exception->getMessage());
		}
		self::assertSame(1, $context->getPendingCount());
		self::assertFalse(self::privateValue($context, 'hasSwitchbacks'));
	}

	/** A logger that closes the context mid-walk stops a later switchback from queueing an exposure that cannot publish. */
	public function testASwitchbackIsNotQueuedAfterALoggerClosesTheContext(): void {
		$variants = [(object) ['name' => 'A', 'config' => '{"other":1}'], (object) ['name' => 'B', 'config' => '{"other":2}']];
		$unit = self::unitExperiment(2, 1, 'exp_unit');
		$unit->variants = $variants;
		$unit->variants[0]->config = '{"sb.arm":0}';
		$unit->variants[1]->config = '{"sb.arm":0}';
		$unit->unitType = 'user_id';
		$switchback = self::switchback(2, null, 2);
		$switchback->variants = [(object) ['name' => 'A', 'config' => '{"sb.arm":10}'], (object) ['name' => 'B', 'config' => '{"sb.arm":11}']];
		$context = $this->createContext(self::dataOf($unit, $switchback));
		$this->now = self::$ANCHOR + 10;
		$this->setLogger($context, new class implements \ABSmartly\SDK\Context\ContextEventLogger {
			public array $events = [];

			public function handleEvent(Context $context, \ABSmartly\SDK\Context\ContextEventLoggerEvent $event): void {
				$this->events[] = $event->getEvent();
				if ($event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Exposure && !$context->isClosed()) {
					$context->close();
				}
			}
		});
		$logger = self::privateValue($context, 'eventLogger');
		self::assertSame(10 + self::$EXPECTED_2_ARMS[0], $context->getVariableValue('sb.arm', -1));
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(['Exposure', 'Publish', 'Close'], $logger->events);
	}

	/** A cluster attribute's serializer that closes the context leaves no switchback exposure queued on it. */
	public function testASerializerThatClosesTheContextQueuesNoSwitchbackExposure(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'geo/city');
		$context = $this->createContext(self::dataOf($experiment), null, []);
		$this->setReadAttribute($context, 'geo', new class($context) implements \JsonSerializable {
			private Context $context;

			public function __construct(Context $context) {
				$this->context = $context;
			}

			public function jsonSerialize(): array {
				if (!$this->context->isClosed()) {
					$this->context->close();
				}
				return ['city' => 'store-42'];
			}
		});
		$this->now = self::$ANCHOR + 10;
		self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
	}

	/**
	 * A refresh from a cluster attribute's serializer that replaces the switchback wins over the read in progress: that
	 * read finishes on its snapshot, and later reads serve and expose the refreshed experiment.
	 */
	public function testAnOlderReadDoesNotReplaceARefreshedSlot(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'geo/city');
		$experiment->variants = [(object) ['name' => 'A', 'config' => '{"v":0}'], (object) ['name' => 'B', 'config' => '{"v":0}']];
		$refreshed = self::switchback(2, null, 9);
		// A plain attribute: the serializer, still being encoded, cannot be read again from inside itself.
		$refreshed->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'geo_plain/city');
		$refreshed->variants = [(object) ['name' => 'A', 'config' => '{"v":99}'], (object) ['name' => 'B', 'config' => '{"v":99}']];
		$context = $this->createContext(self::dataOf($experiment), null, ['geo_plain' => ['city' => 'store-42']]);
		$this->dataProvider->next = self::dataOf($refreshed);
		$geo = new class($context) implements \JsonSerializable {
			private Context $context;
			private bool $refreshed = false;
			public $nested = null;

			public function __construct(Context $context) {
				$this->context = $context;
			}

			public function jsonSerialize(): array {
				if (!$this->refreshed) {
					$this->refreshed = true;
					$this->context->refresh();
					$this->nested = $this->context->peekVariableValue('v', -1);
				}
				return ['city' => 'store-42'];
			}
		};
		$this->setReadAttribute($context, 'geo', $geo);
		$this->now = self::$ANCHOR + 10;
		$context->peekTreatment('exp_sb');
		self::assertSame(99, $geo->nested);
		self::assertSame(99, $context->peekVariableValue('v', -1));
		self::assertSame(99, $context->getVariableValue('v', -1));
		self::assertSame([9], array_column($this->publishExposures($context), 0));
	}

	public function testANestedCloseLeavesAFailingEarlierBatchRetryable(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 1) {
				$context->close();
				throw new \RuntimeException('early send failed');
			}
		};
		try {
			@$context->close();
			self::fail('the early send failure is re-thrown');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('early send failed', $exception->getMessage());
		}
		self::assertFalse($context->isClosed());
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(0, $this->closeEventCount());
		$context->close();
		self::assertTrue($context->isClosed());
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0]], $this->publishedBatches());
		self::assertSame(1, $this->closeEventCount());
	}

	/** A nested close() whose outer early send succeeds leaves the outer caller to close: one Close, after the send. */
	public function testANestedCloseDuringASuccessfulEarlierBatchLeavesTheOuterCloseToFinish(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$context->trackWithProperties('goal');
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 1) {
				$context->close();
				self::assertFalse($context->isClosed());
			}
		};
		$context->close();
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([
			[[self::$UNIT_TYPE, 'user_id'], [], 1],
			[[self::$UNIT_TYPE], ['exp_sb'], 0],
		], $this->publishedBatches());
		$context->flush();
		self::assertSame(1, $this->closeEventCount());
	}

	private function closeEventCount(): int {
		return count(array_filter($this->logger->events ?? [], static function ($event) {
			return $event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Close;
		}));
	}

	/** An event logger that refreshes the context on its first Exposure, then throws when $throw is set. */
	private static function refreshOnFirstExposure(bool $throw = false): \ABSmartly\SDK\Context\ContextEventLogger {
		return new class($throw) implements \ABSmartly\SDK\Context\ContextEventLogger {
			private bool $throw;
			private bool $refreshed = false;

			public function __construct(bool $throw) {
				$this->throw = $throw;
			}

			public function handleEvent(Context $context, \ABSmartly\SDK\Context\ContextEventLoggerEvent $event): void {
				if ($event->getEvent() === \ABSmartly\SDK\Context\ContextEventLoggerEvent::Exposure && !$this->refreshed) {
					$this->refreshed = true;
					$context->refresh();
					if ($this->throw) {
						throw new \RuntimeException('logger failed');
					}
				}
			}
		};
	}

	/** A refresh with unchanged data during the cluster resolution keeps the slot, so the period is exposed once. */
	public function testAnUnchangedRefreshDuringResolutionKeepsThePeriodsSingleExposure(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'geo/city');
		$context = $this->createContext(self::dataOf($experiment), null, []);
		$this->dataProvider->next = self::dataOf($experiment);
		$this->setReadAttribute($context, 'geo', new class($context) implements \JsonSerializable {
			private Context $context;
			private bool $refreshed = false;

			public function __construct(Context $context) {
				$this->context = $context;
			}

			public function jsonSerialize(): array {
				if (!$this->refreshed) {
					$this->refreshed = true;
					$this->context->refresh();
				}
				return ['city' => 'store-42'];
			}
		});
		$this->now = self::$ANCHOR + 10;
		for ($i = 0; $i < 3; ++$i) {
			self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
		}
		self::assertSame(1, $context->getPendingCount());
	}

	/** A nested close() during an outer publish() closes the context once that publish leaves nothing queued. */
	public function testANestedCloseDuringAnOuterPublishClosesOnceItSucceeds(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 1) {
				$context->close();
				self::assertFalse($context->isClosed());
			}
		};
		$context->publish();
		self::assertTrue($context->isClosed());
		self::assertSame(1, $this->closeEventCount());
		self::assertSame(0, $context->getPendingCount());
		$context->flush();
		self::assertSame(1, $this->closeEventCount());
	}

	/** After a nested close() whose outer publish() fails, the next publish() that leaves nothing queued closes it. */
	public function testANestedCloseDuringAFailingOuterPublishClosesOnTheNextSuccessfulPublish(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use (&$calls, $context) {
			if (++$calls === 1) {
				$context->close();
				throw new \RuntimeException('early send failed');
			}
		};
		@$context->publish();
		self::assertFalse($context->isClosed());
		self::assertSame(1, $context->getPendingCount());
		$context->publish();
		self::assertTrue($context->isClosed());
		self::assertSame(1, $this->closeEventCount());
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0]], $this->publishedBatches());
	}

	public function testAVariableWalkHandOffSkipsACandidateTheRefreshRemoved(): void {
		$variants = [(object) ['name' => 'A', 'config' => '{"k":1}'], (object) ['name' => 'B', 'config' => '{"k":2}']];
		$first = self::unitExperiment(2, 1, 'exp_a');
		$first->variants = $variants;
		$first->audience = self::STRICT_GOLD_AUDIENCE;
		$first->audienceStrict = true;
		$removed = self::unitExperiment(2, 2, 'exp_b');
		$removed->variants = $variants;
		$removed->audience = self::STRICT_GOLD_AUDIENCE;
		$removed->audienceStrict = true;
		$last = self::unitExperiment(2, 3, 'exp_c');
		$last->variants = $variants;
		$last->fullOnVariant = 1;
		$context = $this->createContext(self::dataOf($first, $removed, $last));
		$this->now = self::$ANCHOR + 10;
		$context->peekTreatment('exp_b');
		$this->dataProvider->next = self::dataOf($first, $last, self::switchback(2, null, 4, 'exp_sb'));
		$this->setLogger($context, self::refreshOnFirstExposure());
		self::assertSame(2, $context->getVariableValue('k', -1));
		self::assertSame(['exp_c'], array_column($this->publishExposures($context), 1));
	}

	private static function onContextEvent(callable $callback): \ABSmartly\SDK\Context\ContextEventLogger {
		return new class($callback) implements \ABSmartly\SDK\Context\ContextEventLogger {
			private $callback;

			public function __construct(callable $callback) {
				$this->callback = $callback;
			}

			public function handleEvent(Context $context, \ABSmartly\SDK\Context\ContextEventLoggerEvent $event): void {
				($this->callback)($context, $event->getEvent());
			}
		};
	}

	public function testAnOuterCloseCompletesOnceWhenAPublishLoggerFlushes(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$this->eventHandler->prerun = static function () use ($context) {
			$context->close();
		};
		$events = [];
		$this->setLogger($context, self::onContextEvent(static function ($context, $event) use (&$events) {
			$events[] = $event;
			if ($event === 'Publish') {
				$context->flush();
			}
		}));
		$context->close();
		self::assertSame(['Publish', 'Close'], $events);
		self::assertTrue($context->isClosed());
	}

	public function testACloseRequestedBeforeAFailingNestedDrainIsNotLost(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use ($context, &$calls) {
			if (++$calls === 1) {
				$context->trackWithProperties('goal');
				$context->close();
			}
			elseif ($calls === 2) {
				throw new \RuntimeException('nested failed');
			}
		};
		@$context->publish();
		self::assertFalse($context->isClosed());
		self::assertSame(2, $context->getPendingCount());
		$context->publish();
		self::assertTrue($context->isClosed());
		self::assertSame(1, $this->closeEventCount());
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0], [[self::$UNIT_TYPE, 'user_id'], [], 1]], $this->publishedBatches());
	}

	public function testDeferredClosePreservesWorkQueuedDuringTheFinalSend(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use ($context, &$calls) {
			if (++$calls === 1) {
				$context->close();
				$context->trackWithProperties('first');
			}
			elseif ($calls === 2) {
				$context->trackWithProperties('second');
				$context->publish();
			}
		};
		$context->publish();
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([[], ['first'], ['second']], array_map(static function ($event) {
			return array_column($event->goals ?? [], 'name');
		}, $this->eventHandler->submitted));
	}

	public function testDeferredCloseDrainsAGoalQueuedByTheFinalPublishLogger(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$this->setLogger($context, self::publishCallback(static function ($context) {
			$context->trackWithProperties('last');
		}));
		$context->close();
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([[], ['last']], array_map(static function ($event) {
			return array_column($event->goals ?? [], 'name');
		}, $this->eventHandler->submitted));
	}

	public function testAThrowingCloseLoggerLeavesTheDeferredRequestRetryable(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$this->eventHandler->prerun = static function () use ($context) { $context->close(); };
		$closes = 0;
		$this->setLogger($context, self::onContextEvent(static function ($context, $event) use (&$closes) {
			if ($event === 'Close' && ++$closes === 1) {
				throw new \RuntimeException('close logger failed');
			}
		}));
		try {
			$context->publish();
			self::fail('expected close logger failure');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('close logger failed', $exception->getMessage());
		}
		self::assertFalse($context->isClosed());
		$context->publish();
		self::assertTrue($context->isClosed());
		self::assertSame(2, $closes);
		self::assertCount(1, $this->eventHandler->submitted);
	}

	public function testACloseLoggerCannotAcceptUnpublishableWork(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$rejected = false;
		$this->setLogger($context, self::onContextEvent(static function ($context, $event) use (&$rejected) {
			if ($event === 'Close') {
				try {
					$context->trackWithProperties('too_late');
				}
				catch (\ABSmartly\SDK\Exception\LogicException $exception) {
					$rejected = true;
				}
			}
		}));
		$context->close();
		self::assertTrue($rejected);
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
	}

	public function testAMixedWalkCannotQueueAUnitExposureAfterClose(): void {
		$switchback = self::switchback(2, null, 1);
		$switchback->variants = [(object) ['name' => 'A', 'config' => '{}'], (object) ['name' => 'B', 'config' => '{"k":2}']];
		$unit = self::unitExperiment(2, 2);
		$unit->variants = [(object) ['name' => 'A', 'config' => '{"k":99}'], (object) ['name' => 'B', 'config' => '{"k":99}']];
		$context = $this->createContext(self::dataOf($switchback, $unit));
		$this->now = self::$ANCHOR + 10;
		$events = [];
		$this->setLogger($context, self::onContextEvent(static function ($context, $event) use (&$events) {
			$events[] = $event;
			if ($event === 'Exposure') { $context->close(); }
		}));
		self::assertSame(-1, $context->getVariableValue('k', -1));
		self::assertSame(['Exposure', 'Publish', 'Close'], $events);
		self::assertSame(0, $context->getPendingCount());
	}
	public function testAnOlderReadCannotFillAnEmptySlotAfterRefresh(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'geo/city');
		$experiment->variants = [(object) ['name' => 'A', 'config' => '{"v":0}'], (object) ['name' => 'B', 'config' => '{"v":0}']];
		$refreshed = self::switchback(2, null, 9);
		// A plain attribute: the serializer, still being encoded, cannot be read again from inside itself.
		$refreshed->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'geo_plain/city');
		$refreshed->variants = [(object) ['name' => 'A', 'config' => '{"v":99}'], (object) ['name' => 'B', 'config' => '{"v":99}']];
		$context = $this->createContext(self::dataOf($experiment), null, ['geo_plain' => ['city' => 'store-42']]);
		$this->dataProvider->next = self::dataOf($refreshed);
		$test = $this;
		$geo = new class($context, $test) implements \JsonSerializable {
			private Context $context;
			private $test;
			private bool $refreshed = false;
			public $nested = null;

			public function __construct(Context $context, $test) {
				$this->test = $test;
				$this->context = $context;
			}

			public function jsonSerialize(): array {
				if (!$this->refreshed) {
					$this->refreshed = true;
					$this->context->refresh();
					$this->test->now += 3600000;
					$this->nested = $this->context->peekVariableValue('v', -1);
					$this->test->now -= 3600000;
				}
				return ['city' => 'store-42'];
			}
		};
		$this->setReadAttribute($context, 'geo', $geo);
		$this->now = self::$ANCHOR + 10;
		$context->peekTreatment('exp_sb');
		self::assertSame(99, $geo->nested);
		self::assertSame(99, $context->peekVariableValue('v', -1));
		self::assertSame(99, $context->getVariableValue('v', -1));
		self::assertSame([9], array_column($this->publishExposures($context), 0));
	}


	public function testAnOlderResolutionCannotPoisonTheInstalledRuleCache(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'geo/city');
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		$refreshed = self::switchback(2, null, 9);
		$refreshed->switchbackClusters = self::clusters(self::$UNIT_TYPE, self::$CLUSTER_PATH);
		$refreshed->assignmentRules = '{"rules":[{"type":"assign","environments":[],"variant":0}]}';
		$context = $this->createContext(self::dataOf($experiment));
		$this->dataProvider->next = self::dataOf($refreshed);
		$this->setReadAttribute($context, 'geo', new class($context) implements \JsonSerializable {
			private Context $context;
			private bool $done = false;
			public function __construct(Context $context) { $this->context = $context; }
			public function jsonSerialize(): array {
				if (!$this->done) { $this->done = true; $this->context->refresh(); }
				return ['city' => 'store-42'];
			}
		});
		$this->now = self::$ANCHOR + 10;
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		$exposures = $this->publishExposures($context);
		self::assertSame([9], array_column($exposures, 0));
		self::assertSame([0], array_column($exposures, 3));
	}

	public function testANestedReadOfTheSamePeriodExposesOnlyOnce(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, self::$CLUSTER_PATH, 'device_id', 'geo/city');
		$context = $this->createContext(self::dataOf($experiment), [self::$UNIT_TYPE => self::$UID, 'device_id' => 'd1']);
		$geo = new class($context) implements \JsonSerializable {
			private Context $context;
			private bool $done = false;
			public $nested = null;
			public function __construct(Context $context) { $this->context = $context; }
			public function jsonSerialize(): array {
				if (!$this->done) {
					$this->done = true;
					$this->nested = $this->context->getTreatment('exp_sb');
				}
				return ['city' => 'store-42'];
			}
		};
		$this->setReadAttribute($context, 'geo', $geo);
		$this->now = self::$ANCHOR + self::$HOUR + 10;
		self::assertSame(1, $context->getTreatment('exp_sb'));
		self::assertSame(1, $geo->nested);
		self::assertSame(1, $context->getPendingCount());
		self::assertCount(1, $this->publishExposures($context));
	}

	public function testANestedFinalBatchCannotResubmitItself(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$calls = 0;
		$this->eventHandler->prerun = static function () use ($context, &$calls) {
			if (++$calls === 1) {
				$context->trackWithProperties('first');
				$context->close();
			}
			elseif ($calls === 2) {
				$context->publish();
			}
		};
		$context->publish();
		self::assertTrue($context->isClosed());
		self::assertSame([[[self::$UNIT_TYPE, 'user_id'], [], 1], [[self::$UNIT_TYPE], ['exp_sb'], 0]], $this->publishedBatches());
	}

	/** A Publish logger that tracks on every send cannot keep close() publishing forever: the late goal stays queued. */
	public function testCloseReturnsWhenAPublishLoggerTracksOnEverySend(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$this->setLogger($context, self::onContextEvent(static function ($context, $event) {
			if ($event === 'Publish' && !$context->isClosed()) {
				$context->trackWithProperties('again');
			}
		}));
		$context->close();
		self::assertCount(3, $this->eventHandler->submitted);
		self::assertFalse($context->isClosed());
		self::assertSame(1, $context->getPendingCount());
	}

	/** On switchback data, as on unit data, a failed close() followed by a publish() leaves the context open. */
	public function testAPublishAfterAFailedCloseLeavesTheContextOpen(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$this->failFirst(1);
		try {
			@$context->close();
			self::fail('the close failure is re-thrown');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('collector down', $exception->getMessage());
		}
		$context->publish();
		self::assertFalse($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
		$context->trackWithProperties('later');
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(0, $this->closeEventCount());
	}

	/** A goal a Publish logger tracks and publishes during a switchback publish is sent by that publish. */
	public function testAGoalPublishedFromAPublishLoggerOnSwitchbackDataIsSent(): void {
		$context = $this->contextWithAnEarlierUnitExposure();
		$this->setLogger($context, self::publishCallback(static function (Context $context) {
			$context->trackWithProperties('from_logger');
			$context->publish();
		}));
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame([[[self::$UNIT_TYPE], ['exp_sb'], 0], [[self::$UNIT_TYPE, 'user_id'], [], 1]], $this->publishedBatches());
	}

	/** A nested read of the same slot hands its assignment to the outer read, which stamps the exposure with its time. */
	public function testANestedPeekOfTheSameSlotLeavesTheOuterReadsTimestamp(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, self::$CLUSTER_PATH, 'device_id', 'geo/city');
		$context = $this->createContext(self::dataOf($experiment), [self::$UNIT_TYPE => self::$UID, 'device_id' => 'd1']);
		$geo = new class($context, $this) implements \JsonSerializable {
			private Context $context;
			private $test;
			public int $reads = 0;
			public function __construct(Context $context, $test) { $this->context = $context; $this->test = $test; }
			public function jsonSerialize(): array {
				if (++$this->reads === 1) {
					// A later reading in the same period; the outer read keeps its own.
					$this->test->now += 5;
					$this->context->peekTreatment('exp_sb');
					$this->test->now -= 5;
				}
				return ['city' => 'store-42'];
			}
		};
		$this->setReadAttribute($context, 'geo', $geo);
		$this->now = self::$ANCHOR + 10;
		$context->getTreatment('exp_sb');
		self::assertSame(1, $geo->reads);
		self::assertSame([self::$ANCHOR + 10], array_column($this->publishExposures($context), 4));
	}

	/**
	 * A nested refresh and read inside the resolution install a slot of the new generation: the outer read, on its own
	 * snapshot, returns its own arm rather than taking that slot.
	 */
	public function testANestedSlotOfARefreshedGenerationIsNotReturnedToTheOuterRead(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, self::$CLUSTER_PATH, 'device_id', 'geo/city');
		$experiment->assignmentRules = self::ASSIGN_VARIANT_1;
		$refreshed = self::switchback(2, null, 9);
		$refreshed->assignmentRules = '{"rules":[{"type":"assign","environments":[],"variant":0}]}';
		$context = $this->createContext(self::dataOf($experiment), [self::$UNIT_TYPE => self::$UID, 'device_id' => 'd1']);
		$this->dataProvider->next = self::dataOf($refreshed);
		$geo = new class($context) implements \JsonSerializable {
			private Context $context;
			private bool $done = false;
			public $nested = null;
			public function __construct(Context $context) { $this->context = $context; }
			public function jsonSerialize(): array {
				if (!$this->done) {
					$this->done = true;
					$this->context->refresh();
					$this->nested = $this->context->peekTreatment('exp_sb');
				}
				return ['city' => 'store-42'];
			}
		};
		$this->setReadAttribute($context, 'geo', $geo);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(0, $geo->nested);
		self::assertSame(0, $context->peekTreatment('exp_sb'));
	}

	/**
	 * A handler that refreshes in the first switchback during a unit-only send, publishes and tracks again keeps the goal
	 * it tracked: the accepted send removes only what it submitted.
	 */
	public function testAGoalTrackedAfterAHandlerRefreshesInTheFirstSwitchbackIsKept(): void {
		$context = $this->createContext(self::dataOf(self::unitExperiment()));
		$context->trackWithProperties('first');
		$this->dataProvider->next = self::dataOf(self::unitExperiment(), self::switchback(2, null, 4, 'exp_sb'));
		$calls = 0;
		$this->eventHandler->prerun = static function () use ($context, &$calls) {
			if (++$calls === 1) {
				$context->refresh();
				$context->publish();
				$context->trackWithProperties('second');
			}
		};
		$context->publish();
		self::assertSame(1, $context->getPendingCount());
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertContains(['second'], array_map(static function ($event) {
			return array_column($event->goals ?? [], 'name');
		}, $this->eventHandler->submitted));
	}

	/** On switchback data without holdouts, a candidate a mid-walk refresh stripped of the key is skipped. */
	public function testAVariableWalkSkipsACandidateARefreshStrippedOfTheKey(): void {
		$variants = [(object) ['name' => 'A', 'config' => '{"k":1}'], (object) ['name' => 'B', 'config' => '{"k":2}']];
		$first = self::unitExperiment(2, 1, 'exp_a');
		$first->variants = $variants;
		$first->audience = self::STRICT_GOLD_AUDIENCE;
		$first->audienceStrict = true;
		$stripped = self::unitExperiment(2, 2, 'exp_b');
		$stripped->variants = $variants;
		$stripped->audience = self::STRICT_GOLD_AUDIENCE;
		$stripped->audienceStrict = true;
		$last = self::unitExperiment(2, 3, 'exp_c');
		$last->variants = $variants;
		$last->fullOnVariant = 1;
		$switchback = self::switchback(2, null, 4, 'exp_sb');
		$context = $this->createContext(self::dataOf($first, $stripped, $last, $switchback));
		$this->now = self::$ANCHOR + 10;
		$refreshed = clone $stripped;
		$refreshed->variants = [(object) ['name' => 'A', 'config' => '{"other":1}'], (object) ['name' => 'B', 'config' => '{"other":2}']];
		$this->dataProvider->next = self::dataOf($first, $refreshed, $last, $switchback);
		$this->setLogger($context, self::refreshOnFirstExposure());
		self::assertSame(2, $context->getVariableValue('k', -1));
		self::assertSame(['exp_c'], array_column($this->publishExposures($context), 1));
	}
	public function testAnAudienceGetterUpdateIsRevalidatedOnTheNextSwitchbackRead(): void {
		$experiment = self::switchback(2);
		$experiment->fullOnVariant = 1;
		$experiment->audience = '{"filter":[{"eq":[{"var":"tier/value"},{"value":"gold"}]}]}';
		$experiment->audienceStrict = true;
		$context = $this->createContext(self::dataOf($experiment));
		$tier = new class($context) {
			private $value;
			private Context $context;
			public function __construct(Context $context) { $this->context = $context; }
			public function __get($name) {
				$this->context->setAttributeValue('tier', (object) ['value' => 'gold']);
				return 'silver';
			}
		};
		$context->setAttributeValue('tier', $tier);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getTreatment('exp_sb'));
		self::assertSame([0, 1], array_column($this->publishExposures($context), 3));
	}

	public function testSameOutcomeAudienceValidationDoesNotAcknowledgeAGetterUpdate(): void {
		$experiment = self::switchback(2);
		$experiment->fullOnVariant = 1;
		$experiment->audience = '{"filter":[{"eq":[{"var":"tier/value"},{"value":"gold"}]}]}';
		$experiment->audienceStrict = true;
		$context = $this->createContext(self::dataOf($experiment));
		$context->setAttributeValue('tier', (object) ['value' => 'silver']);
		self::assertSame(0, $context->getTreatment('exp_sb'));
		$context->setAttributeValue('tier', new class($context) {
			private $value;
			private Context $context;
			public function __construct(Context $context) { $this->context = $context; }
			public function __get($name) {
				$this->context->setAttributeValue('tier', (object) ['value' => 'gold']);
				return 'silver';
			}
		});
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getTreatment('exp_sb'));
		self::assertSame([0, 1], array_column($this->publishExposures($context), 3));
	}

	/** Callback-created early-unit batches get the same bounded extra pass as callback-created goals. */
	public function testEarlyUnitBatchesCannotExtendThePublishDrainWithoutBound(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$context->getTreatment('exp_sb');
		$context->setUnit('extra0', 'u0');
		$calls = 0;
		$this->eventHandler->prerun = static function () use ($context, &$calls) {
			if (++$calls > 4) { throw new \RuntimeException('unbounded drain'); }
			$context->setOverride('exp_sb', $calls % 2);
			$context->getTreatment('exp_sb');
			$context->setUnit('extra' . $calls, 'u' . $calls);
		};
		$context->publish();
		self::assertSame(2, $calls);
		self::assertSame(1, $context->getPendingCount());
		self::assertFalse($context->isClosed());
		$this->eventHandler->prerun = null;
		$context->close();
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
		self::assertCount(3, $this->eventHandler->submitted);
	}

	/** No per-event identity map is needed when the accepted main batch still is the complete queue. */
	public function testUnchangedMainBatchAvoidsPerEventRemovalAllocation(): void {
		if (!function_exists('memory_reset_peak_usage')) {
			self::markTestSkipped('PHP 8.2 is required for the allocation counter');
		}
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		for ($i = 0; $i < 20000; ++$i) { $context->track('goal'); }
		$handler = new class extends \ABSmartly\SDK\Context\ContextEventHandler {
			public int $before = 0;
			public function __construct() {}
			public function publish(\ABSmartly\SDK\PublishEvent $event): void {
				gc_collect_cycles();
				memory_reset_peak_usage();
				$this->before = memory_get_usage();
			}
		};
		$property = new \ReflectionProperty(Context::class, 'eventHandler');
		$property->setValue($context, $handler);
		$context->publish();
		$allocated = memory_get_peak_usage() - $handler->before;
		self::assertLessThan(65536, $allocated, 'unchanged batches must not allocate a per-goal identity map');
		self::assertSame(0, $context->getPendingCount());
	}

	/** A fully held-out unit experiment covered by holdout 31, defining k with control value 0. */
	private static function heldOutKeyData(object $later, object ...$extra): ContextData {
		$held = self::unitExperiment(2, 1, 'exp_held');
		$held->variants = [(object) ['name' => 'A', 'config' => '{"k":0}'], (object) ['name' => 'B', 'config' => '{"k":1}']];
		$held->holdoutIds = [31];
		$holdout = self::unitExperiment(2, 31, 'holdout_all');
		$holdout->split = [1.0, 0.0];
		$holdout->variants = [(object) ['name' => 'A', 'config' => null], (object) ['name' => 'B', 'config' => null]];
		return new ContextData(array_merge([$held, $later], $extra), [$holdout]);
	}

	/** The first switchback arriving from a holdout Exposure logger keeps that logger's exception for the read. */
	public function testAHandOffToTheFirstSwitchbackKeepsThePendingLoggerError(): void {
		$later = self::unitExperiment(2, 2, 'exp_later');
		$later->variants = [(object) ['name' => 'A', 'config' => '{"k":99}'], (object) ['name' => 'B', 'config' => '{"k":99}']];
		$context = $this->createContext(self::heldOutKeyData($later));
		$this->now = self::$ANCHOR + 10;
		$this->dataProvider->next = self::heldOutKeyData($later, self::switchback(2, null, 40, 'exp_sb'));
		$this->setLogger($context, self::refreshOnFirstExposure(true));
		try {
			$context->getVariableValue('k', -1);
			self::fail('the logger error is re-thrown');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('logger failed', $exception->getMessage());
		}
		self::assertTrue(self::privateValue($context, 'hasSwitchbacks'));
	}

	/** The held-out candidate's control value chosen before the hand-off is still the read's fallback. */
	public function testAHandOffToTheFirstSwitchbackKeepsTheHeldOutFallback(): void {
		$later = self::unitExperiment(2, 2, 'exp_later');
		$later->unitType = 'user_id';
		$later->variants = [(object) ['name' => 'A', 'config' => '{"k":99}'], (object) ['name' => 'B', 'config' => '{"k":99}']];
		$context = $this->createContext(self::heldOutKeyData($later));
		$this->now = self::$ANCHOR + 10;
		$this->dataProvider->next = self::heldOutKeyData($later, self::switchback(2, null, 40, 'exp_sb'));
		$this->setLogger($context, self::refreshOnFirstExposure());
		self::assertSame(0, $context->getVariableValue('k', -1));
		self::assertTrue(self::privateValue($context, 'hasSwitchbacks'));
	}

}

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

		// In-range hour-aligned anchors, including 0 and the last hour before the bound, serve.
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

		// Setting a linked unit later makes the experiment apply.
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
		$context->setAttribute(self::$CLUSTER_PATH, self::$CLUSTER_KEY);
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

	/** Unit experiments keep this SDK's JS behaviour: a strict mismatch walked past on a variable key is recorded. */
	public function testAStrictMismatchedUnitExperimentWalkedPastIsStillExposed(): void {
		$first = self::unitExperiment(2, 1, 'exp_first');
		$first->audience = self::STRICT_GOLD_AUDIENCE;
		$first->audienceStrict = true;
		$second = self::unitExperiment(2, 2, 'exp_second');
		$second->variants = [(object) ['name' => 'A', 'config' => '{"sb.arm":10}'], (object) ['name' => 'B', 'config' => '{"sb.arm":11}']];
		$second->fullOnVariant = 1;
		$context = $this->createContext(self::dataOf($first, $second));
		self::assertSame(11, $context->getVariableValue('sb.arm', -1));
		self::assertSame(['exp_first', 'exp_second'], array_column($this->publishExposures($context), 1));
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
	 * arm with no config): otherwise its control periods would go unrecorded. The value falls through as before.
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
		self::assertSame(11, $context->getVariableValue('sb.arm', -1));
		self::assertSame(['exp_sb', 'exp_unit'], array_column($this->publishExposures($context), 1));
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
		$context->track('goal');
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
	 * reading. The collector, which resolves the cluster from the attributes at exposedAt, sees Faro: a known limit of
	 * the shared Java/Collector contract (SWITCHBACK.md), pinned here so a change to it is deliberate.
	 */
	public function testAnAttributeChangeAfterAPeekKeepsTheServedArmAsInJava(): void {
		$context = $this->createContext(self::dataOf(self::switchback(2)));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->peekTreatment('exp_sb'));
		$this->now = self::$ANCHOR + 20;
		$context->setAttribute(self::$CLUSTER_PATH, 'Faro');
		self::assertSame(1, SwitchbackAssigner::assign('Faro', 0, self::seed(), 2));
		$this->now = self::$ANCHOR + 30;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR + 30)], $this->publishExposures($context));
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
		$context->setAttribute('geo', $geo);
		$variant = $context->getTreatment('exp_sb');
		self::assertSame(SwitchbackAssigner::assign('Porto', 0, self::seed(), 3), $variant);
		self::assertSame([self::sbExposure($variant, self::$ANCHOR + self::$HOUR - 1)], $this->loggedExposures());
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

		// Retained {0, 2}: period 2 is still deduplicated, period 1 was evicted.
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

		// An unchanged identity keeps the epoch.
		$this->refreshTo($context, self::switchback(2));
		$context->getTreatment('exp_sb');
		self::assertSame(4, $context->getPendingCount());
	}

	/** A failed first load leaves no switchback behind: the failed context serves control and the default value. */
	public function testAFailedLoadAfterASwitchbackLeavesNoSwitchbackState(): void {
		$context = @$this->createContext(new ContextData([self::switchback(2), (object) ['id' => 9, 'name' => 'broken']]));
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
		// An experiment missing its required fields fails the install, which is rolled back.
		$this->dataProvider->next = new ContextData([self::switchback(2, 2 * self::$HOUR), (object) ['name' => 'broken']]);
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

		$context->setAttribute('country', 'US');
		$counts->rules = 0;
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(2, $counts->rules);
		self::assertSame(1, $context->peekTreatment('exp_sb'));
		self::assertSame(2, $counts->rules);

		// An unrelated attribute re-validates once, then stays current.
		$context->setAttribute('other', 1);
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
		$context->setAttribute('country', 'US');
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
		$context->setAttribute('other', 1);
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

		$context->setAttribute('x', 1);
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

		$context->setAttribute('tier', 'x');
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
		$context->setAttribute('country', 'US');
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
		// The publish fails and the cap drops period 0's exposure.
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
}

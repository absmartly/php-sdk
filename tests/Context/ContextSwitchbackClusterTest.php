<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Exception\InvalidArgumentException;
use ABSmartly\SDK\Switchback\SwitchbackAssigner;
use ABSmartly\SDK\Utils;
use ABSmartly\SDK\VariantAssigner;
use PHPUnit\Framework\TestCase;

/**
 * Ports java-sdk ContextSwitchbackClusterTest (cluster key selection inside Context) and ContextSwitchbackClusterRuleTest
 * (the Collector's ContextAPISwitchbackTest ClusterRule section, through the public Context API).
 */
class ContextSwitchbackClusterTest extends TestCase {
	use SwitchbackTestSupport;

	// 3 arms over 12 periods: two keys in different buckets are told apart by their schedules.
	private const ARMS = 3;

	private static function seed(): int {
		return (self::$SEED_HI << 32) | (self::$SEED_LO & 0xFFFFFFFF);
	}

	private static function attributeSwitchback(string $path): object {
		$experiment = self::switchback(self::ARMS);
		$experiment->switchbackClusters = self::clusters(self::$UNIT_TYPE, $path);
		return $experiment;
	}

	/** The Collector's draw: the raw cluster key, unhashed. */
	private static function expected(string $key, int $periodIndex): int {
		return SwitchbackAssigner::assign($key, $periodIndex, self::seed(), self::ARMS);
	}

	private static function expectedSchedule(string $key): array {
		$result = [];
		for ($i = 0; $i < 12; ++$i) {
			$result[] = self::expected($key, $i);
		}
		return $result;
	}

	private function peekSchedule(Context $context): array {
		$result = [];
		for ($i = 0; $i < 12; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			$result[] = $context->peekTreatment('exp_sb');
		}
		return $result;
	}

	private function attributeContext(object $experiment, ?array $units = null): Context {
		return $this->createContext(self::dataOf($experiment), $units, []);
	}

	private static function sbExposure(int $variant, int $exposedAt, bool $eligible = true, bool $audienceMismatch = false): array {
		return self::exposure(1, 'exp_sb', self::$UNIT_TYPE, $variant, $exposedAt, true, $eligible, false, false, false, $audienceMismatch);
	}

	/** The unit uid is never the cluster key: with no cluster value the unit is ineligible. */
	public function testUnitUidIsNeverTheClusterKey(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'));
		for ($i = 0; $i < 3; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(0, $context->getTreatment('exp_sb'));
		}
		self::assertSame(0, $context->getPendingCount());
	}

	/**
	 * A numeric UID serves and publishes under its string, as the same string UID does. setUnit() stores a uid as
	 * (string) $uid, as before this change, so only integral values are compared here.
	 */
	public function testNumericUnitUidClustersLikeItsCanonicalString(): void {
		foreach ([[123456789, '123456789'], [123456789.0, '123456789']] as [$numeric, $text]) {
			$published = [];
			$schedules = [];
			foreach ([$numeric, $text] as $uid) {
				$this->now = self::$ANCHOR;
				$context = $this->attributeContext(self::attributeSwitchback('city'), [self::$UNIT_TYPE => $uid]);
				$context->setAttribute('city', 'Lisboa');
				$context->getTreatment('exp_sb');
				$context->publish();
				$published[] = $this->eventHandler->submitted[0];
				$schedules[] = $this->peekSchedule($context);
			}
			self::assertSame(self::expectedSchedule('Lisboa'), $schedules[0]);
			self::assertSame($schedules[1], $schedules[0]);
			self::assertEquals($published[1]->units, $published[0]->units);
			self::assertEquals($published[1]->exposures, $published[0]->exposures);
			self::assertSame(Utils::hashUnit($text), $published[0]->units[0]->uid);
		}
	}

	/** Independent reference (Python MD5/base64url and murmur3) for a numeric UID and a numeric attribute value. */
	public function testNumericUnitUidMatchesTheIndependentReference(): void {
		$schedule = [0, 2, 1, 0, 1, 2, 0, 1, 2, 2, 1, 0];
		$this->now = self::$ANCHOR;
		$context = $this->attributeContext(self::attributeSwitchback('region'), [self::$UNIT_TYPE => 123456789]);
		$context->setAttribute('region', 123456789);
		$context->getTreatment('exp_sb');
		$context->publish();
		self::assertSame('JfnnlDI7RTiF9RgfG2JNCw', $this->eventHandler->submitted[0]->units[0]->uid);
		self::assertSame([self::sbExposure($schedule[0], self::$ANCHOR)], $this->publishExposures($context));
		self::assertSame($schedule, $this->peekSchedule($context));
	}

	/** Periods 0..11 of the raw key "Lisboa", from the independent murmur3 reference. */
	public function testAttributeSourceServesTheRawAttributeValue(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'));
		$context->setAttribute('city', 'Lisboa');
		self::assertSame([0, 2, 1, 2, 1, 0, 1, 0, 2, 0, 1, 2], $this->peekSchedule($context));
		self::assertSame(self::expectedSchedule('Lisboa'), $this->peekSchedule($context));
	}

	public function testUnitsSharingAnAttributeValueShareTheSchedule(): void {
		$lisboa = $this->attributeContext(self::attributeSwitchback('city'));
		$lisboa->setAttribute('city', 'Lisboa');
		$porto = $this->attributeContext(self::attributeSwitchback('city'));
		$porto->setAttribute('city', 'Porto');
		$otherLisboa = $this->attributeContext(self::attributeSwitchback('city'), [self::$UNIT_TYPE => 'another-store']);
		$otherLisboa->setAttribute('city', 'Lisboa');

		self::assertSame(self::expectedSchedule('Lisboa'), $this->peekSchedule($lisboa));
		self::assertSame(self::expectedSchedule('Lisboa'), $this->peekSchedule($otherLisboa));
		self::assertSame(self::expectedSchedule('Porto'), $this->peekSchedule($porto));
		self::assertNotSame(self::expectedSchedule('Lisboa'), self::expectedSchedule('Porto'));
	}

	public function testNestedAndArrayPathsAndIntegerValuesResolve(): void {
		$this->now = self::$ANCHOR;
		$nested = $this->attributeContext(self::attributeSwitchback('geo/city'));
		$nested->setAttribute('geo', ['city' => 'Porto']);
		self::assertSame(self::expectedSchedule('Porto'), $this->peekSchedule($nested));

		$this->now = self::$ANCHOR;
		$object = $this->attributeContext(self::attributeSwitchback('geo/city'));
		$object->setAttribute('geo', (object) ['city' => 'Porto']);
		self::assertSame(self::expectedSchedule('Porto'), $this->peekSchedule($object));

		$this->now = self::$ANCHOR;
		$array = $this->attributeContext(self::attributeSwitchback('items/1/city'));
		$array->setAttribute('items', [['city' => 'Lisbon'], ['city' => 'Faro']]);
		self::assertSame(self::expectedSchedule('Faro'), $this->peekSchedule($array));

		$this->now = self::$ANCHOR;
		$integer = $this->attributeContext(self::attributeSwitchback('region'));
		$integer->setAttribute('region', 42);
		self::assertSame(self::expectedSchedule('42'), $this->peekSchedule($integer));

		$this->now = self::$ANCHOR;
		$nestedInteger = $this->attributeContext(self::attributeSwitchback('geo/city'));
		$nestedInteger->setAttribute('geo', ['city' => -42]);
		self::assertSame(self::expectedSchedule('-42'), $this->peekSchedule($nestedInteger));

		$this->now = self::$ANCHOR + 10;
		$floating = $this->attributeContext(self::attributeSwitchback('city'));
		$floating->setAttribute('city', 2.0);
		self::assertSame(0, $floating->getTreatment('exp_sb'));
		self::assertSame(0, $floating->getPendingCount());

		// A nested float keeps its fraction through the JSON round trip, so 3.0 never keys a cluster either.
		$nestedFloat = $this->attributeContext(self::attributeSwitchback('geo/city'));
		$nestedFloat->setAttribute('geo', ['city' => 3.0]);
		self::assertSame(0, $nestedFloat->getTreatment('exp_sb'));
		self::assertSame(0, $nestedFloat->getPendingCount());
	}

	/** Attribute values that yield no cluster key serve variant 0, not eligible, record no exposure and never throw. */
	public function testValuesWithoutAClusterKeyAreIneligible(): void {
		foreach ([null, '', true, false, 1.5, 2.0, 3.0, -0.0, ['a' => 'b'], ['x'], [], (object) []] as $value) {
			$context = $this->attributeContext(self::attributeSwitchback('city'));
			$context->setAttribute('city', $value);
			$this->now = self::$ANCHOR + 10;
			self::assertSame(0, $context->getTreatment('exp_sb'), var_export($value, true));
			// Variant 0's own config, like any ineligible assignment.
			self::assertSame(0, $context->getVariableValue('sb.arm', -1), var_export($value, true));
			self::assertSame(0, $context->getPendingCount(), var_export($value, true));
		}

		$missing = $this->attributeContext(self::attributeSwitchback('geo/city'));
		$missing->setAttribute('geo', ['country' => 'PT']);
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $missing->getTreatment('exp_sb'));
		self::assertSame(0, $missing->getPendingCount());
	}

	/** A value with no JSON encoding has no cluster key. */
	public function testUnencodableAttributeValuesAreIneligible(): void {
		$cyclic = new \stdClass();
		$cyclic->self = $cyclic;
		$cyclic->city = 'Porto';
		foreach ([$cyclic, ['city' => 'Porto', 'x' => NAN], ['city' => "\xff"]] as $value) {
			$context = $this->attributeContext(self::attributeSwitchback('geo/city'));
			$context->setAttribute('geo', $value);
			self::assertSame(0, $context->getTreatment('exp_sb'));
			self::assertSame(0, $context->getPendingCount());
		}
	}

	/** Unservable configurations serve an ineligible control to linked units, never a unit draw, and record nothing. */
	public function testUnservableConfigurationsAreIneligible(): void {
		$experiments = [];
		foreach (['items/0', 'items/-1/city', '', 'geo.city', "'city", '"city'] as $path) {
			$experiments[] = self::attributeSwitchback($path);
		}
		$noPeriod = self::attributeSwitchback('city');
		$noPeriod->switchbackPeriodMs = null;
		$experiments[] = $noPeriod;
		$zeroPeriod = self::attributeSwitchback('city');
		$zeroPeriod->switchbackPeriodMs = 0;
		$experiments[] = $zeroPeriod;
		$negativePeriod = self::attributeSwitchback('city');
		$negativePeriod->switchbackPeriodMs = -self::$HOUR;
		$experiments[] = $negativePeriod;
		$fractionalPeriod = self::attributeSwitchback('city');
		$fractionalPeriod->switchbackPeriodMs = 3600000.5;
		$experiments[] = $fractionalPeriod;
		$stringStart = self::attributeSwitchback('city');
		$stringStart->switchbackStartAt = (string) self::$ANCHOR;
		$experiments[] = $stringStart;
		$oneArm = self::attributeSwitchback('city');
		$oneArm->split = [1.0];
		$experiments[] = $oneArm;
		foreach ([null, 7, ['city'], (object) ['p' => 'city']] as $path) {
			$pathless = self::attributeSwitchback('city');
			$pathless->switchbackClusters = (object) [self::$UNIT_TYPE => $path];
			$experiments[] = $pathless;
		}
		foreach ($experiments as $experiment) {
			$context = $this->attributeContext($experiment);
			$context->setAttribute('city', 'Lisboa');
			$context->setAttribute('items', [['city' => 'Lisbon']]);
			for ($i = 0; $i < 3; ++$i) {
				$this->now = self::$ANCHOR + $i * self::$HOUR;
				self::assertSame(0, $context->getTreatment('exp_sb'), json_encode($experiment));
			}
			self::assertSame(0, $context->getPendingCount(), json_encode($experiment));
		}
	}

	/** An integral float period (the JSON 3600000.0) is a whole number and serves. */
	public function testIntegralFloatScheduleFieldsServe(): void {
		$experiment = self::attributeSwitchback('city');
		$experiment->switchbackPeriodMs = 3600000.0;
		$experiment->switchbackStartAt = (float) self::$ANCHOR;
		$context = $this->attributeContext($experiment);
		$context->setAttribute('city', 'Lisboa');
		self::assertSame(self::expectedSchedule('Lisboa'), $this->peekSchedule($context));
	}

	/**
	 * A missing or empty cluster map takes the Collector's legacy path: a unit of the declared unitType only identifies an
	 * ineligible control (variant 0's config, no exposure); without one the experiment does not apply.
	 */
	public function testMissingOrEmptyClusterMapIsOnlyALegacyIneligibleControl(): void {
		foreach (['absent', null, (object) [], [], 'a string', 7, ['city', 'x']] as $clusters) {
			$experiment = self::attributeSwitchback('city');
			if ($clusters === 'absent') {
				unset($experiment->switchbackClusters);
			}
			else {
				$experiment->switchbackClusters = $clusters;
			}
			$context = $this->attributeContext($experiment);
			$context->setAttribute('city', 'Lisboa');
			self::assertSame(0, $context->getTreatment('exp_sb'), json_encode($clusters));
			self::assertSame(0, $context->getVariableValue('sb.arm', -1));
			self::assertSame(0, $context->getPendingCount());

			$other = $this->attributeContext($experiment, ['user_id' => 'u1']);
			$other->setAttribute('city', 'Lisboa');
			self::assertSame(0, $other->getTreatment('exp_sb'));
			self::assertSame(-1, $other->getVariableValue('sb.arm', -1));
			self::assertSame(0, $other->getPendingCount());
		}
	}

	/** The Collector reads the attribute set at or before the exposure instant, latest setAt first. */
	public function testLatestAttributeSetAtOrBeforeTheResolutionInstantIsUsed(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'));
		$this->now = self::$ANCHOR + 2 * self::$HOUR;
		$context->setAttribute('city', 'Porto');
		$this->now = self::$ANCHOR + 4 * self::$HOUR;
		$context->setAttribute('city', 'Lisboa');

		$this->now = self::$ANCHOR + self::$HOUR;
		self::assertSame(0, $context->peekTreatment('exp_sb'));
		$this->now = self::$ANCHOR + 3 * self::$HOUR;
		self::assertSame(self::expected('Porto', 3), $context->peekTreatment('exp_sb'));
		$this->now = self::$ANCHOR + 4 * self::$HOUR;
		self::assertSame(self::expected('Lisboa', 4), $context->peekTreatment('exp_sb'));
	}

	/** A later setAt wins over the insertion order; a tie goes to the one set last; names match trimmed. */
	public function testTheLatestSetAtWinsAndTiesGoToTheLastSet(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'));
		$this->now = self::$ANCHOR + 2 * self::$HOUR;
		$context->setAttribute('city', 'Porto');
		// A clock that went backwards: set later, but with an earlier setAt.
		$this->now = self::$ANCHOR + self::$HOUR;
		$context->setAttribute('city', 'Faro');
		$this->now = self::$ANCHOR + 3 * self::$HOUR;
		self::assertSame(self::expected('Porto', 3), $context->peekTreatment('exp_sb'));

		$tie = $this->attributeContext(self::attributeSwitchback('city'));
		$this->now = self::$ANCHOR;
		$tie->setAttribute('city', 'Porto');
		$tie->setAttribute(' city ', 'Lisboa');
		self::assertSame(self::expectedSchedule('Lisboa'), $this->peekSchedule($tie));

		// System attributes are never read.
		$system = $this->attributeContext(self::attributeSwitchback('application'));
		self::assertSame(0, $system->getTreatment('exp_sb'));
		self::assertSame(0, $system->getPendingCount());
	}

	/** Within a period the cluster key is fixed at first resolution; the next period reads the attribute again. */
	public function testSetAttributeMidPeriodDoesNotReassignWithinThePeriod(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'));
		$this->now = self::$ANCHOR + 10;
		$context->setAttribute('city', 'Lisboa');
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));

		$this->now = self::$ANCHOR + 20;
		$context->setAttribute('city', 'Porto');
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));
		self::assertSame(self::expected('Lisboa', 0), $context->peekTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());

		$differing = 0;
		for ($i = 1; $i < 12; ++$i) {
			$this->now = self::$ANCHOR + $i * self::$HOUR;
			self::assertSame(self::expected('Porto', $i), $context->getTreatment('exp_sb'));
			if (self::expected('Porto', $i) !== self::expected('Lisboa', $i)) {
				++$differing;
			}
		}
		self::assertNotSame(0, $differing);
		self::assertSame(12, $context->getPendingCount());
	}

	/** A unit first resolved without a key stays ineligible (and unrecorded) for the rest of that period. */
	public function testAttributeSetAfterAnIneligibleResolutionWaitsForTheNextPeriod(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'));
		$this->now = self::$ANCHOR + 10;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		$context->setAttribute('city', 'Lisboa');
		$this->now = self::$ANCHOR + 20;
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getPendingCount());

		$this->now = self::$ANCHOR + self::$HOUR;
		self::assertSame(self::expected('Lisboa', 1), $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(self::expected('Lisboa', 1), self::$ANCHOR + self::$HOUR)], $this->publishExposures($context));
	}

	/** A slot replaced through a traffic change keeps the period's first key. */
	public function testTrafficChangesWithinThePeriodKeepThePeriodsBucket(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'));
		$this->now = self::$ANCHOR + 10;
		$context->setAttribute('city', 'Lisboa');
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));

		$this->now = self::$ANCHOR + 20;
		$context->setAttribute('city', 'Porto');
		$noTraffic = self::attributeSwitchback('city');
		$noTraffic->trafficSplit = [1.0, 0.0];
		$this->refreshTo($context, $noTraffic);
		self::assertSame(0, $context->getTreatment('exp_sb'));

		$this->refreshTo($context, self::attributeSwitchback('city'));
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));
		self::assertSame(3, $context->getPendingCount());

		$this->now = self::$ANCHOR + self::$HOUR;
		self::assertSame(self::expected('Porto', 1), $context->getTreatment('exp_sb'));
	}

	/** Traffic restored later in the period serves the key read at first resolution. */
	public function testTrafficRestoredWithinThePeriodKeepsTheKey(): void {
		$noTraffic = self::attributeSwitchback('city');
		$noTraffic->trafficSplit = [1.0, 0.0];
		$context = $this->attributeContext($noTraffic);
		$this->now = self::$ANCHOR + 10;
		$context->setAttribute('city', 'Faro');
		self::assertSame(0, $context->getTreatment('exp_sb'));

		$this->now = self::$ANCHOR + 20;
		$context->setAttribute('city', 'Lisboa');
		$this->refreshTo($context, self::attributeSwitchback('city'));
		self::assertSame(1, self::expected('Faro', 0));
		self::assertSame(self::expected('Faro', 0), $context->getTreatment('exp_sb'));
		self::assertSame([self::sbExposure(0, self::$ANCHOR + 10, false), self::sbExposure(self::expected('Faro', 0), self::$ANCHOR + 20)],
			$this->publishExposures($context));
	}

	/** An audience change re-resolves the period slot (a new exposure) but keeps its key. */
	public function testAudienceRevalidationAfterSetAttributeKeepsThePeriodsBucket(): void {
		$experiment = self::attributeSwitchback('city');
		$experiment->audience = '{"filter":[{"eq":[{"var":"tier"},{"value":"gold"}]}]}';
		$context = $this->attributeContext($experiment);
		$this->now = self::$ANCHOR + 10;
		$context->setAttribute('city', 'Lisboa');
		$context->setAttribute('tier', 'gold');
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));

		$this->now = self::$ANCHOR + 20;
		$context->setAttribute('city', 'Porto');
		$context->setAttribute('tier', 'silver');
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));

		self::assertSame([self::sbExposure(self::expected('Lisboa', 0), self::$ANCHOR + 10),
			self::sbExposure(self::expected('Lisboa', 0), self::$ANCHOR + 20, true, true)], $this->publishExposures($context));
	}

	/** A slot first resolved without a key carries that missing key into its replacement. */
	public function testAudienceRevalidationAfterAnIneligibleResolutionKeepsTheMissingKey(): void {
		$experiment = self::attributeSwitchback('city');
		$experiment->audience = '{"filter":[{"eq":[{"var":"tier"},{"value":"gold"}]}]}';
		$context = $this->attributeContext($experiment);
		$this->now = self::$ANCHOR + 10;
		$context->setAttribute('tier', 'gold');
		self::assertSame(0, $context->getTreatment('exp_sb'));

		$this->now = self::$ANCHOR + 20;
		$context->setAttribute('city', 'Faro');
		$context->setAttribute('tier', 'silver');
		self::assertSame(1, self::expected('Faro', 0));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getPendingCount());

		$this->now = self::$ANCHOR + self::$HOUR;
		self::assertSame(self::expected('Faro', 1), $context->peekTreatment('exp_sb'));
		$this->now = self::$ANCHOR + 2 * self::$HOUR;
		self::assertSame(2, self::expected('Faro', 2));
		self::assertSame(self::expected('Faro', 2), $context->peekTreatment('exp_sb'));
	}

	public function testClusterPathChangesInvalidateThePeriod(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'));
		$this->now = self::$ANCHOR + 10;
		$context->setAttribute('city', 'Lisboa');
		$context->setAttribute('geo', ['city' => 'Porto']);
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());

		$this->refreshTo($context, self::attributeSwitchback('city'));
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));
		self::assertSame(1, $context->getPendingCount());

		$this->refreshTo($context, self::attributeSwitchback('geo/city'));
		self::assertSame(self::expected('Porto', 0), $context->getTreatment('exp_sb'));
		self::assertSame(2, $context->getPendingCount());

		$filtered = self::attributeSwitchback('geo/city');
		$filtered->switchbackClusterValues = ['Porto', 'Faro'];
		$this->refreshTo($context, $filtered);
		self::assertSame(self::expected('Porto', 0), $context->getTreatment('exp_sb'));
		self::assertSame(3, $context->getPendingCount());
	}

	/** Re-linking the experiment to another unit type re-keys the period and moves the exposure unit. */
	public function testLinkedUnitTypeChangeRekeysThePeriod(): void {
		$context = $this->attributeContext(self::attributeSwitchback('city'), [self::$UNIT_TYPE => self::$UID, 'user_id' => 'user-0002']);
		$this->now = self::$ANCHOR + 1;
		$context->setAttribute('city', 'Lisboa');
		$context->setAttribute('ucity', 'Faro');
		self::assertSame(self::expected('Lisboa', 0), $context->getTreatment('exp_sb'));

		$byUser = self::attributeSwitchback('ucity');
		$byUser->switchbackClusters = self::clusters('user_id', 'ucity');
		$this->refreshTo($context, $byUser);
		self::assertSame(self::expected('Faro', 0), $context->getTreatment('exp_sb'));
		self::assertSame([self::$UNIT_TYPE, 'user_id'], array_column($this->publishExposures($context), 2));
	}

	public function testCustomAssignmentAppliesOnlyToUnitsWithAClusterKey(): void {
		$keyed = $this->attributeContext(self::attributeSwitchback('city'));
		$keyed->setAttribute('city', 'Lisboa');
		$keyed->setCustomAssignment('exp_sb', 2);
		self::assertSame(2, $keyed->getTreatment('exp_sb'));

		$unkeyed = $this->attributeContext(self::attributeSwitchback('city'));
		$unkeyed->setCustomAssignment('exp_sb', 2);
		self::assertSame(0, $unkeyed->getTreatment('exp_sb'));
	}

	/**
	 * Inertness: a unit experiment ("unit" or absent) carrying every switchback field serves and publishes exactly what
	 * the same experiment without them does.
	 */
	public function testUnitExperimentsIgnoreClusterFields(): void {
		foreach (['unit', null, 'SWITCHBACK', 7] as $type) {
			$unit = self::attributeSwitchback('city');
			$unit->randomizationType = $type;
			$unit->switchbackClusters = self::clusters('user_id', 'city', self::$UNIT_TYPE, "'x");
			$unit->switchbackClusterValues = ['nothing'];
			$unit->switchbackPeriodMs = 0;
			// A split rule randomizes over unitType for a unit experiment, never a cluster unit.
			$unit->assignmentRules = '{"rules":[{"type":"split","environments":[],"percentages":"34/33/33"}]}';
			$reference = self::unitExperiment(self::ARMS, 1, 'exp_sb');
			$reference->assignmentRules = $unit->assignmentRules;

			$published = [];
			foreach ([$unit, $reference] as $experiment) {
				$context = $this->attributeContext($experiment, [self::$UNIT_TYPE => self::$UID, 'user_id' => 'u']);
				$context->setAttribute('city', 'Lisboa');
				$this->now = self::$ANCHOR - self::$HOUR;
				$first = $context->getTreatment('exp_sb');
				for ($i = 1; $i < 6; ++$i) {
					$this->now = self::$ANCHOR - self::$HOUR + $i * self::$HOUR;
					self::assertSame($first, $context->getTreatment('exp_sb'));
				}
				$published[] = $this->publishExposures($context);
			}
			self::assertCount(1, $published[0]);
			self::assertTrue($published[0][0][6]);
			self::assertTrue($published[0][0][11]);
			self::assertSame(self::$ANCHOR - self::$HOUR, $published[0][0][4]);
			self::assertSame($published[1], $published[0]);
		}
	}

	// ---- The Collector's ClusterRule section: switchback "sbc" (id 900, "34/33/33", 1h) linked to user_id at
	// "profile/store" and to session_id at "session_store", declared in unit type id order (session_id first).

	private const SESSION = 'session_id';
	private const USER = 'user_id';
	private const PERIOD = 5;

	private static function sbc(): object {
		$experiment = self::switchback(3, null, 900, 'sbc');
		$experiment->unitType = self::USER;
		$experiment->seedHi = 0x1234;
		$experiment->seedLo = 0x5678abcd;
		$experiment->split = [0.34, 0.33, 0.33];
		$experiment->trafficSeedHi = 7;
		$experiment->trafficSeedLo = 11;
		$experiment->variants = [];
		for ($i = 0; $i < 3; ++$i) {
			$experiment->variants[] = (object) ['name' => (string) $i, 'config' => '{"sbc.config":"test config ' . $i . '"}'];
		}
		$experiment->switchbackClusters = self::clusters(self::SESSION, 'session_store', self::USER, 'profile/store');
		return $experiment;
	}

	private static function scheduled(string $key): int {
		return SwitchbackAssigner::assign($key, self::PERIOD, (0x1234 << 32) | 0x5678abcd, 3);
	}

	/**
	 * The Collector's ExperimentAssignment for "sbc", or null when it lists none (not applicable).
	 *
	 * @return array{unit: ?string, eligible: bool, silent: bool, variant: int, config: ?string, exposures: array}|null
	 */
	private function resolveSbc(object $experiment, array $attributes, array $units, array $others = []): ?array {
		$this->now = self::$ANCHOR + self::PERIOD * self::$HOUR + 17 * 60000;
		$context = $this->createContext(new ContextData(array_merge([$experiment], $others)), $units, $attributes);
		foreach ($others as $other) {
			$context->getTreatment($other->name);
		}
		$variant = $context->getTreatment('sbc');
		$resolution = $this->resolution($context, 'sbc');
		self::assertNotNull($resolution);
		$config = $context->getVariableValue('sbc.config', null);
		$exposures = array_values(array_filter($this->loggedExposures(), static function (array $exposure) {
			return $exposure[1] === 'sbc';
		}));
		if (!$resolution['applies']) {
			self::assertSame(0, $variant);
			self::assertNull($config);
			self::assertSame([], $exposures);
			return null;
		}
		return ['unit' => $resolution['unitType'], 'eligible' => $resolution['eligible'], 'silent' => $resolution['silent'],
			'variant' => $variant, 'config' => $config, 'exposures' => $exposures];
	}

	/** An ineligible cluster is still an assignment, as control, under the first linked unit in declared order. */
	private function assertIneligible(object $experiment, array $attributes, string $expectedUnit, array $units): void {
		$assignment = $this->resolveSbc($experiment, $attributes, $units);
		self::assertNotNull($assignment, json_encode($attributes));
		self::assertSame($expectedUnit, $assignment['unit']);
		self::assertFalse($assignment['eligible']);
		self::assertTrue($assignment['silent']);
		self::assertSame(0, $assignment['variant']);
		self::assertSame('test config 0', $assignment['config']);
		self::assertSame([], $assignment['exposures'], json_encode($attributes));
	}

	public function testMissingOrUnreadableClusterConfigurationReturnsOnlyLegacyIneligibleControl(): void {
		$attributes = ['geo' => ['city' => 'Porto'], 'geo.city' => 'Faro', 'session_store' => 's-1'];
		foreach (['null-cluster', 'missing-links', "'geo", 'geo.city', "'geo.city'", '"geo.city"'] as $broken) {
			$experiment = self::sbc();
			if ($broken === 'null-cluster') {
				$experiment->switchbackClusters = null;
			}
			elseif ($broken === 'missing-links') {
				$experiment->switchbackClusters = (object) [];
			}
			else {
				$experiment->switchbackClusters = self::clusters(self::SESSION, $broken);
			}
			// The declared unit (user_id) only identifies an ineligible control response.
			$this->assertIneligible($experiment, $attributes, self::USER, [self::SESSION => 'x1', self::USER => 'u1']);
			// Without a unit of the declared type, there is no response at all.
			self::assertNull($this->resolveSbc($experiment, $attributes, [self::SESSION => 'x1']), $broken);
		}

		// A readable map whose only linked type (device_id) is absent from the request.
		$unknown = self::sbc();
		$unknown->switchbackClusters = self::clusters('device_id', 'session_store');
		self::assertNull($this->resolveSbc($unknown, ['session_store' => 's-1'], [self::SESSION => 'x1', self::USER => 'u1']));
	}

	public function testTrafficUsesServingUidNotExperimentUid(): void {
		$experiment = self::sbc();
		$experiment->trafficSplit = [0.6, 0.4];
		$ordinary = self::unitExperiment(2, 901, 'ordinary');
		$ordinary->unitType = self::USER;
		$witnesses = [null, null];
		for ($i = 0; $i < 1000 && ($witnesses[0] === null || $witnesses[1] === null); ++$i) {
			$uid = 'traffic-' . $i;
			$witnesses[(new VariantAssigner($uid))->assign([0.6, 0.4], 7, 11) === 0 ? 0 : 1] = $uid;
		}
		self::assertNotNull($witnesses[0]);
		self::assertNotNull($witnesses[1]);
		$key = 's-18';
		self::assertNotSame(0, self::scheduled($key));
		foreach ([false, true] as $servingEligible) {
			$assignment = $this->resolveSbc($experiment, ['session_store' => $key, 'profile' => ['store' => $key]],
				[self::SESSION => $witnesses[$servingEligible ? 1 : 0], self::USER => $witnesses[$servingEligible ? 0 : 1]], [$ordinary]);
			self::assertSame(self::SESSION, $assignment['unit']);
			self::assertSame($servingEligible, $assignment['eligible']);
			self::assertSame($servingEligible ? self::scheduled($key) : 0, $assignment['variant']);
			// A traffic miss on an eligible cluster is still recorded, under the serving unit.
			self::assertCount(1, $assignment['exposures']);
			self::assertSame(self::SESSION, $assignment['exposures'][0][2]);
			self::assertSame($servingEligible, $assignment['exposures'][0][6]);
		}
	}

	public function testOneMatchingUnitServesItsValue(): void {
		$assignment = $this->resolveSbc(self::sbc(), ['profile' => ['store' => 's-17']], [self::USER => 'u1']);
		self::assertTrue($assignment['eligible']);
		self::assertSame(self::USER, $assignment['unit']);
		self::assertSame(self::scheduled('s-17'), $assignment['variant']);
	}

	public function testStrictAudienceMismatchOnAnEligibleClusterIsListedAsControlAndRecorded(): void {
		$experiment = self::sbc();
		$experiment->audience = '{"filter":[{"eq":[{"var":"tier"},{"value":"gold"}]}]}';
		$experiment->audienceStrict = true;
		$assignment = $this->resolveSbc($experiment, ['session_store' => 's-17'], [self::SESSION => 'x1']);
		self::assertSame(self::SESSION, $assignment['unit']);
		self::assertTrue($assignment['eligible']);
		self::assertFalse($assignment['silent']);
		self::assertSame(0, $assignment['variant']);
		self::assertCount(1, $assignment['exposures']);
		self::assertTrue($assignment['exposures'][0][10]);
		self::assertFalse($assignment['exposures'][0][5]);
	}

	public function testTwoAgreeingUnitsServeTheValue(): void {
		$assignment = $this->resolveSbc(self::sbc(), ['profile' => ['store' => 's-17'], 'session_store' => 's-17'],
			[self::USER => 'u1', self::SESSION => 'x1']);
		self::assertTrue($assignment['eligible']);
		self::assertSame(self::scheduled('s-17'), $assignment['variant']);
	}

	public function testTwoDisagreeingUnitsAreIneligible(): void {
		// session_id is first in declared order whatever the order the units were set in.
		$this->assertIneligible(self::sbc(), ['profile' => ['store' => 's-17'], 'session_store' => 's-18'], self::SESSION,
			[self::USER => 'u1', self::SESSION => 'x1']);
	}

	/** A context holds one uid per unit type, so a second session_id is rejected and only one unit serves. */
	public function testTwoUnitsOfOneTypeWithOnePathAgreeOnTheSameValue(): void {
		$key = 's-17';
		self::assertNotSame(0, self::scheduled($key));
		$context = $this->createContext(self::dataOf(self::sbc()), [self::SESSION => 'x1'], ['session_store' => $key]);
		try {
			$context->setUnit(self::SESSION, 'x2');
			self::fail('expected InvalidArgumentException');
		}
		catch (InvalidArgumentException $exception) {
			self::assertSame('Unit "session_id" UID is already set', $exception->getMessage());
		}

		$assignment = $this->resolveSbc(self::sbc(), ['session_store' => $key], [self::SESSION => 'x1']);
		self::assertTrue($assignment['eligible']);
		self::assertSame(self::scheduled($key), $assignment['variant']);
	}

	public function testNoMatchingUnitIsNotApplicable(): void {
		self::assertNull($this->resolveSbc(self::sbc(), ['session_store' => 's-1'], ['device_id' => 'd1']));
		self::assertNull($this->resolveSbc(self::sbc(), ['session_store' => 's-1'], []));
	}

	public function testMatchingUnitWithoutAValueIsIneligible(): void {
		$this->assertIneligible(self::sbc(), ['other' => 's-1'], self::USER, [self::USER => 'u1']);
		$this->assertIneligible(self::sbc(), ['other' => 's-1'], self::SESSION, [self::USER => 'u1', self::SESSION => 'x1']);
	}

	public function testNonStringNonIntegerValuesAreIneligible(): void {
		foreach ([1.5, 3.0, true, ['a' => 1], ['a'], ''] as $value) {
			$this->assertIneligible(self::sbc(), ['session_store' => $value], self::SESSION, [self::SESSION => 'x1']);
		}
	}

	public function testWholeNumbersAreKeyedByTheirDecimalText(): void {
		self::assertNotSame(0, self::scheduled('17'));
		$assignment = $this->resolveSbc(self::sbc(), ['session_store' => 17], [self::SESSION => 'x1']);
		self::assertTrue($assignment['eligible']);
		self::assertSame(self::scheduled('17'), $assignment['variant']);
		// Equal to the string "17" from another unit type, so the two agree.
		self::assertTrue($this->resolveSbc(self::sbc(), ['session_store' => 17, 'profile' => ['store' => '17']],
			[self::SESSION => 'x1', self::USER => 'u1'])['eligible']);
		// "017" is not 17.
		$this->assertIneligible(self::sbc(), ['session_store' => 17, 'profile' => ['store' => '017']], self::SESSION,
			[self::SESSION => 'x1', self::USER => 'u1']);
	}

	public function testNestedAndArrayPathsAreRead(): void {
		$experiment = self::sbc();
		$experiment->switchbackClusters = self::clusters(self::SESSION, 'items/1/store');
		$assignment = $this->resolveSbc($experiment, ['items' => [['store' => 'a'], ['store' => 'b']]], [self::SESSION => 'x1']);
		self::assertSame(self::scheduled('b'), $assignment['variant']);
		self::assertNotSame(self::scheduled('a'), self::scheduled('b'));
	}

	public function testExposureUnitIsTheFirstMatchingUnitInDeclaredOrderWhateverTheUnitOrder(): void {
		$key = 's-18';
		self::assertNotSame(0, self::scheduled($key));
		foreach ([[self::USER => 'u1', self::SESSION => 'x1'], [self::SESSION => 'x1', self::USER => 'u1']] as $units) {
			$assignment = $this->resolveSbc(self::sbc(), ['profile' => ['store' => $key], 'session_store' => $key], $units);
			self::assertSame(self::SESSION, $assignment['unit']);
			self::assertSame(self::SESSION, $assignment['exposures'][0][2]);
			self::assertSame(self::scheduled($key), $assignment['variant']);
		}
	}

	public function testIneligibleClusterNeverPublishesAnExposure(): void {
		$context = $this->createContext(self::dataOf(self::sbc()), [self::USER => 'u1', self::SESSION => 'x1'],
			['session_store' => 'a', 'profile' => ['store' => 'b']]);
		self::assertSame(0, $context->getTreatment('sbc'));
		self::assertSame(0, $context->getPendingCount());
		$context->publish();
		self::assertSame([], $this->eventHandler->submitted);
		self::assertSame([], $this->loggedExposures());
	}

	public function testAUnitWithoutAValueDoesNotServeEvenIfFirstInDeclaredOrder(): void {
		$key = 's-17';
		self::assertNotSame(0, self::scheduled($key));
		$assignment = $this->resolveSbc(self::sbc(), ['profile' => ['store' => $key]], [self::SESSION => 'x1', self::USER => 'u1']);
		self::assertSame(self::USER, $assignment['unit']);
		self::assertSame(self::USER, $assignment['exposures'][0][2]);
		self::assertSame(self::scheduled($key), $assignment['variant']);
	}

	public function testValueNotInTheFilterIsIneligible(): void {
		$experiment = self::sbc();
		$experiment->switchbackClusterValues = ['s-1', 's-2'];
		$this->assertIneligible($experiment, ['session_store' => 's-3'], self::SESSION, [self::SESSION => 'x1']);
		$allowed = $this->resolveSbc($experiment, ['session_store' => 's-2'], [self::SESSION => 'x1']);
		self::assertTrue($allowed['eligible']);
		self::assertSame(self::scheduled('s-2'), $allowed['variant']);

		// A whole number in the allow-list matches the integer's text; anything else in it never matches.
		$numeric = self::sbc();
		$numeric->switchbackClusterValues = [17, 3.0, null, true];
		self::assertTrue($this->resolveSbc($numeric, ['session_store' => '17'], [self::SESSION => 'x1'])['eligible']);
		$this->assertIneligible($numeric, ['session_store' => 3], self::SESSION, [self::SESSION => 'x1']);
		$this->assertIneligible($numeric, ['session_store' => '1'], self::SESSION, [self::SESSION => 'x1']);
	}

	public function testNullOrEmptyFilterAssignsEveryValue(): void {
		foreach (['absent', null, []] as $values) {
			$experiment = self::sbc();
			if ($values !== 'absent') {
				$experiment->switchbackClusterValues = $values;
			}
			for ($i = 0; $i < 5; ++$i) {
				$assignment = $this->resolveSbc($experiment, ['session_store' => 's-' . $i], [self::SESSION => 'x1']);
				self::assertTrue($assignment['eligible']);
				self::assertSame(self::scheduled('s-' . $i), $assignment['variant']);
			}
		}
		// Any other shape is no allow-list either.
		$experiment = self::sbc();
		$experiment->switchbackClusterValues = 's-1';
		self::assertTrue($this->resolveSbc($experiment, ['session_store' => 's-2'], [self::SESSION => 'x1'])['eligible']);
	}

	public function testValidClusterWithoutLinkedUnitDoesNotFallBackToExperimentUnit(): void {
		$experiment = self::sbc();
		$experiment->switchbackClusters = self::clusters(self::SESSION, 'session_store');
		$key = 's-17';
		self::assertNotSame(0, self::scheduled($key));

		self::assertNull($this->resolveSbc($experiment, ['session_store' => $key, 'profile' => ['store' => $key]], [self::USER => 'u1']));
		$linked = $this->resolveSbc($experiment, ['session_store' => $key], [self::USER => 'u1', self::SESSION => 'x1']);
		self::assertSame(self::SESSION, $linked['unit']);
		self::assertSame(self::scheduled($key), $linked['variant']);
	}

	/**
	 * The Collector's wire-rule oracle SdkSwitchbackRule transcribed (java-sdk ContextSwitchbackClusterRuleTest): the
	 * period from the anchor, traffic on the serving uid's hash, then the draw over the cluster key. A null key has no
	 * draw. java-sdk re-ceils the anchor to the hour in the oracle; the grid only uses aligned anchors.
	 *
	 * @return int[] [variant, eligible ? 1 : 0]
	 */
	private static function oracle(object $experiment, string $uid, ?string $clusterKey, int $now): array {
		$startAt = $experiment->switchbackStartAt;
		if ($clusterKey === null || $now < $startAt) {
			return [0, 0];
		}
		$period = intdiv($now - $startAt, $experiment->switchbackPeriodMs);
		if ((new VariantAssigner($uid))->assign($experiment->trafficSplit, $experiment->trafficSeedHi, $experiment->trafficSeedLo) !== 1) {
			return [0, 0];
		}
		return [SwitchbackAssigner::assign($clusterKey, $period, ($experiment->seedHi << 32) | ($experiment->seedLo & 0xFFFFFFFF), count($experiment->split)), 1];
	}

	public function testContextMatchesTheSdkSwitchbackWireRuleParityGrid(): void {
		$eligible = 0;
		$ineligible = 0;
		$trafficMisses = 0;
		$hour = self::$HOUR;
		foreach (['SINGLE', 'DISAGREEING_SECOND_SOURCE', 'MISSING_VALUE', 'FILTERED_VALUE'] as $cluster) {
			foreach ([self::$ANCHOR, self::$ANCHOR + 7 * $hour] as $anchor) {
				foreach ([$hour, 2 * $hour, 24 * $hour] as $periodMs) {
					for ($u = 0; $u < 4; ++$u) {
						$uid = 'uid-' . $u;
						foreach (['s-1', '42', 'Lisboa'] as $key) {
							foreach ([$anchor - $hour, $anchor - 1, $anchor, $anchor + $periodMs - 1, $anchor + $periodMs,
								$anchor + 3 * $periodMs + 5, $anchor + 11 * $periodMs] as $now) {
								$experiment = self::sbc();
								$experiment->trafficSplit = [0.3, 0.7];
								$experiment->switchbackStartAt = $anchor;
								$experiment->switchbackPeriodMs = $periodMs;
								$experiment->switchbackClusters = self::clusters(self::SESSION, 'session_store');
								$attributes = ['session_store' => $key];
								$units = [self::SESSION => $uid];
								if ($cluster === 'DISAGREEING_SECOND_SOURCE') {
									$experiment->switchbackClusters = self::clusters(self::SESSION, 'session_store', self::USER, 'profile/store');
									$attributes['profile'] = ['store' => $key . '-other'];
									$units[self::USER] = 'u-' . $uid;
								}
								elseif ($cluster === 'MISSING_VALUE') {
									$attributes = ['other' => $key];
								}
								elseif ($cluster === 'FILTERED_VALUE') {
									$experiment->switchbackClusterValues = [$key . '-allowed'];
								}

								$this->now = $now;
								$oracleKey = $cluster === 'SINGLE' ? $key : null;
								[$variant, $isEligible] = self::oracle($experiment, $uid, $oracleKey, $now);
								$context = $this->createContext(self::dataOf($experiment), $units, $attributes);
								$message = "$cluster/$anchor/$periodMs/$uid/$key/$now";
								self::assertSame($variant, $context->getTreatment('sbc'), $message);
								self::assertSame($isEligible === 1, $this->resolution($context, 'sbc')['eligible'], $message);
								// Before the anchor nothing is recorded; from it, a keyed cluster records one exposure.
								$exposures = $this->loggedExposures();
								$expectedExposures = $oracleKey !== null && $now >= $anchor ? 1 : 0;
								self::assertCount($expectedExposures, $exposures, $message);
								if ($expectedExposures === 1) {
									self::assertSame($isEligible === 1, $exposures[0][6], $message);
								}
								if ($cluster === 'SINGLE') {
									if ($isEligible === 1) {
										++$eligible;
									}
									else {
										++$ineligible;
										if ($now >= $anchor) {
											++$trafficMisses;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		self::assertGreaterThan(50, $eligible);
		self::assertGreaterThan(50, $ineligible);
		self::assertGreaterThan(0, $trafficMisses);
	}
}

<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Switchback\SwitchbackAssigner;
use ABSmartly\SDK\VariantAssigner;
use PHPUnit\Framework\TestCase;

/**
 * Ports java-sdk SwitchbackServerParityTest: the Collector's R33 serving rules (ContextAPI.switchbackServing and
 * switchbackVariant) replayed through the public Context API. Each case states, independently of SDK code, the serving
 * unit type and the raw cluster key the Collector picks, or that it records nothing. A PHP context holds one uid per
 * unit type, so the Collector's "two units of the same type" case has no counterpart.
 */
class SwitchbackServerParityTest extends TestCase {
	use SwitchbackTestSupport;

	private const PERIODS = 4;
	private const ARMS = 3;
	private const SEED = 1234567890123;
	private const STORE = 'store_id';
	private const DEVICE = 'device_id';
	private const USER = 'user_id';

	private static function sbExperiment(object $clusters, ?array $values = null): object {
		$experiment = self::switchback(self::ARMS);
		// R33 payloads may still carry unitType; it is never used for switchback assignment.
		$experiment->unitType = self::USER;
		$experiment->seedHi = self::SEED >> 32;
		$experiment->seedLo = self::SEED & 0xFFFFFFFF;
		$experiment->switchbackClusters = $clusters;
		$experiment->switchbackClusterValues = $values;
		return $experiment;
	}

	private static function twoSources(): object {
		return self::clusters(self::STORE, 'store_city', self::DEVICE, 'geo/city');
	}

	private static function twoUnits(): array {
		return [self::STORE => 's1', self::DEVICE => 'd1'];
	}

	/** Serves PERIODS periods with getTreatment, one call per period; returns [variants, exposures]. */
	private function serve(object $experiment, array $units, ?array $attributes): array {
		$this->now = self::$ANCHOR;
		$context = $this->createContext(self::dataOf($experiment), $units, $attributes ?? []);
		$variants = [];
		for ($p = 0; $p < self::PERIODS; ++$p) {
			$this->now = self::$ANCHOR + $p * self::$HOUR + 1;
			$variants[] = $context->getTreatment('exp_sb');
		}
		return [$variants, $this->loggedExposures()];
	}

	private static function collectorVariant(string $rawKey, int $periodIndex): int {
		return SwitchbackAssigner::assign($rawKey, $periodIndex, self::SEED, self::ARMS);
	}

	/** One eligible exposure per period under a unit of $servingType, variant from the raw key. */
	private static function assertEligible(array $served, string $servingType, string $key, string $message): void {
		[$variants, $exposures] = $served;
		self::assertCount(self::PERIODS, $exposures, $message);
		for ($p = 0; $p < self::PERIODS; ++$p) {
			$expected = self::collectorVariant($key, $p);
			self::assertSame($expected, $variants[$p], "$message period $p");
			self::assertSame(self::exposure(1, 'exp_sb', $servingType, $expected, self::$ANCHOR + $p * self::$HOUR + 1), $exposures[$p], $message);
		}
	}

	/** The Collector records nothing: control in every period, no exposure. */
	private static function assertSilentControl(array $served, string $message): void {
		[$variants, $exposures] = $served;
		self::assertSame(array_fill(0, self::PERIODS, 0), $variants, $message);
		self::assertSame([], $exposures, $message);
	}

	/** Independent murmur3 reference: periods 0..3 of the raw keys under SEED with 3 arms. */
	public function testRawKeySchedulesMatchTheIndependentReference(): void {
		self::assertEligible($this->serve(self::sbExperiment(self::clusters(self::STORE, 'city')), [self::STORE => 's1'],
			['city' => 'Lisboa']), self::STORE, 'Lisboa', 'Lisboa');
		for ($p = 0; $p < self::PERIODS; ++$p) {
			self::assertSame([0, 1, 2, 2][$p], self::collectorVariant('Lisboa', $p));
			self::assertSame([2, 1, 0, 1][$p], self::collectorVariant('Porto', $p));
			self::assertSame([0, 2, 1, 2][$p], self::collectorVariant('42', $p));
		}
	}

	public function testAgreementServesTheFirstSourceUnit(): void {
		self::assertEligible($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(),
			['store_city' => 'Lisboa', 'geo' => ['city' => 'Lisboa']]), self::STORE, 'Lisboa', 'agreement');
	}

	public function testDisagreementIsIneligibleAndRecordsNothing(): void {
		self::assertSilentControl($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(),
			['store_city' => 'Lisboa', 'geo' => ['city' => 'Porto']]), 'disagreement');
		// Ineligible, not absent: the experiment applies, so a variable read gets variant 0's value, not the default.
		$experiment = self::sbExperiment(self::twoSources());
		$experiment->variants[0]->config = '{"sb.arm":0}';
		$context = $this->createContext(self::dataOf($experiment), self::twoUnits(), ['store_city' => 'Lisboa', 'geo' => ['city' => 'Porto']]);
		self::assertSame(0, $context->getVariableValue('sb.arm', -1));
		self::assertSame(['unitType' => self::STORE, 'assigned' => true, 'eligible' => false, 'silent' => true, 'applies' => true],
			$this->resolution($context, 'exp_sb'));
	}

	public function testMissingValueIsSkippedAndTheNextValueServes(): void {
		self::assertEligible($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(), ['geo' => ['city' => 'Porto']]),
			self::DEVICE, 'Porto', 'first source missing');
		self::assertEligible($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(), ['store_city' => 'Porto']),
			self::STORE, 'Porto', 'second source missing');
		self::assertSilentControl($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(), ['other' => 'x']), 'no value at all');
		self::assertSilentControl($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(), null), 'no attributes');
	}

	public function testOnlyLinkedUnitsPresentInTheContextTakePart(): void {
		self::assertEligible($this->serve(self::sbExperiment(self::twoSources()), [self::DEVICE => 'd1'],
			['store_city' => 'Lisboa', 'geo' => ['city' => 'Porto']]), self::DEVICE, 'Porto', 'store unit absent');
	}

	public function testFilteredValueIsIneligibleAndAllowedValueServes(): void {
		$lisboa = ['store_city' => 'Lisboa'];
		self::assertSilentControl($this->serve(self::sbExperiment(self::twoSources(), ['Porto', 'Faro']), self::twoUnits(), $lisboa), 'filtered');
		self::assertEligible($this->serve(self::sbExperiment(self::twoSources(), ['Porto', 'Lisboa']), self::twoUnits(), $lisboa),
			self::STORE, 'Lisboa', 'allowed');
		self::assertEligible($this->serve(self::sbExperiment(self::twoSources(), null), self::twoUnits(), $lisboa), self::STORE, 'Lisboa', 'null filter');
		self::assertEligible($this->serve(self::sbExperiment(self::twoSources(), []), self::twoUnits(), $lisboa), self::STORE, 'Lisboa', 'empty filter');
		// The filter compares the normalised text of numeric values.
		self::assertEligible($this->serve(self::sbExperiment(self::twoSources(), ['42']), self::twoUnits(), ['store_city' => 42]),
			self::STORE, '42', 'numeric filter');
		// A filtered first value is not replaced by a later allowed one: they disagree.
		self::assertSilentControl($this->serve(self::sbExperiment(self::twoSources(), ['Porto']), self::twoUnits(),
			['store_city' => 'Lisboa', 'geo' => ['city' => 'Porto']]), 'filtered first value');
	}

	/** The declared order decides, not the order units were set or named. */
	public function testLowerUnitTypeIdSourceServesFirst(): void {
		$deviceFirst = self::clusters(self::DEVICE, 'geo/city', self::STORE, 'store_city');
		$agreeing = ['store_city' => 'Lisboa', 'geo' => ['city' => 'Lisboa']];
		foreach ([[self::STORE => 's1', self::DEVICE => 'd1'], [self::DEVICE => 'd1', self::STORE => 's1']] as $units) {
			self::assertEligible($this->serve(self::sbExperiment($deviceFirst), $units, $agreeing), self::DEVICE, 'Lisboa', 'device declared first');
			self::assertEligible($this->serve(self::sbExperiment(self::twoSources()), $units, $agreeing), self::STORE, 'Lisboa', 'store declared first');
		}
	}

	/** Booleans, floats, objects, arrays, null and "" yield no value: skipped, never a disagreement. */
	public function testNonKeyValuesAreIgnored(): void {
		foreach ([true, false, 1.5, 2.0, ['a' => 'b'], ['x'], [], null, ''] as $value) {
			$message = 'ignored ' . var_export($value, true);
			self::assertEligible($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(),
				['store_city' => $value, 'geo' => ['city' => 'Porto']]), self::DEVICE, 'Porto', $message);
			self::assertSilentControl($this->serve(self::sbExperiment(self::clusters(self::STORE, 'store_city')), [self::STORE => 's1'],
				['store_city' => $value]), "$message alone");
		}
	}

	/**
	 * Integral numbers are keyed by their base-10 text, so they agree with that text. PHP has no BigInteger: an integer
	 * past 64 bits arrives as a float or a string, and only the string keys a cluster.
	 */
	public function testNumericValuesAreKeyedAsText(): void {
		foreach ([42, '42'] as $store) {
			foreach ([42, '42'] as $device) {
				self::assertEligible($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(),
					['store_city' => $store, 'geo' => ['city' => $device]]), self::STORE, '42', gettype($store) . '/' . gettype($device));
			}
		}
		self::assertEligible($this->serve(self::sbExperiment(self::clusters(self::STORE, 'n')), [self::STORE => 's1'],
			['n' => '123456789012345678901234567890']), self::STORE, '123456789012345678901234567890', 'big integer text');
		self::assertEligible($this->serve(self::sbExperiment(self::clusters(self::STORE, 'n')), [self::STORE => 's1'], ['n' => -7]),
			self::STORE, '-7', 'negative');
		self::assertEligible($this->serve(self::sbExperiment(self::clusters(self::STORE, 'n')), [self::STORE => 's1'], ['n' => PHP_INT_MAX]),
			self::STORE, '9223372036854775807', 'int64 max');
		self::assertSilentControl($this->serve(self::sbExperiment(self::clusters(self::STORE, 'n')), [self::STORE => 's1'],
			['n' => 1.2345678901234568E+29]), 'big integer float');
		self::assertSilentControl($this->serve(self::sbExperiment(self::twoSources()), self::twoUnits(),
			['store_city' => 42, 'geo' => ['city' => '042']]), '42 vs 042 disagree');
	}

	/** A malformed path makes the whole experiment unservable, even when another source is valid. */
	public function testMalformedPathsAreUnservable(): void {
		$attributes = ['store_city' => 'Lisboa', 'geo' => ['city' => 'Porto'], 'geo.city' => 'Faro'];
		foreach (["'geo/city", '"geo', "geo/'city", 'geo.city', 'geo/a.b', "'geo.city'", '"geo.city"'] as $path) {
			self::assertSilentControl($this->serve(self::sbExperiment(self::clusters(self::STORE, 'store_city', self::DEVICE, $path)),
				self::twoUnits(), $attributes), $path);
			self::assertSilentControl($this->serve(self::sbExperiment(self::clusters(self::DEVICE, $path)), self::twoUnits(), $attributes), "$path alone");
		}
	}

	/** Absent, not an ineligible control: the experiment does not apply, so a variable read gets the caller's default. */
	public function testNoLinkedUnitIsAbsent(): void {
		self::assertSilentControl($this->serve(self::sbExperiment(self::twoSources()), [self::USER => 'u1'], ['store_city' => 'Lisboa']), 'no linked unit');

		$experiment = self::sbExperiment(self::twoSources());
		$experiment->variants[0]->config = '{"sb.arm":0}';
		$context = $this->createContext(self::dataOf($experiment), [self::USER => 'u1'], ['store_city' => 'Lisboa']);
		self::assertSame(-1, $context->getVariableValue('sb.arm', -1));
		self::assertFalse($this->resolution($context, 'exp_sb')['applies']);
		// An ineligible cluster (a linked unit with no value), by contrast, serves variant 0's value.
		$ineligible = $this->createContext(self::dataOf($experiment), [self::STORE => 's1'], []);
		self::assertSame(0, $ineligible->getVariableValue('sb.arm', -1));
		self::assertTrue($this->resolution($ineligible, 'exp_sb')['applies']);
	}

	/** Traffic eligibility is sticky on the serving unit's hash, equal to a unit experiment's on that unit type. */
	public function testTrafficFollowsTheServingUnitHash(): void {
		$eligible = 0;
		$ineligible = 0;
		for ($u = 0; $u < 24; ++$u) {
			$switchback = self::sbExperiment(self::twoSources());
			$switchback->trafficSplit = [0.5, 0.5];
			$switchback->trafficSeedHi = 3;
			$switchback->trafficSeedLo = 4;
			$twin = self::unitExperiment(self::ARMS, 2, 'exp_unit');
			$twin->unitType = self::DEVICE;
			$twin->trafficSplit = [0.5, 0.5];
			$twin->trafficSeedHi = 3;
			$twin->trafficSeedLo = 4;

			$this->now = self::$ANCHOR;
			$context = $this->createContext(self::dataOf($switchback, $twin), [self::STORE => 's1', self::DEVICE => 'device-' . $u],
				['geo' => ['city' => 'Porto']]);
			$context->getTreatment('exp_unit');
			$twinEligible = $this->loggedExposures()[0][6];
			for ($p = 0; $p < self::PERIODS; ++$p) {
				$this->now = self::$ANCHOR + $p * self::$HOUR;
				$variant = $context->getTreatment('exp_sb');
				$exposure = $this->loggedExposures()[$p + 1];
				self::assertSame(self::DEVICE, $exposure[2]);
				self::assertSame($twinEligible, $exposure[6]);
				self::assertSame($twinEligible ? self::collectorVariant('Porto', $p) : 0, $variant);
			}
			$twinEligible ? ++$eligible : ++$ineligible;
		}
		self::assertGreaterThan(0, $eligible);
		self::assertGreaterThan(0, $ineligible);
	}

	/** Each attribute-path-cases.json case, served as a cluster path over the fixture's attributes. */
	public function testAttributePathCasesMatchTheCollector(): void {
		$cases = json_decode(file_get_contents(__DIR__ . '/../Fixtures/switchback/attribute-path-cases.json'), true, 512, JSON_THROW_ON_ERROR);
		$eligibleCases = 0;
		foreach ($cases['cases'] as $c) {
			$served = $this->serve(self::sbExperiment(self::clusters(self::STORE, $c['path'])), [self::STORE => 's1'], $cases['attributes']);
			if (!$c['supported'] || $c['value'] === null) {
				self::assertSilentControl($served, json_encode($c));
				continue;
			}
			self::assertEligible($served, self::STORE, $c['value'], json_encode($c));
			++$eligibleCases;
		}
		self::assertSame(3, $eligibleCases);
	}

	/** The latest value of a repeated attribute name wins; names are matched trimmed. */
	public function testRepeatedAttributeNamesResolveToTheLatest(): void {
		self::assertEligible($this->serve(self::sbExperiment(self::clusters(self::STORE, 'cluster')), [self::STORE => 's1'],
			[' cluster ' => 'Porto']), self::STORE, 'Porto', 'trimmed name');

		$this->now = self::$ANCHOR;
		$context = $this->createContext(self::dataOf(self::sbExperiment(self::clusters(self::STORE, 'cluster'))), [self::STORE => 's1'], []);
		$context->setAttributeValue('cluster', 'Lisboa');
		$context->setAttributeValue('cluster', 'Porto');
		$this->now = self::$ANCHOR + 1;
		self::assertSame(self::collectorVariant('Porto', 0), $context->getTreatment('exp_sb'));
		self::assertTrue($this->loggedExposures()[0][6]);
	}

	/** The shared fixtures are the Collector's blobs at absmartly/abs 9bd2b5ca35 (java-sdk pins the same sha256). */
	public function testSharedFixturesMatchCollectorBlobs(): void {
		$dir = __DIR__ . '/../Fixtures/switchback/';
		self::assertSame('66dda4bafddaa119b030beafff8dc56f5750a8387919ffbe0842c93525ef32b6', hash_file('sha256', $dir . 'golden-vectors.json'));
		self::assertSame('2ee7eb0053997b48b8992980ffe11fe3accb2db01471aa9a617420e1609c6518', hash_file('sha256', $dir . 'attribute-path-cases.json'));
		self::assertFileDoesNotExist($dir . 'bucket-golden-vectors.json');
	}
}

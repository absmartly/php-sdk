<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use ABSmartly\SDK\Tests\Mocks\HoldoutDataProvider;
use ABSmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy;

/**
 * Shared by the switchback tests (java-sdk ContextSwitchbackTest and SwitchbackTestSupport): a switchback fixture, a
 * fixed clock through Context's private seam, and the published exposures.
 */
trait SwitchbackTestSupport {
	protected static string $UNIT_TYPE = 'store_id';
	protected static string $UID = 'e791e240fcd3df7d238cfc285f475e8152fcc0ec';
	// Utils::hashUnit(UID): the MD5/base64url string the Collector's UnitHasher also produces.
	protected static string $UID_HASH = 'pAE3a1i5Drs5mKRNq56adA';

	protected static int $HOUR = 3600000;
	// 2026-01-01T00:00:00Z, hour aligned.
	protected static int $ANCHOR = 1767225600000;

	protected static int $SEED_HI = -5;
	protected static int $SEED_LO = -7;

	protected static string $CLUSTER_PATH = 'store_key';
	protected static string $CLUSTER_KEY = 'store-42';
	// Periods 0..11 of the raw cluster key "store-42" under seed (SEED_HI, SEED_LO), from java-sdk ContextSwitchbackTest
	// (an independent Python reference that reproduces every golden vector).
	protected static array $EXPECTED_2_ARMS = [0, 1, 0, 1, 1, 0, 1, 0, 1, 0, 0, 1];
	protected static array $EXPECTED_3_ARMS = [0, 2, 1, 2, 1, 0, 1, 2, 0, 1, 0, 2];
	protected static array $EXPECTED_10_ARMS = [8, 3, 7, 4, 2, 0, 5, 1, 9, 6, 3, 7];
	// The same reference fed UID_HASH and the raw UID: what keying by the unit instead of the cluster value would serve.
	protected static array $UID_HASH_3_ARMS = [1, 2, 0, 2, 1, 0, 2, 0, 1, 1, 2, 0];
	protected static array $UID_3_ARMS = [2, 0, 1, 0, 1, 2, 1, 2, 0, 0, 1, 2];

	public int $now = 0;
	/** When set, the next clock read returns this value once, then reads fall back to $now. */
	protected ?int $nextRead = null;
	protected ContextEventHandlerMock $eventHandler;
	protected HoldoutDataProvider $dataProvider;
	protected MockContextEventLoggerProxy $logger;

	protected function setUp(): void {
		$this->now = self::$ANCHOR;
		self::setClock(function (): int {
			if ($this->nextRead !== null) {
				$value = $this->nextRead;
				$this->nextRead = null;
				return $value;
			}
			return $this->now;
		});
	}

	protected function tearDown(): void {
		self::setClock(null);
	}

	private static function setClock(?\Closure $clock): void {
		$property = new \ReflectionProperty(Context::class, 'clock');
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		$property->setValue(null, $clock);
	}

	protected static function switchback(int $armCount = 2, ?int $periodMs = null, int $id = 1, string $name = 'exp_sb'): object {
		$split = [];
		$variants = [];
		for ($i = 0; $i < $armCount; ++$i) {
			$split[] = 1.0 / $armCount;
			$variants[] = (object) ['name' => chr(65 + $i), 'config' => '{"sb.arm":' . $i . '}'];
		}
		return (object) [
			'id' => $id,
			'name' => $name,
			'unitType' => self::$UNIT_TYPE,
			'iteration' => 1,
			'seedHi' => self::$SEED_HI,
			'seedLo' => self::$SEED_LO,
			'split' => $split,
			'trafficSeedHi' => 1,
			'trafficSeedLo' => 2,
			'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => 0,
			'applications' => [(object) ['name' => 'website']],
			'variants' => $variants,
			'audience' => null,
			'audienceStrict' => false,
			'randomizationType' => 'switchback',
			'switchbackClusters' => (object) [self::$UNIT_TYPE => self::$CLUSTER_PATH],
			'switchbackStartAt' => self::$ANCHOR,
			'switchbackPeriodMs' => $periodMs ?? self::$HOUR,
			'switchbackCarryoverDurationMs' => 0,
			'switchbackBurnInDurationMs' => 0,
			'switchbackBurnOutDurationMs' => 0,
			'switchbackDesignType' => 'balanced_block',
			'switchbackEstimand' => 'global_average_treatment_effect',
		];
	}

	/** An ordered switchbackClusters object: type, path, type, path, ... */
	protected static function clusters(string ...$entries): object {
		$clusters = new \stdClass();
		for ($i = 0; $i < count($entries); $i += 2) {
			$clusters->{$entries[$i]} = $entries[$i + 1];
		}
		return $clusters;
	}

	/** A unit experiment like switchback(), without the switchback fields. */
	protected static function unitExperiment(int $armCount = 2, int $id = 2, string $name = 'exp_unit'): object {
		$experiment = self::switchback($armCount, null, $id, $name);
		foreach (array_keys(get_object_vars($experiment)) as $field) {
			if ($field === 'randomizationType' || strpos($field, 'switchback') === 0) {
				unset($experiment->{$field});
			}
		}
		return $experiment;
	}

	protected static function dataOf(object ...$experiments): ContextData {
		return new ContextData($experiments);
	}

	/**
	 * @param array<string, string>|null $units
	 * @param array<string, mixed>|null $attributes
	 */
	protected function createContext(ContextData $data, ?array $units = null, ?array $attributes = null, string $environment = 'production'): Context {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', $environment));
		$config = new Config($client);
		$this->dataProvider = new HoldoutDataProvider();
		$config->setContextDataProvider($this->dataProvider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$this->logger = new MockContextEventLoggerProxy();

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($units ?? [self::$UNIT_TYPE => self::$UID]);
		$contextConfig->setAttributes($attributes ?? [self::$CLUSTER_PATH => self::$CLUSTER_KEY]);
		$contextConfig->setEventLogger($this->logger);

		return (new SDK($config))->createContextWithData($contextConfig, $data);
	}

	protected function refreshTo(Context $context, object ...$experiments): void {
		$this->dataProvider->next = new ContextData($experiments);
		$context->refresh();
	}

	/** [id, name, unit, variant, exposedAt, assigned, eligible, overridden, fullOn, custom, audienceMismatch, ruleOverride] */
	protected static function exposure(int $id, string $name, string $unit, int $variant, int $exposedAt, bool $assigned = true,
		bool $eligible = true, bool $overridden = false, bool $fullOn = false, bool $custom = false,
		bool $audienceMismatch = false, bool $ruleOverride = false): array {
		return [$id, $name, $unit, $variant, $exposedAt, $assigned, $eligible, $overridden, $fullOn, $custom, $audienceMismatch, $ruleOverride];
	}

	/** Publishes and returns every exposure the handler has received so far, as exposure() rows. */
	protected function publishExposures(Context $context): array {
		$context->publish();
		$exposures = [];
		foreach ($this->eventHandler->submitted as $event) {
			foreach ($event->exposures ?? [] as $e) {
				$exposures[] = [$e->id, $e->name, $e->unit, $e->variant, (int) $e->exposedAt, $e->assigned, $e->eligible,
					$e->overridden, $e->fullOn, $e->custom, $e->audienceMismatch, $e->ruleOverride];
			}
		}
		return $exposures;
	}

	/** The exposures logged to the event logger so far, as exposure() rows. */
	protected function loggedExposures(): array {
		$exposures = [];
		foreach ($this->logger->events ?? [] as $event) {
			if ($event->getEvent() === ContextEventLoggerEvent::Exposure) {
				$e = $event->getData();
				$exposures[] = [$e->id, $e->name, $e->unit, $e->variant, (int) $e->exposedAt, $e->assigned, $e->eligible,
					$e->overridden, $e->fullOn, $e->custom, $e->audienceMismatch, $e->ruleOverride];
			}
		}
		return $exposures;
	}

	protected static function privateValue(Context $context, string $property) {
		$reflection = new \ReflectionProperty(Context::class, $property);
		if (PHP_VERSION_ID < 80100) {
			$reflection->setAccessible(true);
		}
		return $reflection->getValue($context);
	}

	/**
	 * Swaps in an AudienceMatcher that counts rule and audience evaluations.
	 *
	 * @return object{rules: int, audiences: int}
	 */
	protected static function countEvaluations(Context $context): object {
		$counts = (object) ['rules' => 0, 'audiences' => 0];
		$matcher = new class($counts) extends \ABSmartly\SDK\AudienceMatcher {
			private object $counts;

			public function __construct(object $counts) {
				parent::__construct();
				$this->counts = $counts;
			}

			public function evaluate(\stdClass $audience, array $attributes): ?bool {
				++$this->counts->audiences;
				return parent::evaluate($audience, $attributes);
			}

			public function evaluateParsedRules(array $rules, ?string $environmentName, array $attributes): ?array {
				++$this->counts->rules;
				return parent::evaluateParsedRules($rules, $environmentName, $attributes);
			}
		};
		$reflection = new \ReflectionProperty(Context::class, 'audienceMatcher');
		if (PHP_VERSION_ID < 80100) {
			$reflection->setAccessible(true);
		}
		$reflection->setValue($context, $matcher);
		return $counts;
	}

	protected static function cacheSize(Context $context): int {
		return count(self::privateValue($context, 'assignmentCache'));
	}

	/**
	 * The cached slot of a switchback at the current clock reading, read without resolving anything (java-sdk's
	 * switchbackResolutionForTest): its unit, eligibility and whether it records nothing. Null when nothing is cached.
	 *
	 * @return array{unitType: ?string, assigned: bool, eligible: bool, silent: bool, applies: bool}|null
	 */
	protected function resolution(Context $context, string $name): ?array {
		$experiment = $context->getExperiment($name);
		$key = $name;
		if ($experiment !== null && $experiment->data->randomizationType === 'switchback') {
			$sources = self::privateValue($context, 'switchbackSources')[$name];
			$data = $experiment->data;
			if ($sources->servable && $sources->paths !== null && $this->now >= $data->switchbackStartAt) {
				$key = $name . "\x1f" . \ABSmartly\SDK\Switchback\SwitchbackAssigner::floorDiv($this->now - $data->switchbackStartAt, $data->switchbackPeriodMs);
			}
		}
		$assignment = self::privateValue($context, 'assignmentCache')[$key] ?? null;
		if ($assignment === null) {
			return null;
		}
		$slot = $assignment->switchback;
		return [
			'unitType' => $assignment->unitType,
			'assigned' => $assignment->assigned,
			'eligible' => $assignment->eligible,
			'silent' => $slot !== null && $slot->silent,
			'applies' => $slot !== null && $slot->serving->status !== \ABSmartly\SDK\Switchback\SwitchbackServing::ABSENT,
		];
	}
}

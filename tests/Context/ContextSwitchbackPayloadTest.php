<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Experiment;
use ABSmartly\SDK\Http\HttpClientInterface;
use ABSmartly\SDK\Http\Response;
use ABSmartly\SDK\Switchback\SwitchbackAssigner;
use PHPUnit\Framework\TestCase;

/**
 * Switchbacks as they arrive from the collector: the model fields, decoding through the client, forward compatibility,
 * the declared cluster order surviving json_decode, and no cost or byte change for payloads without switchbacks.
 */
class ContextSwitchbackPayloadTest extends TestCase {
	use SwitchbackTestSupport;

	private static function fixture(string $name): object {
		return json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/' . $name), false, 512, JSON_THROW_ON_ERROR);
	}

	private static function httpClient(string $content): HttpClientInterface {
		return new class($content) implements HttpClientInterface {
			private string $content;

			public function __construct(string $content) {
				$this->content = $content;
			}

			public function get(string $url, array $query = [], array $headers = []): Response {
				$response = new Response();
				$response->status = 200;
				$response->content = $this->content;
				return $response;
			}

			public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response {
				$response = new Response();
				$response->status = 200;
				$response->content = '';
				return $response;
			}

			public function post(string $url, array $query = [], array $headers = [], string $body = ''): Response {
				return $this->put($url, $query, $headers, $body);
			}

			public function close(): void {
			}
		};
	}

	public function testExperimentReadsTheSwitchbackFields(): void {
		$experiment = new Experiment(self::fixture('switchback_context.json')->experiments[0]);
		self::assertSame('switchback', $experiment->randomizationType);
		// Declared order, not sorted: store_id before device_id.
		self::assertSame(['store_id' => 'store/key', 'device_id' => 'geo/city'], $experiment->switchbackClusters);
		self::assertSame(['store_id', 'device_id'], array_keys($experiment->switchbackClusters));
		self::assertSame(['porto', 'lisbon'], $experiment->switchbackClusterValues);
		self::assertSame(1767225600000, $experiment->switchbackStartAt);
		self::assertSame(3600000, $experiment->switchbackPeriodMs);
		self::assertSame(900000, $experiment->switchbackCarryoverDurationMs);
		self::assertSame(600000, $experiment->switchbackBurnInDurationMs);
		self::assertSame(0, $experiment->switchbackBurnOutDurationMs);
		self::assertSame('balanced_block', $experiment->switchbackDesignType);
		self::assertSame('global_average_treatment_effect', $experiment->switchbackEstimand);
		// The Phase 1E fields and unknown fields are not read.
		self::assertFalse(property_exists($experiment, 'switchbackClusterUnitType'));
		self::assertFalse(property_exists($experiment, 'switchbackClusterCount'));
		self::assertFalse(property_exists($experiment, 'switchbackFutureField'));

		$unit = new Experiment(self::fixture('switchback_context.json')->experiments[1]);
		self::assertSame('unit', $unit->randomizationType);
		foreach (['switchbackClusters', 'switchbackClusterValues', 'switchbackStartAt', 'switchbackPeriodMs',
			'switchbackCarryoverDurationMs', 'switchbackBurnInDurationMs', 'switchbackBurnOutDurationMs', 'switchbackDesignType',
			'switchbackEstimand'] as $field) {
			self::assertNull($unit->{$field}, $field);
		}
	}

	public function testMalformedSwitchbackFieldsAreDroppedNotFatal(): void {
		$data = self::switchback(2);
		$data->randomizationType = ['switchback'];
		$data->switchbackClusters = ['store_id', 'store_key'];
		$data->switchbackClusterValues = 'porto';
		$data->switchbackStartAt = '1767225600000';
		$data->switchbackPeriodMs = 1.5;
		$data->switchbackCarryoverDurationMs = true;
		$data->switchbackBurnInDurationMs = 1e30;
		$data->switchbackBurnOutDurationMs = NAN;
		$data->switchbackDesignType = 7;
		$data->switchbackEstimand = (object) [];
		$experiment = new Experiment($data);
		foreach (['randomizationType', 'switchbackClusters', 'switchbackClusterValues', 'switchbackStartAt', 'switchbackPeriodMs',
			'switchbackCarryoverDurationMs', 'switchbackBurnInDurationMs', 'switchbackBurnOutDurationMs', 'switchbackDesignType',
			'switchbackEstimand'] as $field) {
			self::assertNull($experiment->{$field}, $field);
		}

		// Integral floats count as integers; an associative array is a cluster map; whole numbers in the allow-list are text.
		$data = self::switchback(2);
		$data->switchbackStartAt = 1767225600000.0;
		$data->switchbackPeriodMs = 3600000.0;
		$data->switchbackClusters = ['store_id' => 'store_key', 'device_id' => 'city'];
		$data->switchbackClusterValues = ['porto', 17, 1.5];
		$experiment = new Experiment($data);
		self::assertSame(1767225600000, $experiment->switchbackStartAt);
		self::assertSame(3600000, $experiment->switchbackPeriodMs);
		self::assertSame(['store_id' => 'store_key', 'device_id' => 'city'], $experiment->switchbackClusters);
		self::assertSame(['porto', '17', 1.5], $experiment->switchbackClusterValues);
	}

	/** An Experiment serialized before switchbacks existed unserializes with the new fields defaulted. */
	public function testAnExperimentSerializedWithoutTheFieldsUnserializes(): void {
		$experiment = new Experiment(self::unitExperiment());
		$serialized = serialize($experiment);
		foreach (['randomizationType', 'switchbackClusters', 'switchbackClusterValues', 'switchbackStartAt', 'switchbackPeriodMs',
			'switchbackCarryoverDurationMs', 'switchbackBurnInDurationMs', 'switchbackBurnOutDurationMs', 'switchbackDesignType',
			'switchbackEstimand'] as $field) {
			$serialized = preg_replace('/s:\d+:"' . $field . '";N;/', '', $serialized);
		}
		$serialized = preg_replace('/^(O:24:"ABSmartly\\\\SDK\\\\Experiment":)\d+/', '${1}' . (count((array) $experiment) - 10), $serialized);
		self::assertStringNotContainsString('switchback', $serialized);
		$restored = unserialize($serialized);
		self::assertInstanceOf(Experiment::class, $restored);
		self::assertNull($restored->randomizationType);
		self::assertNull($restored->switchbackClusters);
	}

	/** The java-sdk fixture through the client's own decode: the store unit serves its raw cluster key. */
	public function testTheJavaFixtureServesThroughTheClientDecode(): void {
		$json = file_get_contents(__DIR__ . '/../Fixtures/json/switchback_context.json');
		$client = new Client(new ClientConfig('endpoint', 'key', 'app', 'env'), self::httpClient($json));
		$data = $client->getContextData();
		$asyncData = null;
		$client->getContextDataAsync()->then(function (ContextData $resolved) use (&$asyncData) {
			$asyncData = $resolved;
		});
		self::assertEquals($data, $asyncData);

		foreach ([$data, $asyncData] as $contextData) {
			$this->now = 1767225600000 + 5;
			$context = $this->createContext($contextData, ['store_id' => 'store-1', 'device_id' => 'device-1'],
				['store' => ['key' => 'porto'], 'geo' => ['city' => 'porto']]);
			$expected = SwitchbackAssigner::assign('porto', 0, (3603515 << 32) | 233373850, 2);
			self::assertSame($expected, $context->getTreatment('exp_test_switchback'));
			self::assertSame([self::exposure(1, 'exp_test_switchback', 'store_id', $expected, 1767225600000 + 5)], $this->loggedExposures());
		}
	}

	/** Scenario 425: three sources in non-alphabetical JSON order; json_decode keeps it, and the declared order serves. */
	public function testDeclaredClusterOrderSurvivesJsonDecode(): void {
		$json = '{"experiments":[' . json_encode(array_replace((array) self::switchback(3), [
			'switchbackClusters' => null,
		])) . ']}';
		foreach ([
			['{"user_id":"u_key","device_id":"d_key","app_id":"a_key"}', 'user_id'],
			['{"device_id":"d_key","user_id":"u_key","app_id":"a_key"}', 'device_id'],
			['{"app_id":"a_key","user_id":"u_key","device_id":"d_key"}', 'app_id'],
		] as [$clusters, $serving]) {
			$client = new Client(new ClientConfig('endpoint', 'key', 'app', 'env'),
				self::httpClient(str_replace('"switchbackClusters":null', '"switchbackClusters":' . $clusters, $json)));
			$this->now = self::$ANCHOR + 1;
			$context = $this->createContext($client->getContextData(), ['app_id' => 'a', 'device_id' => 'd', 'user_id' => 'u'],
				['u_key' => 'k-user', 'd_key' => 'k-user', 'a_key' => 'k-user']);
			$expected = SwitchbackAssigner::assign('k-user', 0, (self::$SEED_HI << 32) | (self::$SEED_LO & 0xFFFFFFFF), 3);
			self::assertSame($expected, $context->getTreatment('exp_sb'));
			self::assertSame($serving, $this->loggedExposures()[0][2], $clusters);
		}
	}

	/** A unit type that looks like an integer ("123") becomes an int array key in PHP; it is still matched as a string. */
	public function testNumericLookingUnitTypesAreMatched(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackClusters = json_decode('{"123":"city","store_id":"other"}');
		$context = $this->createContext(self::dataOf($experiment), ['123' => 'u', 'store_id' => 's'], ['city' => 'Porto']);
		self::assertSame(SwitchbackAssigner::assign('Porto', 0, (self::$SEED_HI << 32) | (self::$SEED_LO & 0xFFFFFFFF), 2),
			$context->getTreatment('exp_sb'));
		self::assertSame('123', $this->loggedExposures()[0][2]);
	}

	/** Unknown fields inside and around switchback data are ignored, with no PHP warnings. */
	public function testUnknownFieldsAreIgnored(): void {
		$experiment = self::switchback(2);
		$experiment->switchbackFutureField = (object) ['nested' => [1, 2, 3]];
		$experiment->switchbackClusterUnitType = 'store_id';
		$experiment->switchbackClusterCount = 50;
		$experiment->switchbackClusterAttribute = 'geo/city';
		$experiment->randomizationFuture = ['x'];
		$unit = self::unitExperiment();
		$unit->switchbackFutureField = 'x';
		$errors = [];
		set_error_handler(function (int $errno, string $message) use (&$errors) {
			$errors[] = $message;
			return true;
		});
		try {
			$context = $this->createContext(self::dataOf($experiment, $unit));
			self::assertSame(self::$EXPECTED_2_ARMS[0], $context->getTreatment('exp_sb'));
			self::assertSame((new \ABSmartly\SDK\VariantAssigner(self::$UID))->assign([0.5, 0.5], self::$SEED_HI, self::$SEED_LO),
				$context->getTreatment('exp_unit'));
		}
		finally {
			restore_error_handler();
		}
		self::assertSame([], $errors);
		self::assertFalse($context->isFailed());
	}

	/** Only the Phase 1E fields: an experiment with no cluster map takes the legacy path, a silent control. */
	public function testPhase1EOnlyFieldsAreALegacyControl(): void {
		$experiment = self::switchback(2);
		unset($experiment->switchbackClusters);
		$experiment->switchbackClusterUnitType = self::$UNIT_TYPE;
		$experiment->switchbackClusterCount = 50;
		$experiment->switchbackClusterAttribute = self::$CLUSTER_PATH;
		$context = $this->createContext(self::dataOf($experiment));
		self::assertSame(0, $context->getTreatment('exp_sb'));
		self::assertSame(0, $context->getPendingCount());
	}

	/** A payload without switchbacks reads the clock exactly as before: never for a peek, once per exposure. */
	public function testNoClockReadsOrStateWithoutSwitchbacks(): void {
		$reads = 0;
		$this->setUp();
		$property = new \ReflectionProperty(\ABSmartly\SDK\Context\Context::class, 'clock');
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		$property->setValue(null, function () use (&$reads): int {
			++$reads;
			return self::$ANCHOR;
		});

		$unit = self::unitExperiment();
		$unit->randomizationType = 'unit';
		$unit->switchbackClusters = self::clusters(self::$UNIT_TYPE, 'x');
		$context = $this->createContext(self::dataOf($unit, self::unitExperiment(2, 3, 'exp_other')), null, []);
		$reads = 0;
		$context->peekTreatment('exp_unit');
		$context->peekVariableValue('sb.arm', -1);
		self::assertSame(0, $reads);
		$context->getTreatment('exp_unit');
		self::assertSame(1, $reads);
		$context->getTreatment('exp_unit');
		$context->getVariableValue('sb.arm', -1);
		self::assertSame(1, $reads);
		self::assertSame([], self::privateValue($context, 'switchbackSources'));
		self::assertSame([], self::privateValue($context, 'switchbackEpochs'));
		// The guard that keeps unit reads off the switchback path stays off.
		self::assertFalse(self::privateValue($context, 'hasSwitchbacks'));
	}

	/**
	 * A switchback read takes one clock reading, cached slot or not. An exposing read also takes the enqueue reading
	 * Exposure::ingestAssignment() always took; the exposure keeps the resolution's.
	 */
	public function testOneClockReadPerSwitchbackRead(): void {
		$reads = 0;
		$property = new \ReflectionProperty(\ABSmartly\SDK\Context\Context::class, 'clock');
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		$property->setValue(null, function () use (&$reads): int {
			++$reads;
			return self::$ANCHOR + 1;
		});
		$context = $this->createContext(self::dataOf(self::switchback(2)), null, []);
		self::assertTrue(self::privateValue($context, 'hasSwitchbacks'));
		$context->setAttribute(self::$CLUSTER_PATH, self::$CLUSTER_KEY);
		foreach ([['peekTreatment', 1], ['getTreatment', 2], ['getTreatment', 1], ['peekTreatment', 1]] as [$method, $expected]) {
			$reads = 0;
			$context->$method('exp_sb');
			self::assertSame($expected, $reads, $method);
		}
		$reads = 0;
		$context->getVariableValue('sb.arm', -1);
		self::assertSame(1, $reads);
	}

	private function publishedBody(ContextData $data): string {
		$this->now = self::$ANCHOR;
		$context = $this->createContext($data, ['session_id' => 'e791e240fcd3df7d238cfc285f475e8152fcc0ec', 'user_id' => '123', 'email' => 'a@b.c'], []);
		$context->setAttribute('age', 21);
		foreach ($data->experiments as $experiment) {
			$context->getTreatment($experiment->name);
		}
		foreach (array_keys($context->getVariableKeys()) as $key) {
			$context->getVariableValue($key, 'default');
		}
		$context->track('goal', ['amount' => 5]);
		$context->publish();

		return preg_replace('/"(publishedAt|exposedAt|achievedAt|setAt)":[0-9.]+/', '"$1":0', json_encode($this->eventHandler->submitted));
	}

	/**
	 * Inertness: unit experiments ("unit" or absent randomizationType) carrying every switchback field publish the bytes
	 * main published (publish_without_holdouts.json, recorded from origin/main 80a5ec6, timestamps zeroed).
	 */
	public function testUnitExperimentsWithSwitchbackFieldsPublishTheRecordedBytes(): void {
		$expected = rtrim(file_get_contents(__DIR__ . '/../Fixtures/json/publish_without_holdouts.json'));
		self::assertSame($expected, $this->publishedBody(new ContextData(self::fixture('context.json')->experiments)));

		foreach (['unit', null, 'absent'] as $type) {
			$experiments = self::fixture('context.json')->experiments;
			foreach ($experiments as $experiment) {
				foreach (get_object_vars(self::switchback()) as $field => $value) {
					if ($field === 'randomizationType' || strpos($field, 'switchback') === 0) {
						$experiment->{$field} = $value;
					}
				}
				$experiment->switchbackClusters = self::clusters('user_id', 'age', 'session_id', 'age');
				if ($type === 'absent') {
					unset($experiment->randomizationType);
				}
				else {
					$experiment->randomizationType = $type;
				}
			}
			self::assertSame($expected, $this->publishedBody(new ContextData($experiments)), (string) $type);
		}
	}
}

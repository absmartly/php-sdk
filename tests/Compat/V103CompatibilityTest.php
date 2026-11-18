<?php

namespace ABSmartly\SDK\Tests\Compat;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;
use ABSmartly\SDK\Http\HTTPClient;
use ABSmartly\SDK\Http\Response;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use PHPUnit\Framework\TestCase;

class RecordingHTTPClient extends HTTPClient {
	public array $requests = [];

	public function get(string $url, array $query = [], array $headers = []): Response {
		$this->requests[] = ['GET', $headers];
		$response = new Response();
		$response->status = 200;
		$response->content = '{"experiments":[]}';
		return $response;
	}

	public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response {
		$this->requests[] = ['PUT', $headers];
		$response = new Response();
		$response->status = 200;
		$response->content = '{}';
		return $response;
	}
}

class FailingProvider extends ContextDataProvider {
	public function __construct() {
	}

	public function getContextData(): ContextData {
		throw new \RuntimeException('collector down');
	}
}

/**
 * Pins the v1.0.3 contracts that 1.1.0 must keep, written the way a 1.0.3 customer uses the SDK.
 */
class V103CompatibilityTest extends TestCase {
	private $errorLog;
	private string $errorLogFile;

	protected function setUp(): void {
		$this->errorLogFile = tempnam(sys_get_temp_dir(), 'abs-errlog');
		$this->errorLog = ini_set('error_log', $this->errorLogFile);
	}

	protected function tearDown(): void {
		ini_set('error_log', (string) $this->errorLog);
		@unlink($this->errorLogFile);
	}

	public function testEntryClassKeepsItsReleasedName(): void {
		$sdk = SDK::createWithDefaults('https://demo.absmartly.io/v1', 'key', 'env', 'app');

		self::assertSame('ABSmartly\SDK\SDK', get_class($sdk));
		self::assertStringStartsWith('O:17:"ABSmartly\\SDK\\SDK":', serialize($sdk));
	}

	public function testEmptyApiKeyStillFetchesAndPublishes(): void {
		$http = new RecordingHTTPClient();
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', '', 'app', 'env'), $http);
		$sdk = new SDK(new Config($client));

		$context = $sdk->createContext((new ContextConfig())->setUnit('session_id', 'abc'));
		self::assertFalse($context->isFailed());
		$context->track('goal');
		$context->publish();

		self::assertSame(['GET', 'PUT'], array_column($http->requests, 0));
		self::assertSame('', file_get_contents($this->errorLogFile));
	}

	public function testFailedLoadAndPublishWriteNothingToErrorLogWithoutAnEventLogger(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextDataProvider(new FailingProvider());
		$handler = new ContextEventHandlerMock($client);
		$handler->prerun = static function() {
			throw new \RuntimeException('publish failed');
		};
		$config->setContextEventHandler($handler);

		$context = (new SDK($config))->createContext((new ContextConfig())->setUnit('session_id', 'abc'));
		self::assertTrue($context->isFailed());
		$context->getTreatment('exp');
		$context->publish();
		$context->refresh();

		self::assertSame('', file_get_contents($this->errorLogFile));
	}

	public function testAssignmentVariablesKeepsItsV103Type(): void {
		$type = (new \ReflectionProperty(\ABSmartly\SDK\Assignment::class, 'variables'))->getType();
		self::assertSame('stdClass', $type->getName());
		self::assertFalse($type->allowsNull());
	}

	public function testAnExperimentMissingARequiredFieldDegradesOnlyItself(): void {
		$fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'));
		$broken = clone $fixture->experiments[0];
		$broken->name = 'exp_broken';
		unset($broken->seedHi);
		$fixture->experiments[] = $broken;

		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$logger = new \ABSmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy();
		$contextConfig = (new ContextConfig())->setUnit('session_id', 'e791e240fcd3df7d238cfc285f475e8152fcc0ec');
		$contextConfig->setEventLogger($logger);
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData($fixture->experiments));

		self::assertFalse($context->isFailed());
		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(0, $context->getTreatment('exp_broken'));
		self::assertNull($context->getExperiment('exp_broken'));
		self::assertSame(ContextEventLoggerEvent::Error, $logger->events[0]->getEvent());
		self::assertStringContainsString('seedHi', $logger->events[0]->getData()->getMessage());
	}

	public function testAnExperimentWithANullTypedFieldDegradesOnlyItself(): void {
		$fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'));
		$broken = clone $fixture->experiments[0];
		$broken->name = 'exp_broken';
		$broken->unitType = null;
		$fixture->experiments[] = $broken;

		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$contextConfig = (new ContextConfig())->setUnit('session_id', 'e791e240fcd3df7d238cfc285f475e8152fcc0ec');
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData($fixture->experiments));

		self::assertFalse($context->isFailed());
		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertNull($context->getExperiment('exp_broken'));
	}

	public function testSetUnitsStoresNumbersAsStrings(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$context = (new SDK($config))->createContextWithData(new ContextConfig(), new ContextData([]));

		$context->setUnits(['user_id' => 125, 'f' => 1.5, 's' => 'abc']);

		self::assertSame('125', $context->getUnit('user_id'));
		self::assertSame('1.5', $context->getUnit('f'));
		self::assertSame('abc', $context->getUnit('s'));
	}

	public function testAThrowingPublishLoggerWritesNothingToErrorLog(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$handler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($handler);
		$contextConfig = (new ContextConfig())->setUnit('session_id', 'abc');
		$contextConfig->setEventLogger(new \ABSmartly\SDK\Context\ContextEventLoggerCallback(
			static function(string $event) {
				if ($event === ContextEventLoggerEvent::Publish) {
					throw new \RuntimeException('logger failed');
				}
			}
		));
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData([]));
		$context->track('goal');
		$context->publish();

		self::assertSame(0, $context->getPendingCount());
		self::assertSame('', file_get_contents($this->errorLogFile));
	}

	public function testContextConfigAttributesAreConvertedToStringsAsInV103(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$handler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($handler);
		$contextConfig = (new ContextConfig())->setUnit('session_id', 'abc');
		$contextConfig->setAttributes(['age' => 25, 'vip' => true, 'name' => 'Alice']);
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData([]));

		self::assertSame('25', $context->getAttribute('age'));
		self::assertSame('1', $context->getAttribute('vip'));
		$context->track('goal');
		$context->publish();
		$published = array_column(json_decode(json_encode($handler->submitted[0]), true)['attributes'], 'value', 'name');
		self::assertSame(['age' => '25', 'vip' => '1', 'name' => 'Alice'], $published);
	}

	public function testAnOverrideToVariant0IsExposedOnce(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$handler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($handler);
		$fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'));
		$context = (new SDK($config))->createContextWithData(
			(new ContextConfig())->setUnit('session_id', 'abc'),
			new ContextData($fixture->experiments)
		);
		$context->setOverride('exp_test_ab', 0);

		foreach ([1, 2, 3] as $read) {
			self::assertSame(0, $context->getTreatment('exp_test_ab'));
		}
		self::assertSame(1, $context->getPendingCount());
		$context->publish();
		self::assertCount(1, $handler->submitted[0]->exposures);
	}

	public function testMalformedAudienceJsonDegradesOnlyItsExperimentOnLoadAndRefresh(): void {
		$fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'));
		$broken = clone $fixture->experiments[1];
		$broken->name = 'exp_bad_audience';
		$broken->audience = '{not json';

		$withBroken = array_merge($fixture->experiments, [$broken]);

		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextDataProvider(new class($client, $withBroken) extends ContextDataProvider {
			private array $experiments;

			public function __construct(Client $client, array $experiments) {
				parent::__construct($client);
				$this->experiments = $experiments;
			}

			public function getContextData(): ContextData {
				return new ContextData($this->experiments);
			}
		});
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$logger = new \ABSmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy();
		$contextConfig = (new ContextConfig())->setUnit('session_id', 'e791e240fcd3df7d238cfc285f475e8152fcc0ec');
		$contextConfig->setEventLogger($logger);
		$sdk = new SDK($config);

		$context = $sdk->createContext($contextConfig);
		self::assertFalse($context->isFailed());
		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertNull($context->getExperiment('exp_bad_audience'));

		self::assertNotContains('exp_bad_audience', $context->getExperiments());
		self::assertInstanceOf(\JsonException::class, $logger->events[0]->getData());

		$refreshed = $sdk->createContextWithData(
			(new ContextConfig())->setUnit('session_id', 'e791e240fcd3df7d238cfc285f475e8152fcc0ec'),
			new ContextData([])
		);
		$refreshed->refresh();
		self::assertSame(1, $refreshed->getTreatment('exp_test_ab'), 'the valid experiments of the refresh are applied');
		self::assertNull($refreshed->getExperiment('exp_bad_audience'));
	}

	/**
	 * @dataProvider unpublishableValues
	 */
	public function testAnUnpublishableTypedAttributeIsRejectedAndPublishingKeepsWorking($value): void {
		$http = new RecordingHTTPClient();
		$config = new Config(new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'), $http));
		$context = (new SDK($config))->createContextWithData((new ContextConfig())->setUnit('session_id', 'abc'), new ContextData([]));

		try {
			$context->setAttributeValue('score', $value);
			self::fail('expected an InvalidArgumentException');
		}
		catch (\ABSmartly\SDK\Exception\InvalidArgumentException $e) {
			self::assertStringStartsWith('Attribute "score" value cannot be published', $e->getMessage());
		}
		self::assertNull($context->getAttribute('score'));

		$context->setAttributeValue('score', 7);
		$context->track('goal');
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(['PUT'], array_column($http->requests, 0));
	}

	public static function unpublishableValues(): array {
		return ['INF' => [INF], 'NaN' => [NAN], 'nested INF' => [['a' => -INF]]];
	}

	public function testInvalidUtf8StringAttributesAreAcceptedAsInV103(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$contextConfig = (new ContextConfig())->setUnit('session_id', 'abc');
		$contextConfig->setAttribute('ua', "Mozilla \xff");
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData([]));

		$context->setAttribute('city', "caf\xe9");
		$context->setAttributes(['region' => "\xc3"]);

		self::assertSame("Mozilla \xff", $context->getAttribute('ua'));
		self::assertSame("caf\xe9", $context->getAttribute('city'));
	}

	public function testUnpublishableGoalPropertiesAreRejectedAndPublishingKeepsWorking(): void {
		$http = new RecordingHTTPClient();
		$config = new Config(new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'), $http));
		$context = (new SDK($config))->createContextWithData((new ContextConfig())->setUnit('session_id', 'abc'), new ContextData([]));

		try {
			$context->trackWithProperties('bad', ['amount' => INF]);
			self::fail('expected an InvalidArgumentException');
		}
		catch (\ABSmartly\SDK\Exception\InvalidArgumentException $e) {
			self::assertStringStartsWith("Goal 'bad' properties cannot be published", $e->getMessage());
		}
		self::assertSame(0, $context->getPendingCount());

		$context->trackWithProperties('good', ['amount' => 5]);
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(['PUT'], array_column($http->requests, 0));
	}

	public function testARejectedExperimentLeavesNoVariableKeysBehind(): void {
		$fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'));
		// The lowest id, sharing button.color with exp_test_abc: its first variant decodes, then the second fails.
		$rejected = clone $fixture->experiments[1];
		$rejected->id = 0;
		$rejected->name = 'exp_rejected_late';
		$rejected->variants = [
			(object) ['name' => 'A', 'config' => '{"button.color":"from-rejected","rejected.only":1}'],
			(object) ['name' => 'B', 'config' => ['not-a-string']],
			(object) ['name' => 'C', 'config' => null],
		];

		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$context = (new SDK($config))->createContextWithData(
			(new ContextConfig())->setUnit('session_id', 'e791e240fcd3df7d238cfc285f475e8152fcc0ec'),
			new ContextData(array_merge([$rejected], $fixture->experiments))
		);

		self::assertFalse($context->isFailed());
		self::assertNull($context->getExperiment('exp_rejected_late'));
		self::assertSame('exp_test_abc', $context->getVariableKeys()['button.color']);
		self::assertArrayNotHasKey('rejected.only', $context->getVariableKeys());
		// exp_test_abc serves variant 2 ("red") to this unit; the rejected experiment's value never appears.
		self::assertSame('red', $context->peekVariableValue('button.color', 'none'));
	}

	/**
	 * @dataProvider serializationFailures
	 */
	public function testAThrowingJsonSerializableIsRejectedAsInvalidArgument(\Throwable $failure): void {
		$config = new Config(new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'), new RecordingHTTPClient()));
		$context = (new SDK($config))->createContextWithData((new ContextConfig())->setUnit('session_id', 'abc'), new ContextData([]));
		$dto = new class($failure) implements \JsonSerializable {
			private \Throwable $failure;

			public function __construct(\Throwable $failure) {
				$this->failure = $failure;
			}

			public function jsonSerialize(): array {
				throw $this->failure;
			}
		};

		foreach ([
			static fn() => $context->trackWithProperties('goal', $dto),
			static fn() => $context->setAttributeValue('dto', $dto),
		] as $call) {
			try {
				$call();
				self::fail('expected an InvalidArgumentException');
			}
			catch (\ABSmartly\SDK\Exception\InvalidArgumentException $e) {
				self::assertSame($failure, $e->getPrevious());
			}
		}
		self::assertSame(0, $context->getPendingCount());
	}

	public static function serializationFailures(): array {
		return [
			'an Exception' => [new \DomainException('not serializable')],
			'an Error' => [new \TypeError('not serializable')],
		];
	}

	/**
	 * @dataProvider emptyVariantConfigs
	 */
	public function testAnEmptyVariantConfigMeansNoVariablesAsInV103($config): void {
		$fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'));
		$fixture->experiments[1]->variants[1]->config = $config;
		$context = $this->contextWith($fixture->experiments);

		self::assertNotNull($context->getExperiment('exp_test_abc'));
		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		// Variant 1 of exp_test_abc now has no variables: overridden onto it, button.color falls back to the default.
		$context->setOverride('exp_test_abc', 1);
		self::assertSame('missing', $context->peekVariableValue('button.color', 'missing'));
		self::assertSame('missing', $context->getVariableValue('button.color', 'missing'));
	}

	public static function emptyVariantConfigs(): array {
		return ['[]' => [[]], '0' => [0], 'false' => [false], '""' => [''], '"0"' => ['0'], 'null' => [null]];
	}

	public function testASkippedExperimentReportsTheOriginalExceptionAsInV103(): void {
		$fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'));
		$fixture->experiments[1]->audience = '{not json';
		$logger = new \ABSmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy();
		$this->contextWith($fixture->experiments, $logger);

		$errors = array_values(array_filter($logger->events, static fn($e) => $e->getEvent() === ContextEventLoggerEvent::Error));
		self::assertCount(1, $errors);
		// The same type and message v1.0.3 passed, so a logger matching on them keeps working.
		self::assertInstanceOf(\JsonException::class, $errors[0]->getData());
		try {
			json_decode('{not json', false, 512, JSON_THROW_ON_ERROR);
			self::fail('expected invalid JSON');
		}
		catch (\JsonException $original) {
			self::assertSame($original->getMessage(), $errors[0]->getData()->getMessage());
			self::assertSame($original->getCode(), $errors[0]->getData()->getCode());
			// Not wrapped in another exception, and raised where Experiment decodes the audience.
			self::assertNull($errors[0]->getData()->getPrevious());
			self::assertStringEndsWith('Experiment.php', $errors[0]->getData()->getFile());
		}
	}

	public function testTheDepthCheckMatchesWhatAPublishCanEncode(): void {
		$http = new RecordingHTTPClient();
		$config = new Config(new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'), $http));
		$context = (new SDK($config))->createContextWithData((new ContextConfig())->setUnit('session_id', 'abc'), new ContextData([]));
		$nested = static function (int $depth) {
			$value = 1;
			for ($i = 0; $i < $depth; ++$i) {
				$value = [$value];
			}
			return $value;
		};

		// json_encode's default depth is 512 and a publish wraps each attribute in three levels (event, list, record).
		$context->setAttributeValue('deepest', $nested(509));
		try {
			$context->setAttributeValue('too_deep', $nested(510));
			self::fail('expected an InvalidArgumentException');
		}
		catch (\ABSmartly\SDK\Exception\InvalidArgumentException $e) {
			self::assertStringStartsWith('Attribute "too_deep" value cannot be published', $e->getMessage());
		}

		$context->track('goal');
		$context->publish();
		self::assertSame(0, $context->getPendingCount());
		self::assertSame(['PUT'], array_column($http->requests, 0));

		// The setter's limit is the stock publish encoder's: a real publish of 509 levels encodes, of 510 it fails.
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'), new RecordingHTTPClient());
		foreach ([509 => true, 510 => false] as $depth => $encodes) {
			$event = new \ABSmartly\SDK\PublishEvent();
			$event->setAttributes([(object) ['name' => 'a', 'value' => $nested($depth), 'setAt' => 1]]);
			try {
				$client->encode($event);
				self::assertTrue($encodes, "a $depth-level attribute must not encode");
			}
			catch (\JsonException $e) {
				self::assertFalse($encodes, "a $depth-level attribute must encode");
				self::assertSame(JSON_ERROR_DEPTH, $e->getCode());
			}
		}
	}

	private function contextWith(array $experiments, ?\ABSmartly\SDK\Context\ContextEventLogger $logger = null): \ABSmartly\SDK\Context\Context {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$contextConfig = (new ContextConfig())->setUnit('session_id', 'e791e240fcd3df7d238cfc285f475e8152fcc0ec');
		if ($logger !== null) {
			$contextConfig->setEventLogger($logger);
		}
		return (new SDK($config))->createContextWithData($contextConfig, new ContextData($experiments));
	}

	public function testGoalPropertiesAreCopiedOnlyByTrackWithProperties(): void {
		$handler = null;
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env'));
		$config = new Config($client);
		$config->setContextEventHandler($handler = new ContextEventHandlerMock($client));
		$context = (new SDK($config))->createContextWithData((new ContextConfig())->setUnit('session_id', 'abc'), new ContextData([]));

		$copied = (object) ['amount' => 1, 'item' => (object) ['sku' => 'a']];
		$kept = (object) ['amount' => 1];
		$context->trackWithProperties('copied', $copied);
		$context->track('kept', $kept);
		$copied->amount = 2;
		$copied->item->sku = 'b';
		$kept->amount = 2;
		$context->publish();

		$goals = array_column(json_decode(json_encode($handler->submitted[0]), true)['goals'], 'properties', 'name');
		self::assertSame(1, $goals['copied']['amount']);
		// The copy is one level deep: a nested object is shared, so its later change is published.
		self::assertSame('b', $goals['copied']['item']['sku']);
		self::assertSame(2, $goals['kept']['amount'], 'track() keeps the object, as in v1.0.3');
	}
}

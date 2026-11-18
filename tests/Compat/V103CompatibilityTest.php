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
}

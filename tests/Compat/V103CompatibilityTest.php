<?php

namespace ABSmartly\SDK\Tests\Compat;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
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
}

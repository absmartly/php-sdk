<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Http\HttpClientInterface;
use ABSmartly\SDK\Http\AsyncHttpClientInterface;
use ABSmartly\SDK\Http\Response;
use ABSmartly\SDK\PublishEvent;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class ClientAsyncTest extends TestCase {
	private function createMockAsyncHttpClient(): AsyncHttpClientInterface {
		$mock = $this->createMock(AsyncHttpClientInterface::class);
		return $mock;
	}

	private function createMockSyncHttpClient(): HttpClientInterface {
		$mock = $this->createMock(HttpClientInterface::class);
		return $mock;
	}

	private function createContextDataResponse(): Response {
		$response = new Response();
		$response->status = 200;
		$response->content = json_encode([
			'experiments' => [
				[
					'id' => 1,
					'name' => 'test_exp',
					'unitType' => 'session_id',
					'iteration' => 1,
					'seedHi' => 0,
					'seedLo' => 0,
					'split' => [0.5, 0.5],
					'trafficSeedHi' => 0,
					'trafficSeedLo' => 0,
					'trafficSplit' => [0.0, 1.0],
					'fullOnVariant' => 0,
					'applications' => [['name' => 'app']],
					'variants' => [[], []],
					'audienceStrict' => false,
					'audience' => null
				]
			]
		]);
		return $response;
	}

	public function testGetContextDataAsyncWithAsyncClient(): void {
		$httpClient = $this->createMockAsyncHttpClient();
		$response = $this->createContextDataResponse();

		$httpClient->method('getAsync')
			->willReturn(resolve($response));

		$config = new ClientConfig('https://example.com', 'api-key', 'app', 'env');
		$client = new Client($config, $httpClient);

		self::assertTrue($client->isAsync());

		$promise = $client->getContextDataAsync();
		self::assertInstanceOf(PromiseInterface::class, $promise);
	}

	public function testGetContextDataAsyncWithSyncClientReturnsResolvedPromise(): void {
		$httpClient = $this->createMockSyncHttpClient();
		$response = $this->createContextDataResponse();

		$httpClient->method('get')
			->willReturn($response);

		$config = new ClientConfig('https://example.com', 'api-key', 'app', 'env');
		$client = new Client($config, $httpClient);

		self::assertFalse($client->isAsync());

		$promise = $client->getContextDataAsync();
		self::assertInstanceOf(PromiseInterface::class, $promise);

		$result = null;
		$promise->then(function($data) use (&$result) {
			$result = $data;
		});

		self::assertInstanceOf(ContextData::class, $result);
	}

	public function testPublishAsyncWithAsyncClient(): void {
		$httpClient = $this->createMockAsyncHttpClient();
		$response = new Response();
		$response->status = 200;
		$response->content = '{}';

		$httpClient->method('putAsync')
			->willReturn(resolve($response));

		$config = new ClientConfig('https://example.com', 'api-key', 'app', 'env');
		$client = new Client($config, $httpClient);

		$publishEvent = new PublishEvent();
		$promise = $client->publishAsync($publishEvent);

		self::assertInstanceOf(PromiseInterface::class, $promise);
	}

	public function testPublishAsyncWithSyncClientReturnsResolvedPromise(): void {
		$httpClient = $this->createMockSyncHttpClient();
		$response = new Response();
		$response->status = 200;
		$response->content = '{}';

		$httpClient->method('put')
			->willReturn($response);

		$config = new ClientConfig('https://example.com', 'api-key', 'app', 'env');
		$client = new Client($config, $httpClient);

		$publishEvent = new PublishEvent();
		$promise = $client->publishAsync($publishEvent);

		self::assertInstanceOf(PromiseInterface::class, $promise);

		$resolved = false;
		$promise->then(function() use (&$resolved) {
			$resolved = true;
		});

		self::assertTrue($resolved);
	}
}

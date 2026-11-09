<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\AsyncClientInterface;
use ABSmartly\SDK\Context\AsyncContextDataProvider;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class AsyncContextDataProviderTest extends TestCase {
	public function testExtendsContextDataProvider(): void {
		$client = $this->createMock(AsyncClientInterface::class);
		$provider = new AsyncContextDataProvider($client);

		self::assertInstanceOf(ContextDataProvider::class, $provider);
	}

	public function testGetContextDataAsyncReturnsPromise(): void {
		$client = $this->createMock(AsyncClientInterface::class);
		$contextData = new ContextData();

		$client->method('getContextDataAsync')
			->willReturn(resolve($contextData));

		$provider = new AsyncContextDataProvider($client);
		$promise = $provider->getContextDataAsync();

		self::assertInstanceOf(PromiseInterface::class, $promise);
	}

	public function testGetContextDataAsyncResolvesToContextData(): void {
		$client = $this->createMock(AsyncClientInterface::class);
		$contextData = new ContextData();
		$contextData->experiments = [];

		$client->method('getContextDataAsync')
			->willReturn(resolve($contextData));

		$provider = new AsyncContextDataProvider($client);
		$promise = $provider->getContextDataAsync();

		$result = null;
		$promise->then(function($data) use (&$result) {
			$result = $data;
		});

		self::assertSame($contextData, $result);
	}

	public function testGetContextDataSyncStillWorks(): void {
		$client = $this->createMock(AsyncClientInterface::class);
		$contextData = new ContextData();
		$contextData->experiments = [];

		$client->method('getContextData')
			->willReturn($contextData);

		$provider = new AsyncContextDataProvider($client);
		$result = $provider->getContextData();

		self::assertSame($contextData, $result);
	}
}

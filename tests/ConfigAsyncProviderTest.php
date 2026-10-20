<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\AsyncContextDataProvider;
use ABSmartly\SDK\Http\AsyncHttpClientInterface;
use ABSmartly\SDK\Http\HttpClientInterface;
use PHPUnit\Framework\TestCase;

class ConfigAsyncProviderTest extends TestCase {
	public function testAsyncTransportSelectsAsyncProvider(): void {
		$client = new Client(new ClientConfig('https://example.com', 'key', 'app', 'env'), $this->createMock(AsyncHttpClientInterface::class));
		self::assertInstanceOf(AsyncContextDataProvider::class, (new Config($client))->getContextDataProvider());
	}

	public function testSyncTransportKeepsSyncProvider(): void {
		$client = new Client(new ClientConfig('https://example.com', 'key', 'app', 'env'), $this->createMock(HttpClientInterface::class));
		self::assertNotInstanceOf(AsyncContextDataProvider::class, (new Config($client))->getContextDataProvider());
	}
}

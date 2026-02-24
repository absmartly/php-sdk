<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Client\ClientInterface;
use ABSmartly\SDK\Client\AsyncClientInterface;
use ABSmartly\SDK\Http\HTTPClient;
use PHPUnit\Framework\TestCase;

class ClientInterfaceTest extends TestCase {
	public function testClientImplementsClientInterface(): void {
		$config = new ClientConfig('https://example.com', 'api-key', 'app', 'env');
		$client = new Client($config);

		self::assertInstanceOf(ClientInterface::class, $client);
	}

	public function testClientImplementsAsyncClientInterface(): void {
		$config = new ClientConfig('https://example.com', 'api-key', 'app', 'env');
		$client = new Client($config);

		self::assertInstanceOf(AsyncClientInterface::class, $client);
	}

	public function testClientInterfaceDefinesRequiredMethods(): void {
		$reflection = new \ReflectionClass(ClientInterface::class);

		self::assertTrue($reflection->hasMethod('getContextData'));
		self::assertTrue($reflection->hasMethod('publish'));
		self::assertTrue($reflection->hasMethod('close'));
	}

	public function testAsyncClientInterfaceDefinesRequiredMethods(): void {
		$reflection = new \ReflectionClass(AsyncClientInterface::class);

		self::assertTrue($reflection->hasMethod('getContextDataAsync'));
		self::assertTrue($reflection->hasMethod('publishAsync'));
	}

	public function testClientHasIsAsyncMethod(): void {
		$config = new ClientConfig('https://example.com', 'api-key', 'app', 'env');
		$client = new Client($config);

		self::assertFalse($client->isAsync());
	}

	public function testClientAcceptsHttpClientInterface(): void {
		$config = new ClientConfig('https://example.com', 'api-key', 'app', 'env');
		$httpClient = new HTTPClient();
		$client = new Client($config, $httpClient);

		self::assertInstanceOf(Client::class, $client);
		self::assertFalse($client->isAsync());
	}
}

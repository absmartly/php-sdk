<?php

namespace ABSmartly\SDK\Tests\Http;

use ABSmartly\SDK\Http\ReactHttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ReactHttpClientTest extends TestCase {
	public function testFlattenHeadersReturnsAssociativeArray(): void {
		$client = new ReactHttpClient();
		$method = new ReflectionMethod(ReactHttpClient::class, 'flattenHeaders');
		$method->setAccessible(true);

		$headers = [
			'Content-Type' => 'application/json',
			'X-API-Key' => 'test-key',
			'Authorization' => 'Bearer token123',
		];

		$result = $method->invoke($client, $headers);

		self::assertSame('application/json', $result['Content-Type']);
		self::assertSame('test-key', $result['X-API-Key']);
		self::assertSame('Bearer token123', $result['Authorization']);
		self::assertCount(3, $result);
	}

	public function testFlattenHeadersEmptyArray(): void {
		$client = new ReactHttpClient();
		$method = new ReflectionMethod(ReactHttpClient::class, 'flattenHeaders');
		$method->setAccessible(true);

		$result = $method->invoke($client, []);
		self::assertSame([], $result);
	}
}

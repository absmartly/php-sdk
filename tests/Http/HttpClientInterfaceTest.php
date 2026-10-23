<?php

namespace ABSmartly\SDK\Tests\Http;

use ABSmartly\SDK\Http\HttpClientInterface;
use ABSmartly\SDK\Http\HTTPClient;
use PHPUnit\Framework\TestCase;

class HttpClientInterfaceTest extends TestCase {
	public function testHTTPClientImplementsInterface(): void {
		$client = new HTTPClient();
		self::assertInstanceOf(HttpClientInterface::class, $client);
	}

	public function testInterfaceDefinesRequiredMethods(): void {
		$reflection = new \ReflectionClass(HttpClientInterface::class);

		self::assertTrue($reflection->hasMethod('get'));
		self::assertTrue($reflection->hasMethod('put'));
		self::assertTrue($reflection->hasMethod('post'));
		self::assertTrue($reflection->hasMethod('close'));
	}
}

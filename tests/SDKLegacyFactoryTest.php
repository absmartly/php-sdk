<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\SDK;
use PHPUnit\Framework\TestCase;

class SDKLegacyFactoryTest extends TestCase {
	public function testCreateWithDefaultsReturnsSdkInstance(): void {
		$sdk = SDK::createWithDefaults('https://example.com', 'key', 'env', 'app');
		self::assertInstanceOf(SDK::class, $sdk);
	}
}

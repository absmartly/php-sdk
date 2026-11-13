<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK;
use PHPUnit\Framework\TestCase;

class SDKAliasTest extends TestCase {
	public function testLegacyClassNameStillWorks(): void {
		$sdk = \ABSmartly\SDK\SDK::createWithDefaults('https://demo.absmartly.io/v1', 'key', 'env', 'app');

		self::assertInstanceOf(SDK::class, $sdk);
		self::assertInstanceOf(\ABSmartly\SDK\SDK::class, $sdk);
		self::assertSame(SDK::class, get_class($sdk));
	}
}

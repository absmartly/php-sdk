<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\SDK;
use PHPUnit\Framework\TestCase;

class SDKAliasTest extends TestCase {
	public function testReleasedClassNameIsTheRealClass(): void {
		$sdk = SDK::createWithDefaults('https://demo.absmartly.io/v1', 'key', 'env', 'app');

		// get_class() and serialize() keep the v1.0.3 name, so rolling back can still unserialize.
		self::assertSame('ABSmartly\SDK\SDK', get_class($sdk));
		self::assertInstanceOf(\ABSmartly\SDK::class, $sdk);
	}
}

<?php

namespace Absmartly\SDK\Tests\Client;

use Absmartly\SDK\Client\ClientConfig;
use PHPUnit\Framework\TestCase;

class ClientConfigTest extends TestCase {
	public function testClientConfigVarDumpHidesApiKey(): void {
		$clientConfig = new ClientConfig('', 'test', '', '');
		$output = print_r($clientConfig, true);
		self::assertStringContainsString('****', $output);
	}

	public function testGetterSetters(): void {
		$clientConfig = new ClientConfig('test-endpoint', 'test-key', 'test-application', 'test-environment');
		self::assertSame('test-key', $clientConfig->getApiKey());
		self::assertSame('test-application', $clientConfig->getApplication());
		self::assertSame('test-endpoint', $clientConfig->getEndpoint());
		self::assertSame('test-environment', $clientConfig->getEnvironment());
	}
}

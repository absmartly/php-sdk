<?php

namespace Absmartly\SDK\Tests;

use Absmartly\SDK\ClientConfig;
use PHPUnit\Framework\TestCase;

class ClientConfigTest extends TestCase {
	public function testClientConfigVarDumpHidesApiKey(): void {
		$clientConfig = new ClientConfig('test', '', '', '');
		$output = print_r($clientConfig, true);
		self::assertStringContainsString('****', $output);
	}
}

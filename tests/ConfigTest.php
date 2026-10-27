<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\ContextEventLogger;
use ABSmartly\SDK\Context\ContextEventLoggerCallback;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase {
	private function createConfig(): Config {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$client = new Client($clientConfig);
		return new Config($client);
	}

	public function testSetContextEventLoggerReturnsFluentInterface(): void {
		$config = $this->createConfig();
		$logger = new ContextEventLoggerCallback(function () {});
		$result = $config->setContextEventLogger($logger);
		self::assertSame($config, $result);
	}

	public function testGetContextEventLoggerReturnsNullByDefault(): void {
		$config = $this->createConfig();
		self::assertNull($config->getContextEventLogger());
	}

	public function testSetAndGetContextEventLogger(): void {
		$config = $this->createConfig();
		$logger = new ContextEventLoggerCallback(function () {});
		$config->setContextEventLogger($logger);
		self::assertSame($logger, $config->getContextEventLogger());
	}

	public function testSetContextEventLoggerWithNull(): void {
		$config = $this->createConfig();
		$logger = new ContextEventLoggerCallback(function () {});
		$config->setContextEventLogger($logger);
		$config->setContextEventLogger(null);
		self::assertNull($config->getContextEventLogger());
	}
}

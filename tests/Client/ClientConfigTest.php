<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Exception\InvalidArgumentException;
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

	public function testTimeoutDefaultValue(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		self::assertSame(3000, $clientConfig->getTimeout());
	}

	public function testSetTimeoutValidValue(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$clientConfig->setTimeout(5000);
		self::assertSame(5000, $clientConfig->getTimeout());
	}

	public function testSetTimeoutZeroThrowsException(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Timeout value must be larger than 0');
		$clientConfig->setTimeout(0);
	}

	public function testSetTimeoutNegativeThrowsException(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$this->expectException(InvalidArgumentException::class);
		$clientConfig->setTimeout(-100);
	}

	public function testRetriesDefaultValue(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		self::assertSame(5, $clientConfig->getRetries());
	}

	public function testSetRetriesValidValue(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$clientConfig->setRetries(10);
		self::assertSame(10, $clientConfig->getRetries());
	}

	public function testSetRetriesZeroIsAllowed(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$clientConfig->setRetries(0);
		self::assertSame(0, $clientConfig->getRetries());
	}

	public function testSetRetriesNegativeThrowsException(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Retries value must be 0 (no retries) or larger');
		$clientConfig->setRetries(-1);
	}

	public function testFluentInterface(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$result = $clientConfig->setTimeout(1000)->setRetries(3);
		self::assertSame($clientConfig, $result);
		self::assertSame(1000, $clientConfig->getTimeout());
		self::assertSame(3, $clientConfig->getRetries());
	}
}

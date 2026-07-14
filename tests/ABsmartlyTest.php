<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\ABsmartly;
use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextEventLoggerCallback;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;
use ABSmartly\SDK\Tests\Mocks\ContextDataProviderMock;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use ABSmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class ABsmartlyTest extends TestCase {
	public function testCreateSimpleParameterOrderMatchesClientConfig(): void {
		$reflection = new ReflectionMethod(ABsmartly::class, 'createSimple');
		$params = $reflection->getParameters();

		self::assertSame('endpoint', $params[0]->getName());
		self::assertSame('apiKey', $params[1]->getName());
		self::assertSame('application', $params[2]->getName());
		self::assertSame('environment', $params[3]->getName());
	}

	public function testCreateSimpleIsNotDeprecated(): void {
		$reflection = new ReflectionMethod(ABsmartly::class, 'createSimple');
		$docComment = $reflection->getDocComment();

		self::assertStringNotContainsString('@deprecated', $docComment);
	}

	public function testCreateWithDefaultsIsDeprecated(): void {
		$reflection = new ReflectionMethod(ABsmartly::class, 'createWithDefaults');
		$docComment = $reflection->getDocComment();

		self::assertStringContainsString('@deprecated', $docComment);
	}

	public function testCreateWithDefaultsParameterOrderIsPreservedForBackwardCompatibility(): void {
		$reflection = new ReflectionMethod(ABsmartly::class, 'createWithDefaults');
		$params = $reflection->getParameters();

		self::assertSame('endpoint', $params[0]->getName());
		self::assertSame('apiKey', $params[1]->getName());
		self::assertSame('environment', $params[2]->getName());
		self::assertSame('application', $params[3]->getName());
	}

	public function testEventLoggerFromConfigIsStoredOnInstance(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', 'key', 'app', 'env');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$logger = new MockContextEventLoggerProxy();
		$config->setContextEventLogger($logger);

		$sdk = new ABsmartly($config);

		$prop = new ReflectionProperty(ABsmartly::class, 'eventLogger');
		$prop->setAccessible(true);
		self::assertSame($logger, $prop->getValue($sdk));
	}

	public function testEventLoggerFromConfigPropagatedToContext(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$dataProvider = new ContextDataProviderMock($client);
		$dataProvider->setSource('context.json');
		$config->setContextDataProvider($dataProvider);

		$eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($eventHandler);

		$logger = new MockContextEventLoggerProxy();
		$config->setContextEventLogger($logger);

		$sdk = new ABsmartly($config);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits([
			'session_id' => 'e791e240fcd3df7d238cfc285f475e8152fcc0ec',
		]);

		$context = $sdk->createContext($contextConfig);
		self::assertTrue($context->isReady());
		self::assertGreaterThan(0, $logger->called);

		$readyEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Ready);
		self::assertCount(1, $readyEvents);
	}

	public function testContextConfigEventLoggerNotOverriddenBySdkLogger(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$dataProvider = new ContextDataProviderMock($client);
		$dataProvider->setSource('context.json');
		$config->setContextDataProvider($dataProvider);

		$eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($eventHandler);

		$sdkLogger = new MockContextEventLoggerProxy();
		$config->setContextEventLogger($sdkLogger);

		$contextLogger = new MockContextEventLoggerProxy();

		$sdk = new ABsmartly($config);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits([
			'session_id' => 'e791e240fcd3df7d238cfc285f475e8152fcc0ec',
		]);
		$contextConfig->setEventLogger($contextLogger);

		$context = $sdk->createContext($contextConfig);
		self::assertTrue($context->isReady());
		self::assertSame(0, $sdkLogger->called);
		self::assertGreaterThan(0, $contextLogger->called);
	}
}

<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\ABsmartly;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
}

<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Context\ContextConfig;
use PHPUnit\Framework\TestCase;

class ContextConfigTest extends TestCase {

	public function testInitParams(): void {
		$contextConfig = new ContextConfig();
		self::assertInstanceOf(ContextConfig::class, $contextConfig); // Useless assertion, but ensures the signature.
	}

	public function testUnits(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setUnit('foo', 'foo-value'); // Value retention
		$contextConfig->setUnit('bar', 2); // Automatic casting to string
		$contextConfig->setUnit('baz', 'old-baz-value'); // Old value
		$contextConfig->setUnit('baz', 'new-baz-value'); // New value is overwritten
		$contextConfig->setUnit('baz', 'new-baz-value'); // New value is overwritten

		self::assertSame('foo-value', $contextConfig->getUnit('foo'));
		self::assertSame('2', $contextConfig->getUnit('bar'));
		self::assertSame('new-baz-value', $contextConfig->getUnit('baz'));

		self::assertNull($contextConfig->getUnit('does-not-exist'));

		self::assertCount(3, $contextConfig->getUnits());

		$contextConfig->setUnits(
			[
				'x' => 1,
				'y' => 'mango'
			],
		);

		self::assertCount(5, $contextConfig->getUnits());

		self::assertSame('1', $contextConfig->getUnit('x'));
		self::assertSame('mango', $contextConfig->getUnit('y'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unit set value with key "x" must be of type string, array passed');

		$contextConfig->setUnits(
			[
				'x' => [],
			],
		);

	}

	public function testAttributes(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setAttribute('foo', (object) [1]); // Value retention
		$contextConfig->setAttribute('bar', (object) [1, 2, 3]);
		$contextConfig->setAttribute('baz', (object) ['x' => 1, 'y' => 5]); // Old value
		$contextConfig->setAttribute('baz', (object) ['x' => 1, 'y' => 6]); // New value is overwritten

		self::assertEquals((object) [1], $contextConfig->getAttribute('foo'));
		self::assertEquals((object) [1, 2, 3], $contextConfig->getAttribute('bar'));
		self::assertEquals((object) ['x' => 1, 'y' => 6], $contextConfig->getAttribute('baz'));

		self::assertNull($contextConfig->getAttribute('does-not-exist'));

		self::assertCount(3, $contextConfig->getAttributes());

		$contextConfig->setAttributes(
			[
				'x' => (object) ['x' => 1, 'y' => 7],
				'y' => (object) ['x' => 1, 'y' => 8],
			],
		);

		self::assertCount(5, $contextConfig->getAttributes());

		self::assertEquals((object) ['x' => 1, 'y' => 7], $contextConfig->getAttribute('x'));
		self::assertEquals((object) ['x' => 1, 'y' => 8], $contextConfig->getAttribute('y'));
	}


	public function testOverrides(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setOverride('foo', 1); // Value retention
		$contextConfig->setOverride('bar', 2);
		$contextConfig->setOverride('baz', 3); // Old value
		$contextConfig->setOverride('baz', 4); // New value is overwritten

		self::assertEquals(1, $contextConfig->getOverride('foo'));
		self::assertEquals(2, $contextConfig->getOverride('bar'));
		self::assertEquals(4, $contextConfig->getOverride('baz'));

		self::assertNull($contextConfig->getOverride('does-not-exist'));

		self::assertCount(3, $contextConfig->getOverrides());

		$contextConfig->setOverrides(
			[
				'x' => 42,
				'y' => 45,
			],
		);

		self::assertCount(5, $contextConfig->getOverrides());

		self::assertEquals(42, $contextConfig->getOverride('x'));
		self::assertEquals(45, $contextConfig->getOverride('y'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Override set value with key "x" must be of type integer, array passed');

		$contextConfig->setOverrides(
			[
				'x' => [],
			],
		);

	}


	public function testCustomAssignments(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setCustomAssignment('foo', 1); // Value retention
		$contextConfig->setCustomAssignment('bar', 2);
		$contextConfig->setCustomAssignment('baz', 3); // Old value
		$contextConfig->setCustomAssignment('baz', 4); // New value is overwritten

		self::assertEquals(1, $contextConfig->getCustomAssignment('foo'));
		self::assertEquals(2, $contextConfig->getCustomAssignment('bar'));
		self::assertEquals(4, $contextConfig->getCustomAssignment('baz'));

		self::assertNull($contextConfig->getCustomAssignment('does-not-exist'));

		self::assertCount(3, $contextConfig->getCustomAssignments());

		$contextConfig->setCustomAssignments(
			[
				'x' => 42,
				'y' => 45,
			],
		);

		self::assertCount(5, $contextConfig->getCustomAssignments());

		self::assertEquals(42, $contextConfig->getCustomAssignment('x'));
		self::assertEquals(45, $contextConfig->getCustomAssignment('y'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Custom assignment set value with key "x" must be of type integer, array passed');

		$contextConfig->setCustomAssignments(
			[
				'x' => [],
			],
		);

	}


	public function testPublishDelay(): void {
		$contextConfig = new ContextConfig();
		self::assertNotNull($contextConfig->getPublishDelay());
		self::assertInstanceOf(ContextConfig::class, $contextConfig->setPublishDelay(42));
		self::assertInstanceOf(ContextConfig::class, $contextConfig->setPublishDelay(16));

		self::assertSame(16, $contextConfig->getPublishDelay());
	}

	public function testRefreshInterval(): void {
		$contextConfig = new ContextConfig();
		self::assertNotNull($contextConfig->getRefreshInterval());
		self::assertInstanceOf(ContextConfig::class, $contextConfig->setRefreshInterval(42));
		self::assertInstanceOf(ContextConfig::class, $contextConfig->setRefreshInterval(16));

		self::assertSame(16, $contextConfig->getRefreshInterval());
	}
}

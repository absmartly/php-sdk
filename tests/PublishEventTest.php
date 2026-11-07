<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\PublishEvent;
use PHPUnit\Framework\TestCase;

class PublishEventTest extends TestCase {
	public function testLegacyHashUnitOverrideReceivesCanonicalString(): void {
		$event = new class extends PublishEvent {
			public array $seen = [];

			public function hashUnit(string $unit): string {
				$this->seen[] = $unit;
				return parent::hashUnit($unit);
			}
		};
		$event->setUnits(['user' => 12.5]);
		self::assertSame(['13'], $event->seen);
		self::assertSame($event->hashUnit('13'), $event->jsonSerialize()->units[0]->uid);
	}

	public function testPublishEventSerializes(): void {
		$event = new PublishEvent();
		$event->setUnits([]);
		$event->publishedAt = (int) (microtime(true) * 1000);

		$json = json_encode($event, JSON_THROW_ON_ERROR);
		self::assertJson($json);
	}
}

<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\PublishEvent;
use PHPUnit\Framework\TestCase;

class PublishEventTest extends TestCase {
	public function testPublishEventSerializes(): void {
		$event = new PublishEvent();
		$event->setUnits([]);
		$event->publishedAt = (int) (microtime(true) * 1000);

		$json = json_encode($event, JSON_THROW_ON_ERROR);
		self::assertJson($json);
	}
}

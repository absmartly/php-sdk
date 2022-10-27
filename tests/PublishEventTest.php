<?php

namespace Absmartly\SDK\Tests;

use Absmartly\SDK\PublishEvent;
use PHPUnit\Framework\TestCase;

class PublishEventTest extends TestCase {
	public function testPublishEventSerializes(): void {
		$event = new PublishEvent();
		$event->units = [];

		$json = json_encode($event, JSON_THROW_ON_ERROR);
		self::assertJson($json);
	}
}

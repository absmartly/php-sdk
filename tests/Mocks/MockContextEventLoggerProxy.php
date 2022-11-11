<?php

namespace ABSmartly\SDK\Tests\Mocks;

use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextEventLogger;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;

class MockContextEventLoggerProxy implements ContextEventLogger {
	public int $called = 0;
	public array $events;

	public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
		++$this->called;
		$this->events[] = $event;
	}

	public function clear(): void {
		$this->events = [];
		$this->called = 0;
	}
}

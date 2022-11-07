<?php

namespace Absmartly\SDK\Tests\Mocks;

use Absmartly\SDK\Context\Context;
use Absmartly\SDK\Context\ContextEventLogger;
use Absmartly\SDK\Context\ContextEventLoggerEvent;

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

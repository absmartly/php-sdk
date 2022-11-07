<?php

namespace Absmartly\SDK\Context;

class ContextEventLoggerCallback implements ContextEventLogger {

	private $callable;

	public function __construct(callable $callable) {
		$this->callable = $callable;
	}

	public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
		($this->callable)($event->getEvent(), $event->getData());
	}
}

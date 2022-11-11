<?php

namespace ABSmartly\SDK\Context;

interface ContextEventLogger {
	public function handleEvent(Context $context, ContextEventLoggerEvent $event): void;
}

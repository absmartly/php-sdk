<?php

namespace Absmartly\SDK\Context;

interface ContextEventLogger {
	public function handleEvent(Context $context, ContextEventLoggerEvent $event): void;
}

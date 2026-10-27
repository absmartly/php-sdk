<?php

namespace ABSmartly\SDK\Client;

use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\PublishEvent;

interface ClientInterface {
	public function getContextData(): ContextData;
	public function publish(PublishEvent $publishEvent): void;
	public function close(): void;
}

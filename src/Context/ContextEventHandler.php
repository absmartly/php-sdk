<?php

namespace ABSmartly\SDK\Context;

use ABSmartly\SDK\Client\ClientInterface;
use ABSmartly\SDK\PublishEvent;

class ContextEventHandler {
	private ClientInterface $client;

	public function __construct(ClientInterface $client) {
		$this->client = $client;
	}

	public function publish(PublishEvent $event): void {
		$this->client->publish($event);
	}
}

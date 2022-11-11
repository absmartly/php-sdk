<?php

namespace ABSmartly\SDK\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\PublishEvent;

class ContextEventHandler {
	private Client $client;

	public function __construct(Client $client) {
		$this->client = $client;
	}

	public function publish(PublishEvent $event): void {
		$this->client->publish($event);
	}
}

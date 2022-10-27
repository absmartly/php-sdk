<?php

namespace Absmartly\SDK\Context;

use Absmartly\SDK\Client\Client;
use Absmartly\SDK\PublishEvent;

class ContextEventHandler {
	private Client $client;

	public function __construct(Client $client) {
		$this->client = $client;
	}

	public function publish(PublishEvent $event): void {
		$this->client->publish($event);
	}
}

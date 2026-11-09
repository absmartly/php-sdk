<?php

namespace ABSmartly\SDK\Context;

use ABSmartly\SDK\Client\AsyncClientInterface;
use ABSmartly\SDK\PublishEvent;
use React\Promise\PromiseInterface;

class AsyncContextEventHandler extends ContextEventHandler {
	private AsyncClientInterface $asyncClient;

	public function __construct(AsyncClientInterface $client) {
		parent::__construct($client);
		$this->asyncClient = $client;
	}

	public function publishAsync(PublishEvent $event): PromiseInterface {
		return $this->asyncClient->publishAsync($event);
	}
}

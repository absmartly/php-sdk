<?php

namespace ABSmartly\SDK\Context;

use ABSmartly\SDK\Client\AsyncClientInterface;
use React\Promise\PromiseInterface;

class AsyncContextDataProvider extends ContextDataProvider {
	private AsyncClientInterface $asyncClient;

	public function __construct(AsyncClientInterface $client) {
		parent::__construct($client);
		$this->asyncClient = $client;
	}

	public function getContextDataAsync(): PromiseInterface {
		return $this->asyncClient->getContextDataAsync();
	}
}

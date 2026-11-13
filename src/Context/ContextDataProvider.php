<?php

namespace ABSmartly\SDK\Context;

use ABSmartly\SDK\Client\ClientInterface;

class ContextDataProvider {
	private ClientInterface $client;

	public function __construct(ClientInterface $client) {
		$this->client = $client;
	}

	public function getContextData(): ContextData {
		return $this->client->getContextData();
	}
}

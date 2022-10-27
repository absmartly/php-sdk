<?php

namespace Absmartly\SDK\Context;

use Absmartly\SDK\Client\Client;

class ContextDataProvider {
	private Client $client;

	public function __construct(Client $client) {
		$this->client = $client;
	}

	public function getContextData(): ContextData {
		return $this->client->getContextData();
	}
}

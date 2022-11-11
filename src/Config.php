<?php

namespace ABSmartly\SDK;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Context\ContextDataProvider;
use ABSmartly\SDK\Context\ContextEventHandler;

class Config {
	private Client $client;
	private ContextDataProvider $contextDataProvider;
	private ContextEventHandler $contextEventHandler;

	public function __construct(Client $client) {
		$this->client = $client;
	}

	public function getClient(): Client {
		return $this->client;
	}

	public function setContextDataProvider(ContextDataProvider $contextDataProvider): Config {
		$this->contextDataProvider = $contextDataProvider;
		return $this;
	}

	public function setContextEventHandler(ContextEventHandler $contextEventHandler): Config {
		$this->contextEventHandler = $contextEventHandler;
		return $this;
	}

	public function getContextDataProvider(): ContextDataProvider {
		if (!isset($this->contextDataProvider)) {
			$this->contextDataProvider = new ContextDataProvider($this->client);
		}
		return $this->contextDataProvider;
	}

	public function getContextEventHandler(): ContextEventHandler {
		if (!isset($this->contextEventHandler)) {
			$this->contextEventHandler = new ContextEventHandler($this->client);
		}

		return $this->contextEventHandler;
	}
}

<?php

namespace Absmartly\SDK;

use Absmartly\SDK\Client\Client;
use Absmartly\SDK\Context\ContextDataProvider;
use Absmartly\SDK\Context\ContextEventHandler;
use Absmartly\SDK\Context\ContextEventLogger;

class Config {
	private Client $client;
	private ContextDataProvider $contextDataProvider;
	private ?Scheduler $scheduler = null;
	private ?contextEventHandler $contextEventHandler = null;
	private ?ContextEventLogger $contextEventLogger = null;
	private ?VariableParser $variableParser = null;


	public function __construct(Client $client) {
		$this->client = $client;
	}

	public function setClient(Client $client): self {
		$this->client = $client;
		return $this;
	}

	public function getClient(): Client {
		return $this->client;
	}

	public function setContextDataProvider(ContextDataProvider $contextDataProvider): Config {
		$this->contextDataProvider = $contextDataProvider;
		return $this;
	}

	public function getContextDataProvider(): ContextDataProvider {
		if (!isset($this->contextDataProvider)) {
			$this->contextDataProvider = new ContextDataProvider($this->client);
		}
		return $this->contextDataProvider;
	}
}

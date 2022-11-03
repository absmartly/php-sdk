<?php

namespace Absmartly\SDK;

use Absmartly\SDK\Client\Client;
use Absmartly\SDK\Client\ClientConfig;
use Absmartly\SDK\Context\Context;
use Absmartly\SDK\Context\ContextConfig;
use Absmartly\SDK\Context\ContextData;
use Absmartly\SDK\Context\ContextDataProvider;
use Absmartly\SDK\Context\ContextEventLogger;
use Absmartly\SDK\Context\ContextPublisher;
use Absmartly\SDK\Http\HTTPClient;

final class SDK {
	private $clientOptions;
	private Client $client;
	private ContextPublisher $publisher;
	private ContextDataProvider $provider;
	private ?ContextDataProvider $contextDataProvider = null;
	private ?Scheduler $scheduler = null;
	private ?contextEventHandler $contextEventHandler = null;
	private ?ContextEventLogger $contextEventLogger = null;
	private ?VariableParser $variableParser = null;

	public function __construct(Config $config) {
		$this->client = $config->getClient();
		$this->provider = $config->getContextDataProvider() ?? new ContextDataProvider($this->client);
		// Todo: Ingest other properties from Config instance
	}

	public static function createWithDefaults(
		string $apiKey,
		string $application,
		string $endpoint,
		string $environment
	): SDK {

		$clientConfig = new ClientConfig(
			$apiKey,
			$application,
			$endpoint,
			$environment
		);

		$client = new Client($clientConfig, new HTTPClient());
		$sdkConfig = new Config($client);
		return new SDK($sdkConfig);
	}

	public function createContext(ContextConfig $contextConfig): Context {
		return Context::createFromContextConfig($this, $contextConfig, $this->provider->getContextData());
	}

	public function createContextWith(ContextConfig $contextConfig, ContextData $contextData): Context {
		return Context::createFromContextConfig($this, $contextConfig);
	}

	public function getContextData(): ContextData {
		return $this->client->getContextData();
	}

	public function close(): void {
		$this->client->close();
	}
}

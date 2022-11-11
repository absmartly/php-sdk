<?php

namespace ABSmartly\SDK;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
use ABSmartly\SDK\Context\ContextEventHandler;
use ABSmartly\SDK\Http\HTTPClient;

final class SDK {

	private Client $client;
	private ContextEventHandler $handler;
	private ContextDataProvider $provider;

	public function __construct(Config $config) {
		$this->client = $config->getClient();
		$this->provider = $config->getContextDataProvider();
		$this->handler = $config->getContextEventHandler();
	}

	/**
	 * @param string $endpoint URL to your API endpoint. Most commonly "your-company.absmartly.io".
	 * @param string $apiKey API key which can be found on the Web Console.
	 * @param string $environment Environment of the platform where the SDK is installed. Environments are created on
	 *                  the Web Console and should match the available environments in your infrastructure.
	 * @param string $application Name of the application where the SDK is installed. Applications are created on the
	 *                  Web Console and should match the applications where your experiments will be running.
	 * @param int $retries The number of retries before the SDK stops trying to connect.
	 * @param int $timeout Amount of time, in milliseconds, before the SDK will stop trying to connect.
	 * @param callable|null $eventLogger A callback function which runs after SDK events.
	 * @return SDK SDK instance created using the credentials and details above.
	 */
	public static function createWithDefaults(
		string $endpoint,
		string $apiKey,
		string $environment,
		string $application,
		int $retries = 5,
		int $timeout = 3000,
		?callable $eventLogger = null
	): SDK {

		$clientConfig = new ClientConfig(
			$endpoint,
			$apiKey,
			$application,
			$environment,
		);

		$client = new Client($clientConfig, new HTTPClient());
		$sdkConfig = new Config($client);
		return new SDK($sdkConfig);
	}

	public function createContext(ContextConfig $contextConfig): Context {
		return Context::createFromContextConfig($this, $contextConfig, $this->provider, $this->handler);
	}

	public function createContextWithData(ContextConfig $contextConfig, ContextData $contextData): Context {
		return Context::createFromContextConfig($this, $contextConfig, $this->provider, $this->handler, $contextData);
	}

	public function close(): void {
		$this->client->close();
	}
}

<?php

namespace ABSmartly\SDK;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Context\AsyncContextDataProvider;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
use ABSmartly\SDK\Context\ContextPublisher;
use ABSmartly\SDK\Context\ContextEventLogger;
use ABSmartly\SDK\Http\HTTPClient;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class ABsmartly {

	private Client $client;
	private ContextPublisher $handler;
	private ContextDataProvider $provider;
	private ?ContextEventLogger $eventLogger;

	public function __construct(Config $config) {
		$this->client = $config->getClient();
		$this->provider = $config->getContextDataProvider();
		$this->handler = $config->getContextEventHandler();
		$this->eventLogger = $config->getContextEventLogger();
	}

	/**
	 * @param string $endpoint URL to your API endpoint. Most commonly "your-company.absmartly.io".
	 * @param string $apiKey API key which can be found on the Web Console.
	 * @param string $application Name of the application where the SDK is installed. Applications are created on the
	 *                  Web Console and should match the applications where your experiments will be running.
	 * @param string $environment Environment of the platform where the SDK is installed. Environments are created on
	 *                  the Web Console and should match the available environments in your infrastructure.
	 * @param int $retries The number of retries before the SDK stops trying to connect.
	 * @param int $timeout Amount of time, in milliseconds, before the SDK will stop trying to connect.
	 * @param callable|null $eventLogger A callback function which runs after SDK events.
	 * @return ABsmartly ABsmartly instance created using the credentials and details above.
	 */
	public static function createSimple(
		string $endpoint,
		string $apiKey,
		string $application,
		string $environment,
		int $retries = 5,
		int $timeout = 3000,
		?callable $eventLogger = null
	): ABsmartly {
		$clientConfig = new ClientConfig(
			$endpoint,
			$apiKey,
			$application,
			$environment,
		);
		$clientConfig->setRetries($retries);
		$clientConfig->setTimeout($timeout);

		$client = new Client($clientConfig, new HTTPClient());
		$sdkConfig = new Config($client);
		if ($eventLogger !== null) {
			$sdkConfig->setContextEventLogger(new \ABSmartly\SDK\Context\ContextEventLoggerCallback($eventLogger));
		}
		return new ABsmartly($sdkConfig);
	}

	/**
	 * @deprecated Use createSimple() instead. This method has $environment and $application in the wrong order
	 *             relative to ClientConfig::__construct(). createSimple() fixes this with the correct order:
	 *             endpoint, apiKey, application, environment.
	 *
	 * @param string $endpoint URL to your API endpoint. Most commonly "your-company.absmartly.io".
	 * @param string $apiKey API key which can be found on the Web Console.
	 * @param string $environment Environment of the platform where the SDK is installed. Environments are created on
	 *                  the Web Console and should match the available environments in your infrastructure.
	 * @param string $application Name of the application where the SDK is installed. Applications are created on the
	 *                  Web Console and should match the applications where your experiments will be running.
	 * @param int $retries The number of retries before the SDK stops trying to connect.
	 * @param int $timeout Amount of time, in milliseconds, before the SDK will stop trying to connect.
	 * @param callable|null $eventLogger A callback function which runs after SDK events.
	 * @return ABsmartly ABsmartly instance created using the credentials and details above.
	 */
	public static function createWithDefaults(
		string $endpoint,
		string $apiKey,
		string $environment,
		string $application,
		int $retries = 5,
		int $timeout = 3000,
		?callable $eventLogger = null
	): ABsmartly {

		$clientConfig = new ClientConfig(
			$endpoint,
			$apiKey,
			$application,
			$environment,
		);
		$clientConfig->setRetries($retries);
		$clientConfig->setTimeout($timeout);

		$client = new Client($clientConfig, new HTTPClient());
		$sdkConfig = new Config($client);
		if ($eventLogger !== null) {
			$sdkConfig->setContextEventLogger(new \ABSmartly\SDK\Context\ContextEventLoggerCallback($eventLogger));
		}
		return new ABsmartly($sdkConfig);
	}

	public function createContext(ContextConfig $contextConfig): Context {
		$this->applyEventLogger($contextConfig);
		return Context::createFromContextConfig($this, $contextConfig, $this->provider, $this->handler);
	}

	public function createContextWithData(ContextConfig $contextConfig, ContextData $contextData): Context {
		$this->applyEventLogger($contextConfig);
		return Context::createFromContextConfig($this, $contextConfig, $this->provider, $this->handler, $contextData);
	}

	private function applyEventLogger(ContextConfig $contextConfig): void {
		if ($this->eventLogger !== null && $contextConfig->getEventLogger() === null) {
			$contextConfig->setEventLogger($this->eventLogger);
		}
	}

	public function createContextAsync(ContextConfig $contextConfig): PromiseInterface {
		$this->applyEventLogger($contextConfig);
		if (!$this->provider instanceof AsyncContextDataProvider) {
			return resolve($this->createContext($contextConfig));
		}

		return $this->provider->getContextDataAsync()
			->then(fn($data) => $this->createContextWithData($contextConfig, $data));
	}

	public function createContextPending(ContextConfig $contextConfig): array {
		$this->applyEventLogger($contextConfig);
		$context = Context::createPending($this, $contextConfig, $this->provider, $this->handler);

		if (!$this->provider instanceof AsyncContextDataProvider) {
			$promise = resolve(null)->then(function() use ($context) {
				$data = $this->provider->getContextData();
				$context->setContextData($data);
				return $context;
			});
		} else {
			$promise = $this->provider->getContextDataAsync()
				->then(function($data) use ($context) {
					$context->setContextData($data);
					return $context;
				});
		}

		return ['context' => $context, 'promise' => $promise];
	}

	public function close(): void {
		$this->client->close();
	}
}

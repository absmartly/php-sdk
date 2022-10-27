<?php
declare(strict_types=1);
namespace Absmartly\SDK\Client;

use Absmartly\SDK\Context\ContextEventLogger;

class ClientConfig {
	private string $apiKey;
	private string $application;
	private string $endpoint;
	private string $environment;

	private ContextEventLogger $eventLogger;

	public function __construct(
		string $apiKey,
		string $application,
		string $endpoint,
		string $environment) {

		$this->apiKey = $apiKey;
		$this->application = $application;
		$this->endpoint = $endpoint;
		$this->environment = $environment;
	}

	/**
	 * Custom var-dump handler to prevent the apiKey parameter from leaking. Replace is with **** signs.
	 * @return array
	 */
	public function __debugInfo(): array {
		return [
			'apiKey' => str_repeat('*', strlen($this->apiKey)),
			'application' => $this->application,
			'endpoint' => $this->endpoint,
			'environment' => $this->environment,
			'eventLogger' => isset($this->eventLogger) ? get_class($this->eventLogger) : null,
		];
	}

	public function getEndpoint(): string {
		return $this->endpoint;
	}

	public function getApplication(): string {
		return $this->application;
	}

	public function getEnvironment(): string {
		return $this->environment;
	}

	public function getApiKey(): string {
		return $this->apiKey;
	}

	public function setContextEventLogger(ContextEventLogger $eventLogger): ClientConfig {
		$this->eventLogger = $eventLogger;
	}

	public function getEventLogger(): ContextEventLogger {
		return $this->eventLogger;
	}
}

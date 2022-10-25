<?php
declare(strict_types=1);
namespace Absmartly\SDK;

class ClientConfig {
	protected string $apiKey;
	protected string $application;
	protected string $endpoint;
	protected string $environment;

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
}

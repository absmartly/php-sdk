<?php

namespace Absmartly\SDK;

use SensitiveParameter;

class ClientConfig {
	public string $apiKey;
	public string $application;
	public string $endpoint;
	public string $environment;

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
}

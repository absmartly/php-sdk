<?php
declare(strict_types=1);
namespace ABSmartly\SDK\Client;

use ABSmartly\SDK\Exception\InvalidArgumentException;

use function get_class;
use function str_repeat;
use function strlen;

class ClientConfig {
	private string $apiKey;
	private string $application;
	private string $endpoint;
	private string $environment;

	private int $retries = 5;
	private int $timeout = 3000;

	public function __construct(
		string $endpoint,
		string $apiKey,
		string $application,
		string $environment
	) {

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



	/**
	 * @param int $retries The number of retries before the SDK stops trying to connect.
	 * @return $this
	 */
	public function setRetries(int $retries = 5): ClientConfig {
		if ($retries < 0) {
			throw new InvalidArgumentException('Retries value must be 0 (no retries) or larger');
		}

		$this->retries = $retries;
		return $this;
	}

	/**
	 * @param int $timeout Amount of time, in milliseconds, before the SDK will stop trying to connect.
	 * @return $this
	 */
	public function setTimeout(int $timeout = 3000): ClientConfig {
		if ($timeout <= 0) {
			throw new InvalidArgumentException('Timeout value must be larger than 0');
		}
		$this->timeout = $timeout;
		return $this;
	}

	public function getTimeout(): int {
		return $this->timeout;
	}

	public function getRetries(): int {
		return $this->retries;
	}
}

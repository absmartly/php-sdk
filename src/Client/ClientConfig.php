<?php
declare(strict_types=1);
namespace ABSmartly\SDK\Client;

use ABSmartly\SDK\Exception\InvalidArgumentException;

use function str_repeat;
use function strlen;
use function trim;

class ClientConfig {
	private string $apiKey;
	private string $application;
	private string $endpoint;
	private string $environment;
	private ?string $applicationVersion = null;

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
			// Always null, as in v1.0.3 (ClientConfig has no event logger); kept so the dump keeps its released keys.
			'eventLogger' => null,
			'applicationVersion' => $this->applicationVersion,
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
	 * Sets the application version, sent as the X-Application-Version header when it is a whole number (other
	 * versions, such as "2.4.1", are sent as "0"). Surrounding whitespace is removed; null or a blank value means no
	 * version is set.
	 */
	public function setApplicationVersion(?string $version): ClientConfig {
		$this->applicationVersion = $version === null || trim($version) === '' ? null : trim($version);
		return $this;
	}

	public function getApplicationVersion(): ?string {
		return $this->applicationVersion;
	}



	/**
	 * @param int $retries The number of times a failed GET request is retried, only when HTTPClient::$totalTimeout bounds
	 *             the retries (see HTTPClient). Event publishing (PUT) is never retried.
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

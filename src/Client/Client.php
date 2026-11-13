<?php

namespace ABSmartly\SDK\Client;

use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Http\HttpClientInterface;
use ABSmartly\SDK\Http\AsyncHttpClientInterface;
use ABSmartly\SDK\Http\HTTPClient;
use ABSmartly\SDK\PublishEvent;
use React\Promise\PromiseInterface;

use function json_decode;
use function json_encode;
use function React\Promise\reject;
use function React\Promise\resolve;
use function preg_match;
use function rtrim;

use const JSON_THROW_ON_ERROR;

class Client implements AsyncClientInterface {
	/** The SDK version, sent in the X-Agent header. Keep in step with the release tag (checked by the release workflow). */
	public const SDK_VERSION = '2.0.0';
	public const SDK_NAME = 'absmartly-php-sdk';
	/** @deprecated Use SDK_VERSION. */
	protected const VERSION = self::SDK_VERSION;
	private HttpClientInterface $httpClient;
	private string $url;
	private array $query;
	private array $headers;

	public function __construct(ClientConfig $clientConfig, ?HttpClientInterface $HTTPClient = null) {
		if (!$HTTPClient) {
			$HTTPClient = new HTTPClient();
		}
		$this->httpClient = $HTTPClient;

		if (property_exists($this->httpClient, 'timeout')) {
			$this->httpClient->timeout = $clientConfig->getTimeout();
		}
		if (property_exists($this->httpClient, 'retries')) {
			$this->httpClient->retries = $clientConfig->getRetries();
		}

		$this->url = rtrim($clientConfig->getEndpoint(), '/') .'/context';
		$this->query = [
			'application' => $clientConfig->getApplication(),
			'environment' => $clientConfig->getEnvironment(),
		];

		$this->headers = [
			'Content-Type' => 'application/json; charset=utf-8',
			'X-Agent' => self::SDK_NAME . '/' . static::VERSION,
			'X-API-Key' => $clientConfig->getApiKey(),
			'X-Environment' => $clientConfig->getEnvironment(),
			'X-Application' => $clientConfig->getApplication(),
			'X-Application-Version' => self::applicationVersionHeader($clientConfig->getApplicationVersion()),
		];
	}


	/**
	 * The collector parses X-Application-Version as a whole number and rejects the request otherwise, so a version
	 * that is not one (such as "2.4.1") is sent as "0", as when no version is configured.
	 */
	private static function applicationVersionHeader(?string $applicationVersion): string {
		if ($applicationVersion === null || preg_match('/^\d{1,18}$/D', $applicationVersion) !== 1) {
			return '0';
		}

		return $applicationVersion;
	}

	/** The value sent as the X-Agent header, "<name>/<version>", e.g. "absmartly-php-sdk/2.0.0". */
	public function getAgent(): string {
		return $this->headers['X-Agent'];
	}

	private function authRequest(): void {
		if (empty($this->headers['X-API-Key'])) {
			throw new \ABSmartly\SDK\Exception\RuntimeException(
				'API key is not configured. Please set a valid API key in ClientConfig.'
			);
		}
	}

	public function getApplication(): string {
		return $this->query['application'];
	}

	public function getEnvironment(): string {
		return $this->query['environment'];
	}

	public function getContextData(): ContextData {
		$this->authRequest();
		$response = $this->httpClient->get($this->url, $this->query, $this->headers);
		$decoded = $this->decode($response->content);
		return new ContextData($decoded->experiments);
	}

	public function getContextDataAsync(): PromiseInterface {
		if (!$this->httpClient instanceof AsyncHttpClientInterface) {
			try {
				return resolve($this->getContextData());
			} catch (\Throwable $e) {
				return reject($e);
			}
		}

		try {
			$this->authRequest();
		} catch (\Throwable $e) {
			return reject($e);
		}
		return $this->httpClient
			->getAsync($this->url, $this->query, $this->headers)
			->then(fn($response) => new ContextData($this->decode($response->content)->experiments));
	}

	public function publish(PublishEvent $publishEvent): void {
		$this->authRequest();
		$data = $this->encode($publishEvent);
		$this->httpClient->put($this->url, $this->query, $this->headers, $data);
	}

	public function publishAsync(PublishEvent $publishEvent): PromiseInterface {
		if (!$this->httpClient instanceof AsyncHttpClientInterface) {
			try {
				$this->publish($publishEvent);
				return resolve(null);
			} catch (\Throwable $e) {
				return reject($e);
			}
		}

		try {
			$this->authRequest();
			$data = $this->encode($publishEvent);
		} catch (\Throwable $e) {
			return reject($e);
		}
		return $this->httpClient->putAsync($this->url, $this->query, $this->headers, $data);
	}

	public function decode(string $jsonString): object {
		return json_decode($jsonString, false, 512, JSON_THROW_ON_ERROR);
	}

	public function encode(object $object): string {
		return json_encode($object, JSON_THROW_ON_ERROR);
	}

	public function close(): void {
		$this->httpClient->close();
	}

	public function isAsync(): bool {
		return $this->httpClient instanceof AsyncHttpClientInterface;
	}
}

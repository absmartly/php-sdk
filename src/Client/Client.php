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
use function React\Promise\resolve;
use function rtrim;

use const JSON_THROW_ON_ERROR;

class Client implements AsyncClientInterface {
	protected const VERSION = '1.0.3';
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
			'X-Agent' => 'absmartly-php-sdk/v'. static::VERSION,
			'X-API-Key' => $clientConfig->getApiKey(),
			'X-Environment' => $clientConfig->getEnvironment(),
			'X-Application' => $clientConfig->getApplication(),
			'X-Application-Version' => '0',
		];
	}


	private function authRequest(): void {
		if (empty($this->headers['X-API-Key'])) {
			throw new \ABSmartly\SDK\Exception\RuntimeException(
				'API key is not configured. Please set a valid API key in ClientConfig.'
			);
		}
	}

	public function getContextData(): ContextData {
		$this->authRequest();
		$response = $this->httpClient->get($this->url, $this->query, $this->headers);
		$decoded = $this->decode($response->content);
		return new ContextData($decoded->experiments);
	}

	public function getContextDataAsync(): PromiseInterface {
		if (!$this->httpClient instanceof AsyncHttpClientInterface) {
			return resolve($this->getContextData());
		}

		$this->authRequest();
		return $this->httpClient
			->getAsync($this->url, $this->query, $this->headers)
			->then(fn($response) => new ContextData($this->decode($response->content)->experiments));
	}

	public function publish(PublishEvent $publishEvent): void {
		$data = $this->encode($publishEvent);
		$this->httpClient->put($this->url, $this->query, $this->headers, $data);
	}

	public function publishAsync(PublishEvent $publishEvent): PromiseInterface {
		if (!$this->httpClient instanceof AsyncHttpClientInterface) {
			$this->publish($publishEvent);
			return resolve(null);
		}

		$data = $this->encode($publishEvent);
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

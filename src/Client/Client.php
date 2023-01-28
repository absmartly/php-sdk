<?php

namespace ABSmartly\SDK\Client;

use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Http\HTTPClient;
use ABSmartly\SDK\PublishEvent;

use function json_decode;
use function json_encode;
use function rtrim;

use const JSON_THROW_ON_ERROR;

class Client {
	protected const VERSION = '1.0';
	private HTTPClient $httpClient;
	private string $url;
	private array $query;
	private array $headers;

	public function __construct(ClientConfig $clientConfig, ?HTTPClient $HTTPClient = null) {
		if (!$HTTPClient) {
			$HTTPClient = new HTTPClient();
		}
		$this->httpClient = $HTTPClient;
		$this->httpClient->timeout = $clientConfig->getTimeout();
		$this->httpClient->retries = $clientConfig->getRetries();

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

	}

	public function getContextData(): ContextData {
		$this->authRequest();
		$response = $this->httpClient->get($this->url, $this->query, $this->headers);
		$response = $this->decode($response->content);
		return new ContextData($response->experiments);
	}

	public function publish(PublishEvent $publishEvent): void {
		$data = $this->encode($publishEvent);
		$this->httpClient->put($this->url, $this->query, $this->headers, $data);
	}

	public function decode(string $jsonString): object {
		return json_decode($jsonString, false, 16, JSON_THROW_ON_ERROR);
	}

	public function encode(object $object): string {
		return json_encode($object, JSON_THROW_ON_ERROR);
	}

	public function close(): void {
		$this->httpClient->close();
	}
}

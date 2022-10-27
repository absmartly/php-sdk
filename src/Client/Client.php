<?php

namespace Absmartly\SDK\Client;

use Absmartly\SDK\Context\ContextData;
use Absmartly\SDK\Http\HTTPClient;
use Absmartly\SDK\PublishEvent;

class Client {
	protected const VERSION = '1.0';
	private HTTPClient $httpClient;
	private string $url;
	private array $query;
	private array $headers;

	private $serializer;
	private $deserializer;
	private $executor;

	public function __construct(ClientConfig $clientConfig, ?HTTPClient $HTTPClient = null) {
		if (!$HTTPClient) {
			$HTTPClient = new HTTPClient();
		}
		$this->httpClient = $HTTPClient;

		$this->url = rtrim($clientConfig->getEndpoint(), '/') . "/context";
		$this->query = [
			"application" => $clientConfig->getApplication(),
			"environment" => $clientConfig->getEnvironment(),
		];

		$this->headers = [
			"Content-Type" => "application/json; charset=utf-8",
			"X-Agent" => "absmartly-php-sdk/v" . static::VERSION,
			"X-API-Key" => $clientConfig->getApiKey(),
			"X-Environment" => $clientConfig->getEnvironment(),
			"X-Application" => $clientConfig->getApplication(),
			"X-Application-Version" => "0",
		];
	}


	private function authRequest(): void {
		// Todo: attach API credentials.
	}

	public function getContextData(): ContextData {
		$this->authRequest();
		$response = $this->httpClient->get($this->url, $this->query, $this->headers);
		$response = $this->decode($response->content);
		return new ContextData($response->experiments);
	}

	public function publish(PublishEvent $publishEvent): void {
		$data = json_encode($publishEvent, JSON_THROW_ON_ERROR);
		$this->httpClient->put($this->url, [], $this->headers, $data);
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

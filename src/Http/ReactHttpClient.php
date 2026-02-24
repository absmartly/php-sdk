<?php

namespace ABSmartly\SDK\Http;

use React\Http\Browser;
use React\Promise\PromiseInterface;

use function http_build_query;
use function React\Async\await;
use function rtrim;
use function strpos;

class ReactHttpClient implements AsyncHttpClientInterface {
	private Browser $browser;
	public int $retries = 5;
	public int $timeout = 3000;

	public function __construct(?Browser $browser = null) {
		$this->browser = $browser ?? new Browser();
	}

	public function getAsync(string $url, array $query = [], array $headers = []): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		return $this->browser
			->withTimeout($this->timeout / 1000)
			->get($url, $this->flattenHeaders($headers))
			->then(fn($response) => $this->toResponse($response));
	}

	public function putAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		return $this->browser
			->withTimeout($this->timeout / 1000)
			->put($url, $this->flattenHeaders($headers), $body)
			->then(fn($response) => $this->toResponse($response));
	}

	public function postAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		return $this->browser
			->withTimeout($this->timeout / 1000)
			->post($url, $this->flattenHeaders($headers), $body)
			->then(fn($response) => $this->toResponse($response));
	}

	public function get(string $url, array $query = [], array $headers = []): Response {
		return await($this->getAsync($url, $query, $headers));
	}

	public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response {
		return await($this->putAsync($url, $query, $headers, $body));
	}

	public function post(string $url, array $query = [], array $headers = [], string $body = ''): Response {
		return await($this->postAsync($url, $query, $headers, $body));
	}

	public function close(): void {
	}

	private function buildUrl(string $url, array $query): string {
		if (!$query) {
			return $url;
		}
		$queryParams = http_build_query($query);
		return strpos($url, '?') === false
			? "$url?$queryParams"
			: rtrim($url, '&') . "&$queryParams";
	}

	private function flattenHeaders(array $headers): array {
		$flat = [];
		foreach ($headers as $key => $value) {
			$flat[] = "$key: $value";
		}
		return $flat;
	}

	private function toResponse($reactResponse): Response {
		$response = new Response();
		$response->status = $reactResponse->getStatusCode();
		$response->content = (string) $reactResponse->getBody();

		if ($response->status >= 400) {
			throw new HttpClientError(
				sprintf('HTTP Client returned an HTTP error %d: Response Body: %s',
					$response->status,
					$response->content
				)
			);
		}

		return $response;
	}
}

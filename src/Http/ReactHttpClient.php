<?php

namespace ABSmartly\SDK\Http;

use ABSmartly\SDK\Exception\HttpClientError;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

use function http_build_query;
use function max;
use function min;
use function pow;
use function preg_match;
use function sprintf;
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
		return $this->withRetries(fn() => $this->browser
			->withTimeout($this->timeout / 1000)
			->get($url, $this->flattenHeaders($headers))
			->then(fn($response) => $this->toResponse($response)));
	}

	public function putAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		return $this->withRetries(fn() => $this->browser
			->withTimeout($this->timeout / 1000)
			->put($url, $this->flattenHeaders($headers), $body)
			->then(fn($response) => $this->toResponse($response)));
	}

	public function postAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		return $this->withRetries(fn() => $this->browser
			->withTimeout($this->timeout / 1000)
			->post($url, $this->flattenHeaders($headers), $body)
			->then(fn($response) => $this->toResponse($response)));
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

	private function withRetries(callable $request): PromiseInterface {
		$maxAttempts = max(1, $this->retries);
		$current = null;
		$timer = null;
		$cancelled = false;

		return new Promise(
			function($resolve, $reject) use ($request, $maxAttempts, &$current, &$timer, &$cancelled) {
				$attempt = function(int $n) use (&$attempt, $request, $maxAttempts, $resolve, $reject, &$current, &$timer, &$cancelled) {
					if ($cancelled) {
						return;
					}
					$current = $request();
					$current->then($resolve, function($error) use (&$attempt, $n, $maxAttempts, $reject, &$timer, &$cancelled) {
						if ($cancelled) {
							return;
						}
						if (!$this->isRetryable($error) || $n >= $maxAttempts - 1) {
							$reject($error);
							return;
						}
						$backoffMs = min(1000 * pow(2, $n), 10000);
						error_log(sprintf(
							'ABsmartly SDK: Retrying HTTP request (attempt %d/%d) after %dms due to error: %s',
							$n + 1,
							$maxAttempts,
							$backoffMs,
							$error->getMessage()
						));
						$timer = Loop::addTimer($backoffMs / 1000, function() use (&$attempt, $n, &$timer) {
							$timer = null;
							$attempt($n + 1);
						});
					});
				};
				$attempt(0);
			},
			function() use (&$current, &$timer, &$cancelled) {
				$cancelled = true;
				if ($timer !== null) {
					Loop::cancelTimer($timer);
					$timer = null;
				}
				if ($current !== null) {
					$current->cancel();
				}
				throw new \RuntimeException('HTTP request cancelled');
			}
		);
	}

	private function isRetryable(\Throwable $error): bool {
		if ($error instanceof ResponseException) {
			$code = $error->getCode();
			return ($code >= 500 && $code < 600) || $code === 408 || $code === 429;
		}
		if ($error instanceof HttpClientError) {
			return preg_match('/HTTP error (5\d\d|408|429)\b/', $error->getMessage()) === 1;
		}
		return true;
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
		return $headers;
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

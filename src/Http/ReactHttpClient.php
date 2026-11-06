<?php

namespace ABSmartly\SDK\Http;

use ABSmartly\SDK\Exception\HttpClientError;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

use function http_build_query;
use function microtime;
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
		return $this->withRetries(fn(float $timeoutSeconds) => $this->browser
			->withTimeout($timeoutSeconds)
			->get($url, $this->flattenHeaders($headers))
			->then(fn($response) => $this->toResponse($response)));
	}

	public function putAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		// Sent once: replaying a publish after a lost response could record the same events twice.
		return $this->browser
			->withTimeout($this->timeoutSeconds())
			->put($url, $this->flattenHeaders($headers), $body)
			->then(fn($response) => $this->toResponse($response));
	}

	public function postAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		return $this->browser
			->withTimeout($this->timeoutSeconds())
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

	/**
	 * A $timeout of 0 or less means no limit, as in HTTPClient; React's Browser takes a negative timeout as "none".
	 */
	private function timeoutSeconds(): float {
		return $this->timeout > 0 ? $this->timeout / 1000 : -1;
	}

	private function nowMs(): float {
		return microtime(true) * 1000;
	}

	/**
	 * Runs a GET, retrying transport errors and HTTP 5xx, 408 and 429 responses up to $retries times after the first
	 * attempt, as HTTPClient does. $timeout bounds the whole sequence: each attempt only gets the time that is left,
	 * and no retry starts once the remaining time cannot cover its back-off.
	 *
	 * @param callable(float): PromiseInterface $request called with the attempt's timeout in seconds
	 */
	private function withRetries(callable $request): PromiseInterface {
		$maxAttempts = 1 + max(0, $this->retries);
		$hasDeadline = $this->timeout > 0;
		$deadline = $this->nowMs() + $this->timeout;
		$current = null;
		$timer = null;
		$cancelled = false;

		return new Promise(
			function($resolve, $reject) use ($request, $maxAttempts, $hasDeadline, $deadline, &$current, &$timer, &$cancelled) {
				$attempt = function(int $n) use (&$attempt, $request, $maxAttempts, $hasDeadline, $deadline, $resolve, $reject, &$current, &$timer, &$cancelled) {
					if ($cancelled) {
						return;
					}
					$timeoutSeconds = $hasDeadline ? max(0.001, ($deadline - $this->nowMs()) / 1000) : -1;
					$current = $request($timeoutSeconds);
					$current->then($resolve, function($error) use (&$attempt, $n, $maxAttempts, $hasDeadline, $deadline, $reject, &$timer, &$cancelled) {
						if ($cancelled) {
							return;
						}
						$backoffMs = min(1000 * pow(2, $n), 10000);
						$outOfTime = $hasDeadline && $this->nowMs() + $backoffMs >= $deadline;
						if (!$this->isRetryable($error) || $n + 1 >= $maxAttempts || $outOfTime) {
							$reject($error);
							return;
						}
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

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
	/**
	 * Per-attempt timeout in milliseconds. It bounds each individual request, not the whole retry sequence; use
	 * totalTimeout for that. 0 or less means no per-attempt limit.
	 */
	public int $timeout = 3000;
	/**
	 * Optional total budget in milliseconds for the whole request, including retries and back-off. When greater
	 * than 0 it bounds the entire retry sequence; 0 (the default) means no total limit.
	 */
	public int $totalTimeout = 0;

	public function __construct(?Browser $browser = null) {
		$this->browser = $browser ?? new Browser();
	}

	public function getAsync(string $url, array $query = [], array $headers = []): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		return $this->withRetries(fn(float $timeoutSeconds) => $this->browser
			->withTimeout($timeoutSeconds)
			->get($url, $headers)
			->then(fn($response) => $this->toResponse($response)));
	}

	public function putAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		// Sent once: replaying a publish after a lost response could record the same events twice.
		return $this->singleAttempt(fn(float $timeoutSeconds) => $this->browser
			->withTimeout($timeoutSeconds)
			->put($url, $headers, $body)
			->then(fn($response) => $this->toResponse($response)));
	}

	public function postAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
		$url = $this->buildUrl($url, $query);
		return $this->singleAttempt(fn(float $timeoutSeconds) => $this->browser
			->withTimeout($timeoutSeconds)
			->post($url, $headers, $body)
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

	/**
	 * The timeout (in seconds) to apply to a single attempt. The per-attempt $timeout bounds each request as in
	 * HTTPClient (0 or less means no per-attempt limit; React's Browser takes a negative timeout as "none"). When a
	 * total budget ($totalTimeout) is set, the current attempt is additionally bounded by the time remaining before
	 * the deadline, so a slow attempt cannot run past the budget.
	 */
	private function effectiveTimeoutSeconds(bool $hasDeadline, float $deadline): float {
		$perAttempt = $this->timeout > 0 ? $this->timeout / 1000 : -1.0;
		if (!$hasDeadline) {
			return $perAttempt;
		}
		$remaining = ($deadline - $this->nowMs()) / 1000;
		if ($remaining < 0) {
			$remaining = 0.0;
		}
		return $perAttempt < 0 ? $remaining : min($perAttempt, $remaining);
	}

	private function nowMs(): float {
		return microtime(true) * 1000;
	}

	/**
	 * Runs a request once, bounding it by the per-attempt $timeout and, when a total budget ($totalTimeout) is set,
	 * by the remaining budget. A deadline watchdog rejects and cancels the in-flight request if it has not settled by
	 * the deadline, so single-attempt methods (PUT/POST) honour the total budget too.
	 *
	 * @param callable(float): PromiseInterface $request
	 */
	private function singleAttempt(callable $request): PromiseInterface {
		$hasDeadline = $this->totalTimeout > 0;
		$deadline = $this->nowMs() + $this->totalTimeout;
		$current = null;
		$deadlineTimer = null;
		$cancelled = false;

		return new Promise(
			function($resolve, $reject) use ($request, $hasDeadline, $deadline, &$current, &$deadlineTimer, &$cancelled) {
				$clear = function() use (&$deadlineTimer) {
					if ($deadlineTimer !== null) {
						Loop::cancelTimer($deadlineTimer);
						$deadlineTimer = null;
					}
				};
				if ($hasDeadline) {
					$remaining = ($deadline - $this->nowMs()) / 1000;
					$deadlineTimer = Loop::addTimer(max(0, $remaining), function() use (&$current, &$cancelled, $reject) {
						if ($cancelled) {
							return;
						}
						$cancelled = true;
						if ($current !== null) {
							$current->cancel();
						}
						$reject(new \RuntimeException('HTTP request exceeded total timeout'));
					});
				}
				$current = $request($this->effectiveTimeoutSeconds($hasDeadline, $deadline));
				$current->then(
					function($response) use ($resolve, $reject, $clear, $hasDeadline, $deadline, &$cancelled) {
						if ($cancelled) {
							return;
						}
						$cancelled = true;
						$clear();
						if ($hasDeadline && $this->nowMs() >= $deadline) {
							$reject(new \RuntimeException('HTTP request exceeded total timeout'));
							return;
						}
						$resolve($response);
					},
					function($error) use ($reject, $clear, &$cancelled) {
						if ($cancelled) {
							return;
						}
						$clear();
						$reject($error);
					}
				);
			},
			function() use (&$current, &$deadlineTimer, &$cancelled) {
				$cancelled = true;
				if ($deadlineTimer !== null) {
					Loop::cancelTimer($deadlineTimer);
					$deadlineTimer = null;
				}
				if ($current !== null) {
					$current->cancel();
				}
				throw new \RuntimeException('HTTP request cancelled');
			}
		);
	}

	/**
	 * Runs a GET, retrying transport errors and HTTP 5xx, 408 and 429 responses up to $retries times after the first
	 * attempt, as HTTPClient does. When a total budget ($totalTimeout) is set, it bounds the whole sequence: no retry
	 * starts once the remaining time cannot cover its back-off, each attempt is bounded by the remaining budget, and a
	 * watchdog rejects and cancels an in-flight attempt at the deadline. The per-attempt $timeout always applies.
	 *
	 * @param callable(float): PromiseInterface $request
	 */
	private function withRetries(callable $request): PromiseInterface {
		$maxAttempts = 1 + max(0, $this->retries);
		$hasDeadline = $this->totalTimeout > 0;
		$deadline = $this->nowMs() + $this->totalTimeout;
		$current = null;
		$timer = null;
		$deadlineTimer = null;
		$cancelled = false;

		return new Promise(
			function($resolve, $reject) use ($request, $maxAttempts, $hasDeadline, $deadline, &$current, &$timer, &$deadlineTimer, &$cancelled) {
				$clearDeadline = function() use (&$deadlineTimer) {
					if ($deadlineTimer !== null) {
						Loop::cancelTimer($deadlineTimer);
						$deadlineTimer = null;
					}
				};
				if ($hasDeadline) {
					$remaining = ($deadline - $this->nowMs()) / 1000;
					$deadlineTimer = Loop::addTimer(max(0, $remaining), function() use (&$current, &$timer, &$cancelled, $reject) {
						if ($cancelled) {
							return;
						}
						$cancelled = true;
						if ($timer !== null) {
							Loop::cancelTimer($timer);
							$timer = null;
						}
						if ($current !== null) {
							$current->cancel();
						}
						$reject(new \RuntimeException('HTTP request exceeded total timeout'));
					});
				}
				$expire = function() use ($reject, $clearDeadline, &$current, &$timer, &$cancelled) {
					$cancelled = true;
					$clearDeadline();
					if ($timer !== null) {
						Loop::cancelTimer($timer);
						$timer = null;
					}
					if ($current !== null) {
						$current->cancel();
					}
					$reject(new \RuntimeException('HTTP request exceeded total timeout'));
				};
				$attempt = function(int $n) use (&$attempt, $request, $maxAttempts, $hasDeadline, $deadline, $resolve, $reject, $clearDeadline, $expire, &$current, &$timer, &$cancelled) {
					if ($cancelled) {
						return;
					}
					if ($hasDeadline && $this->nowMs() >= $deadline) {
						$expire();
						return;
					}
					$current = $request($this->effectiveTimeoutSeconds($hasDeadline, $deadline));
					$current->then(
						function($response) use ($resolve, $clearDeadline, $expire, $hasDeadline, $deadline, &$cancelled) {
							if ($cancelled) {
								return;
							}
							if ($hasDeadline && $this->nowMs() >= $deadline) {
								$expire();
								return;
							}
							$clearDeadline();
							$resolve($response);
						},
						function($error) use (&$attempt, $n, $maxAttempts, $hasDeadline, $deadline, $reject, $clearDeadline, &$timer, &$cancelled) {
							if ($cancelled) {
								return;
							}
							$backoffMs = min(1000 * pow(2, $n), 10000);
							$outOfTime = $hasDeadline && $this->nowMs() + $backoffMs >= $deadline;
							if (!$this->isRetryable($error) || $n + 1 >= $maxAttempts || $outOfTime) {
								$clearDeadline();
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
						}
					);
				};
				$attempt(0);
			},
			function() use (&$current, &$timer, &$deadlineTimer, &$cancelled) {
				$cancelled = true;
				if ($timer !== null) {
					Loop::cancelTimer($timer);
					$timer = null;
				}
				if ($deadlineTimer !== null) {
					Loop::cancelTimer($deadlineTimer);
					$deadlineTimer = null;
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

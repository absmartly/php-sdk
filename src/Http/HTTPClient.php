<?php /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */

namespace ABSmartly\SDK\Http;

use ABSmartly\SDK\Exception\HttpClientError;
use CurlHandle;

use function curl_close;
use function curl_errno;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function curl_setopt_array;
use function curl_strerror;
use function ceil;
use function hrtime;
use function http_build_query;
use function max;
use function rtrim;
use function min;
use function sprintf;
use function strpos;
use function usleep;

use const CURL_SSLVERSION_TLSv1_2;
use const PHP_VERSION_ID;
use const CURLE_HTTP_RETURNED_ERROR;
use const CURLINFO_EFFECTIVE_URL;
use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_MAXREDIRS;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_REDIR_PROTOCOLS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_SSLVERSION;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const CURLPROTO_HTTPS;

class HTTPClient implements HttpClientInterface {
	/**
	 * @var CurlHandle|resource
	 */
	private $curlHandle;
	/**
	 * Number of times a request is retried after a transport error or an HTTP 5xx, 408 or 429 response.
	 */
	public int $retries = 5;
	/**
	 * Timeout in milliseconds for the complete request, including retries and backoff.
	 */
	public int $timeout = 3000;

	private const RETRY_BASE_DELAY_MS = 50;
	private const RETRY_MAX_DELAY_MS = 1000;
	// 50ms << 5 already exceeds the 1s cap; capping the shift keeps it from overflowing on large retry counts.
	private const RETRY_MAX_SHIFT = 5;

	private function setupRequest(string $url, array $query = [], array $headers = [], string $type = 'GET', ?string $data = null): void {
		$this->curlInit();
		$flatHeaders = [];
		foreach ($headers as $header => $value) {
			$flatHeaders[] = "$header: $value";
		}

		if ($query) {
			$queryParams = http_build_query($query);
			if (strpos($url, '?') === false) {
				$url .= '?';
			}
			else {
				$url = rtrim($url, '&') . '&';
			}

			$url .= $queryParams;
		}

		curl_setopt($this->curlHandle, CURLOPT_URL, $url);
		curl_setopt($this->curlHandle, CURLOPT_HTTPHEADER, $flatHeaders);
		curl_setopt($this->curlHandle, CURLOPT_CUSTOMREQUEST, $type);
		curl_setopt($this->curlHandle, CURLOPT_POSTFIELDS, $data);
	}

	/**
	 * Sends the request, retrying transport errors and HTTP 5xx, 408 and 429 responses. $timeout bounds the whole sequence: each
	 * attempt only gets the time that is left, and no retry starts once the remaining time cannot cover its back-off.
	 * A $timeout of 0 or less means no limit.
	 */
	private function fetchResponse(): Response {
		$hasDeadline = $this->timeout > 0;
		$deadline = $this->nowMs() + $this->timeout;
		$attempt = 0;
		while (true) {
			// cURL treats a timeout of 0 as "no timeout", so never pass less than 1ms when a deadline applies.
			$attemptTimeout = $hasDeadline ? max(1, (int) ceil($deadline - $this->nowMs())) : 0;
			$returnedResponse = $this->transfer($attemptTimeout);

			if ($attempt >= $this->retries || !$this->isRetryable()) {
				break;
			}

			$delay = min(self::RETRY_BASE_DELAY_MS << min($attempt, self::RETRY_MAX_SHIFT), self::RETRY_MAX_DELAY_MS);
			if ($hasDeadline && $deadline - $this->nowMs() <= $delay) {
				break;
			}

			$this->sleepMs($delay);
			++$attempt;
		}

		$this->throwOnError($returnedResponse === false ? null : $returnedResponse);

		$response = new Response();
		$response->content = (string) $returnedResponse;
		$response->status = (int) curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);

		return $response;
	}

	public function get(string $url, array $query = [], array $headers = []): Response {
		$this->setupRequest($url, $query, $headers);
		return $this->fetchResponse();
	}

	public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response {
		$this->setupRequest($url, $query, $headers, 'PUT', $body);
		return $this->fetchResponse();
	}

	public function post(string $url, array $query = [], array $headers = [], string $body = ''): Response {
		$this->setupRequest($url, $query, $headers, 'POST', $body);
		return $this->fetchResponse();
	}

	/**
	 * @return string|false
	 */
	protected function transfer(int $timeoutMs) {
		curl_setopt($this->curlHandle, CURLOPT_CONNECTTIMEOUT_MS, $timeoutMs);
		curl_setopt($this->curlHandle, CURLOPT_TIMEOUT_MS, $timeoutMs);

		return curl_exec($this->curlHandle);
	}

	protected function nowMs(): float {
		return hrtime(true) / 1e6;
	}

	protected function sleepMs(int $milliseconds): void {
		usleep($milliseconds * 1000);
	}

	protected function isRetryable(): bool {
		if (curl_errno($this->curlHandle) !== 0) {
			return true;
		}

		$status = (int) curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
		return $status >= 500 || $status === 408 || $status === 429;
	}

	private function throwOnError(?string $responseBody): void {
		$http_status = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);

		if ($http_status >= 400) {
			throw new HttpClientError(
				sprintf('HTTP Client returned an HTTP error %d for URL %s: Response Body: %s',
					curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE),
					curl_getinfo($this->curlHandle, CURLINFO_EFFECTIVE_URL),
					$responseBody,
				)
			);
		}

		if ($error = curl_errno($this->curlHandle)) {
			throw new HttpClientError(sprintf('HTTP Client returned error %d: %s', $error, curl_strerror($error)));
		}
	}

	private function curlInit(): void {
		if (isset($this->curlHandle)) {
			return;
		}
		$this->curlHandle = curl_init();

		if ($this->curlHandle === false) {
			throw new HttpClientError('Failed to initialize cURL. Is the curl extension loaded?');
		}

		// https://php.watch/articles/php-curl-security-hardening
		curl_setopt_array($this->curlHandle, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, // Always use HTTPS, never HTTP
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
		]);
	}

	public function close(): void {
		// curl_close() is a no-op since PHP 8.0 and deprecated since 8.5.
		if (isset($this->curlHandle) && PHP_VERSION_ID < 80000) {
			curl_close($this->curlHandle);
		}
	}

	public function __destruct() {
		$this->close();
	}
}

<?php

namespace Absmartly\SDK\Http;

use Absmartly\SDK\Exception\HttpClientError;
use CurlHandle;

class HTTPClient {
	/**
	 * @var CurlHandle|resource
	 */
	private $curlHandle;

	protected function setupRequest(string $url, array $query = [], array $headers = [], string $type = 'GET', string $data = null): void {
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

	private function fetchResponse(): Response {
		$returnedResponse = curl_exec($this->curlHandle);
		$this->throwOnError();

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

	protected function throwOnError(): void {
		if ($error = curl_errno($this->curlHandle)) {
			if ($error === CURLE_HTTP_RETURNED_ERROR) {
				throw new HttpClientError(
					sprintf('HTTP Client returned an HTTP error %d for URL %s',
				        curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE),
				        curl_getinfo($this->curlHandle,  CURLINFO_EFFECTIVE_URL))
				);
			}

			throw new HttpClientError(sprintf('HTTP Client returned error %d: %s', $error, curl_strerror($error)));
		}
	}

	public function curlInit(): void {
		if (isset($this->curlHandle)) {
			return;
		}
		$this->curlHandle = curl_init();

		// https://php.watch/articles/php-curl-security-hardening
		curl_setopt_array($this->curlHandle, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, // Always use HTTPS, never HTTP
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
			CURLOPT_FAILONERROR => true, // So Curl fails on status codes >= 400
		]);
	}

	public function close(): void {
		if (isset($this->curlHandle)) {
			curl_close($this->curlHandle);
		}
	}

	public function __destruct() {
		$this->close();
	}
}

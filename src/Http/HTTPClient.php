<?php

namespace Absmartly\SDK\Http;

class HTTPClient {
	/**
	 * @var \CurlHandle|resource
	 */
	private $curlHandle;

	public function get(string $url, array $query = [], array $headers = []): Response {

	}


	public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response {

	}

	public function post(string $url, array $query = [], array $headers = [], string $body = ''): Response {

	}

	public function curlInit(): void {
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
		]);
	}

	public function close(): void {
		curl_close($this->curlHandle);
	}

	public function __destruct() {
		$this->close();
	}
}

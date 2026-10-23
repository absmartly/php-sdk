<?php

namespace ABSmartly\SDK\Http;

use React\Promise\PromiseInterface;

interface AsyncHttpClientInterface extends HttpClientInterface {
	public function getAsync(string $url, array $query = [], array $headers = []): PromiseInterface;
	public function putAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface;
	public function postAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface;
}

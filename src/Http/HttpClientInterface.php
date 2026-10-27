<?php

namespace ABSmartly\SDK\Http;

interface HttpClientInterface {
	public function get(string $url, array $query = [], array $headers = []): Response;
	public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response;
	public function post(string $url, array $query = [], array $headers = [], string $body = ''): Response;
	public function close(): void;
}

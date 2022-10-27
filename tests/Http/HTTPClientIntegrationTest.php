<?php

namespace Absmartly\SDK\Tests\Http;

use Absmartly\SDK\Exception\HttpClientError;
use Absmartly\SDK\Http\HTTPClient;
use Absmartly\SDK\Http\Response;
use PHPUnit\Framework\TestCase;

class HTTPClientIntegrationTest extends TestCase {
	public function testErrorsThrow(): void {
		$client = new HTTPClient();
		$this->expectException(HttpClientError::class);
		$client->get('https://httpbin.org/status/401');
	}

	public function testReturnsResponseObjects(): void {
		$client = new HTTPClient();
		$response = $client->get('https://httpbin.org/status/200');
		self::assertInstanceOf(Response::class, $response);
		self::assertSame(200, $response->status);
	}

	public function testPassesHeaders(): void {
		$client = new HTTPClient();
		$response = $client->get('https://httpbin.org/headers');
		self::assertInstanceOf(Response::class, $response);

		$response = $response->content;
		$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
		self::assertArrayNotHasKey('test', $response);

		$response = $client->get('https://httpbin.org/headers', [], ['test' => 5]);
		self::assertInstanceOf(Response::class, $response);

		$response = $response->content;
		$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
		self::assertArrayHasKey('headers', $response);
		self::assertArrayHasKey('Test', $response['headers']);
		self::assertEquals(5, $response['headers']['Test']);
	}

	public function testPassesParameters(): void {
		$client = new HTTPClient();
		$response = $client->get('https://httpbin.org/anything', ['x' => 'y'], ['a' => 'b']);
		$response = $response->content;
		$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

		self::assertSame('https://httpbin.org/anything?x=y', $response['url']);

		$response = $client->get('https://httpbin.org/anything?x=y', ['p' => 'q'], ['a' => 'b']);
		$response = $response->content;
		$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

		self::assertSame('https://httpbin.org/anything?x=y&p=q', $response['url']);
	}

	public function testPostRequest(): void {
		$client = new HTTPClient();
		$response = $client->post('https://httpbin.org/anything', ['x' => 'y'], ['a' => 'b'], 'body_test=foo');

		$response = $response->content;
		$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

		self::assertArrayHasKey('method', $response);
		self::assertSame('POST', $response['method']);

		self::assertArrayHasKey('form', $response);
		self::assertArrayHasKey('body_test', $response['form']);
		self::assertSame('foo', $response['form']['body_test']);
	}

	public function testPutRequest(): void {
		$client = new HTTPClient();
		$response = $client->put('https://httpbin.org/anything', ['x' => 'y'], ['a' => 'b'], 'body_test=foo');

		$response = $response->content;
		$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

		self::assertArrayHasKey('method', $response);
		self::assertSame('PUT', $response['method']);

		self::assertArrayHasKey('form', $response);
		self::assertArrayHasKey('body_test', $response['form']);
		self::assertSame('foo', $response['form']['body_test']);
	}

	public function testThrowsOnHttpError(): void {
		$client = new HTTPClient();
		$this->expectException(HttpClientError::class);
		$this->expectExceptionMessage('HTTP Client returned an HTTP error 404 for URL https://httpbin.org/status/404');
		$client->put('https://httpbin.org/status/404');
	}

	public function testThrowsOnCurlError(): void {
		$client = new HTTPClient();
		$this->expectException(HttpClientError::class);
		$this->expectExceptionMessage('HTTP Client returned error 1: Unsupported protocol');
		$client->put('hxxps://httpbin.org/status/404');
	}
}

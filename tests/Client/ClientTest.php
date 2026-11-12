<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Http\HttpClientInterface;
use ABSmartly\SDK\Http\Response;
use ABSmartly\SDK\PublishEvent;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase {
	public function testDecodeHandsDeeplyNestedJson(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$client = new Client($clientConfig);

		$nested = '{"a":{"b":{"c":{"d":{"e":{"f":{"g":{"h":{"i":{"j":{"k":{"l":{"m":{"n":{"o":{"p":{"q":"deep"}}}}}}}}}}}}}}}}}';
		$result = $client->decode($nested);

		self::assertSame('deep', $result->a->b->c->d->e->f->g->h->i->j->k->l->m->n->o->p->q);
	}

	public function testDecodeThrowsOnInvalidJson(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$client = new Client($clientConfig);

		$this->expectException(\JsonException::class);
		$client->decode('invalid json');
	}

	private function recordingHttpClient(): HttpClientInterface {
		return new class implements HttpClientInterface {
			public array $headers = [];

			public function get(string $url, array $query = [], array $headers = []): Response {
				$this->headers[] = $headers;
				$response = new Response();
				$response->status = 200;
				$response->content = '{"experiments":[]}';
				return $response;
			}

			public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response {
				$this->headers[] = $headers;
				$response = new Response();
				$response->status = 200;
				$response->content = '';
				return $response;
			}

			public function post(string $url, array $query = [], array $headers = [], string $body = ''): Response {
				return $this->put($url, $query, $headers, $body);
			}

			public function close(): void {
			}
		};
	}

	public function testXAgentCarriesTheSdkNameAndVersionOnFetchAndPublish(): void {
		$http = $this->recordingHttpClient();
		$client = new Client(new ClientConfig('endpoint', 'key', 'app', 'env'), $http);

		$client->getContextData();
		$client->publish(new PublishEvent());

		self::assertSame('absmartly-php-sdk/' . Client::SDK_VERSION, $client->getAgent());
		self::assertMatchesRegularExpression('#^absmartly-php-sdk/\d+\.\d+\.\d+#', $client->getAgent());
		self::assertCount(2, $http->headers);
		foreach ($http->headers as $headers) {
			self::assertSame($client->getAgent(), $headers['X-Agent']);
		}
	}

	public function applicationVersionProvider(): array {
		return [
			'not set' => [null, '0'],
			'whole number' => ['42', '42'],
			'trimmed whole number' => [' 7 ', '7'],
			'semantic version' => ['2.4.1', '0'],
			'blank' => ['  ', '0'],
			'too long for the collector' => ['1234567890123456789', '0'],
		];
	}

	/**
	 * @dataProvider applicationVersionProvider
	 */
	public function testApplicationVersionHeader(?string $version, string $expected): void {
		$http = $this->recordingHttpClient();
		$config = new ClientConfig('endpoint', 'key', 'app', 'env');
		if ($version !== null) {
			$config->setApplicationVersion($version);
		}

		(new Client($config, $http))->getContextData();

		self::assertSame($expected, $http->headers[0]['X-Application-Version']);
	}
}

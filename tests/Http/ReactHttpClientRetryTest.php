<?php

namespace ABSmartly\SDK\Tests\Http;

use ABSmartly\SDK\Http\ReactHttpClient;
use PHPUnit\Framework\TestCase;
use React\Http\Browser;
use React\Http\Message\Response as ReactResponse;

use function React\Promise\reject;
use function React\Promise\resolve;

class ReactHttpClientRetryTest extends TestCase {
	private function browser(array $results): Browser {
		$browser = $this->createMock(Browser::class);
		$browser->method('withTimeout')->willReturnSelf();
		$browser->expects(self::exactly(count($results)))->method('get')
			->willReturnOnConsecutiveCalls(...$results);
		return $browser;
	}

	public function testRetriesNetworkFailureThenSucceeds(): void {
		$client = new ReactHttpClient($this->browser([
			reject(new \RuntimeException('Connection refused')),
			resolve(new ReactResponse(200, [], 'ok')),
		]));
		$client->retries = 2;

		$result = null;
		$client->getAsync('http://x')->then(function($r) use (&$result) { $result = $r; });
		\React\EventLoop\Loop::run();

		self::assertSame('ok', $result->content);
	}

	public function testDoesNotRetryClientErrors(): void {
		$client = new ReactHttpClient($this->browser([
			resolve(new ReactResponse(404, [], 'nf')),
		]));
		$client->retries = 3;

		$error = null;
		$client->getAsync('http://x')->then(null, function($e) use (&$error) { $error = $e; });

		self::assertInstanceOf(\ABSmartly\SDK\Exception\HttpClientError::class, $error);
	}
}

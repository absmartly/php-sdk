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

	public function testCancellingDuringBackoffPreventsFurtherRequests(): void {
		$client = new ReactHttpClient($this->browser([
			reject(new \RuntimeException('Connection refused')),
		]));
		$client->retries = 3;

		$promise = $client->getAsync('http://x');
		$error = null;
		$promise->then(null, function($e) use (&$error) { $error = $e; });
		$promise->cancel();

		\React\EventLoop\Loop::addTimer(1.3, fn() => \React\EventLoop\Loop::stop());
		\React\EventLoop\Loop::run();

		self::assertInstanceOf(\RuntimeException::class, $error);
	}

	private function failingBrowser(string $method, int $calls): Browser {
		$browser = $this->createMock(Browser::class);
		$browser->method('withTimeout')->willReturnSelf();
		$browser->expects(self::exactly($calls))->method($method)
			->willReturn(reject(new \RuntimeException('Connection refused')));
		return $browser;
	}

	public function testPutIsNeverRetried(): void {
		$client = new ReactHttpClient($this->failingBrowser('put', 1));
		$client->retries = 3;

		$error = null;
		$client->putAsync('http://x', [], [], '{}')->then(null, function($e) use (&$error) { $error = $e; });
		\React\EventLoop\Loop::run();

		self::assertInstanceOf(\RuntimeException::class, $error);
	}

	public function testPostIsNeverRetried(): void {
		$client = new ReactHttpClient($this->failingBrowser('post', 1));
		$client->retries = 3;

		$error = null;
		$client->postAsync('http://x', [], [], '{}')->then(null, function($e) use (&$error) { $error = $e; });
		\React\EventLoop\Loop::run();

		self::assertInstanceOf(\RuntimeException::class, $error);
	}

	public function testGetMakesOneAttemptPlusConfiguredRetries(): void {
		$client = new ReactHttpClient($this->failingBrowser('get', 2));
		$client->retries = 1;

		$error = null;
		$client->getAsync('http://x')->then(null, function($e) use (&$error) { $error = $e; });
		\React\EventLoop\Loop::run();

		self::assertInstanceOf(\RuntimeException::class, $error);
	}

	public function testTimeoutBoundsRetriesAndBackoff(): void {
		// The first back-off (1s) does not fit in a 500ms total timeout, so no retry starts.
		$client = new ReactHttpClient($this->failingBrowser('get', 1));
		$client->retries = 5;
		$client->timeout = 500;

		$error = null;
		$client->getAsync('http://x')->then(null, function($e) use (&$error) { $error = $e; });
		\React\EventLoop\Loop::run();

		self::assertInstanceOf(\RuntimeException::class, $error);
	}
}

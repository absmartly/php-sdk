<?php

namespace ABSmartly\SDK\Tests\Http;

use ABSmartly\SDK\Http\ReactHttpClient;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\Response as ReactResponse;
use React\Promise\Deferred;

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

	public function testTotalTimeoutBoundsRetriesAndBackoff(): void {
		// The first back-off (1s) does not fit in a 500ms total budget, so no retry starts.
		$client = new ReactHttpClient($this->failingBrowser('get', 1));
		$client->retries = 5;
		$client->totalTimeout = 500;

		$error = null;
		$client->getAsync('http://x')->then(null, function($e) use (&$error) { $error = $e; });
		\React\EventLoop\Loop::run();

		self::assertInstanceOf(\RuntimeException::class, $error);
	}

	/**
	 * A browser whose $method returns a promise that resolves only after $delaySeconds on the event loop, letting a
	 * test observe whether totalTimeout aborts an in-flight (slow but otherwise successful) request.
	 */
	private function slowBrowser(string $method, float $delaySeconds): Browser {
		$browser = $this->createMock(Browser::class);
		$browser->method('withTimeout')->willReturnSelf();
		$browser->expects(self::once())->method($method)->willReturnCallback(
			function() use ($delaySeconds) {
				$deferred = new Deferred();
				Loop::addTimer($delaySeconds, function() use ($deferred) {
					$deferred->resolve(new ReactResponse(200, [], 'ok'));
				});
				return $deferred->promise();
			}
		);
		return $browser;
	}

	public function testTotalTimeoutAbortsSlowSuccessfulGet(): void {
		// A 200ms successful GET must be aborted when totalTimeout is 50ms: the active attempt, not only the
		// post-rejection back-off, is bounded by the remaining budget.
		$client = new ReactHttpClient($this->slowBrowser('get', 0.2));
		$client->retries = 0;
		$client->totalTimeout = 50;

		$result = null;
		$error = null;
		$client->getAsync('http://x')->then(
			function($r) use (&$result) { $result = $r; },
			function($e) use (&$error) { $error = $e; }
		);
		Loop::addTimer(0.5, fn() => Loop::stop());
		Loop::run();

		self::assertNull($result);
		self::assertInstanceOf(\RuntimeException::class, $error);
		self::assertStringContainsString('total timeout', $error->getMessage());
	}

	public function testOverdueRetryAfterBlockedLoopDoesNotRunOrSucceed(): void {
		$browser = $this->createMock(Browser::class);
		$browser->method('withTimeout')->willReturnSelf();
		$calls = 0;
		$browser->method('get')->willReturnCallback(function() use (&$calls) {
			$calls++;
			return $calls === 1
				? reject(new \RuntimeException('Connection refused'))
				: resolve(new ReactResponse(200, [], 'late'));
		});
		$client = new ReactHttpClient($browser);
		$client->retries = 1;
		$client->totalTimeout = 1500;

		$result = null;
		$error = null;
		$client->getAsync('http://x')->then(
			function($r) use (&$result) { $result = $r; },
			function($e) use (&$error) { $error = $e; }
		);
		// The application blocks the loop past both the 1s back-off and the 1.5s budget.
		Loop::addTimer(0.01, fn() => usleep(1600000));
		Loop::addTimer(3.0, fn() => Loop::stop());
		Loop::run();

		self::assertSame(1, $calls);
		self::assertNull($result);
		self::assertInstanceOf(\RuntimeException::class, $error);
		self::assertStringContainsString('total timeout', $error->getMessage());
	}

	public function testOverduePutResponseAfterBlockedLoopIsATimeout(): void {
		$browser = $this->createMock(Browser::class);
		$browser->method('withTimeout')->willReturnSelf();
		$deferred = new \React\Promise\Deferred();
		$browser->expects(self::once())->method('put')->willReturn($deferred->promise());
		Loop::addTimer(0.01, fn() => $deferred->resolve(new ReactResponse(200, [], 'late')));
		$client = new ReactHttpClient($browser);
		$client->totalTimeout = 50;

		$result = null;
		$error = null;
		$client->putAsync('http://x', [], [], '{}')->then(
			function($r) use (&$result) { $result = $r; },
			function($e) use (&$error) { $error = $e; }
		);
		// Block the loop past the 50ms budget before the 10ms response is delivered.
		Loop::addTimer(0.001, fn() => usleep(100000));
		Loop::addTimer(0.5, fn() => Loop::stop());
		Loop::run();

		self::assertNull($result);
		self::assertInstanceOf(\RuntimeException::class, $error);
		self::assertStringContainsString('total timeout', $error->getMessage());
	}

	public function testTotalTimeoutAbortsSlowSuccessfulPut(): void {
		// Single-attempt PUT must honour totalTimeout too: a 200ms success is aborted at the 50ms deadline.
		$client = new ReactHttpClient($this->slowBrowser('put', 0.2));
		$client->totalTimeout = 50;

		$result = null;
		$error = null;
		$client->putAsync('http://x', [], [], '{}')->then(
			function($r) use (&$result) { $result = $r; },
			function($e) use (&$error) { $error = $e; }
		);
		Loop::addTimer(0.5, fn() => Loop::stop());
		Loop::run();

		self::assertNull($result);
		self::assertInstanceOf(\RuntimeException::class, $error);
		self::assertStringContainsString('total timeout', $error->getMessage());
	}

	public function testSlowSuccessfulGetWithinTotalTimeoutResolves(): void {
		// With a generous budget the same slow success resolves normally; the deadline watchdog does not fire early.
		$client = new ReactHttpClient($this->slowBrowser('get', 0.1));
		$client->retries = 0;
		$client->totalTimeout = 1000;

		$result = null;
		$client->getAsync('http://x')->then(function($r) use (&$result) { $result = $r; });
		Loop::run();

		self::assertNotNull($result);
		self::assertSame('ok', $result->content);
	}

	public function testPutWithinTotalTimeoutResolves(): void {
		// A PUT that completes before the deadline resolves normally and is sent exactly once.
		$client = new ReactHttpClient($this->slowBrowser('put', 0.05));
		$client->totalTimeout = 1000;

		$result = null;
		$client->putAsync('http://x', [], [], '{}')->then(function($r) use (&$result) { $result = $r; });
		Loop::run();

		self::assertNotNull($result);
		self::assertSame('ok', $result->content);
	}

	public function testNonPositiveTimeoutDisablesPerAttemptLimit(): void {
		// React's Browser treats a negative timeout as "time out immediately"; `false` is its "no limit".
		$browser = $this->createMock(Browser::class);
		$browser->expects(self::once())->method('withTimeout')->with(false)->willReturnSelf();
		$browser->method('get')->willReturn(resolve(new ReactResponse(200, [], 'ok')));
		$client = new ReactHttpClient($browser);
		$client->timeout = 0;

		$result = null;
		$client->getAsync('http://x')->then(function($r) use (&$result) { $result = $r; });
		Loop::run();

		self::assertSame('ok', $result->content);
	}
}

<?php

namespace ABSmartly\SDK\Tests\Http;

use ABSmartly\SDK\Http\HTTPClient;
use PHPUnit\Framework\TestCase;

/**
 * Drives HTTPClient's retry loop with a fake clock and a scripted transport, so no network or real sleeping is involved.
 */
class FakeClockHTTPClient extends HTTPClient {
	public float $now = 0.0;
	/** @var array<int, array{0: int, 1: bool}> Per attempt: [elapsed ms, retryable] */
	public array $script = [];
	public array $attemptTimeouts = [];
	public array $connectTimeouts = [];
	public array $sleeps = [];

	protected function transfer(int $timeoutMs) {
		$this->attemptTimeouts[] = $timeoutMs;
		$this->connectTimeouts[] = $this->timeout;
		[$elapsed] = $this->script[count($this->attemptTimeouts) - 1];
		$this->now += $elapsed;
		return 'body';
	}

	protected function isRetryable(): bool {
		return $this->script[count($this->attemptTimeouts) - 1][1];
	}

	protected function nowMs(): float {
		return $this->now;
	}

	protected function sleepMs(int $milliseconds): void {
		$this->sleeps[] = $milliseconds;
		$this->now += $milliseconds;
	}

	public function run(string $method = 'get'): void {
		// transfer() is faked, so no request leaves the process; get()/put()/post() only set up a real, never-executed
		// cURL handle.
		$this->$method('https://demo.absmartly.io/v1/context');
	}
}

class HTTPClientRetryTest extends TestCase {
	private function client(int $totalTimeout, int $retries, array $script): FakeClockHTTPClient {
		$client = new FakeClockHTTPClient();
		$client->timeout = 3000;
		$client->totalTimeout = $totalTimeout;
		$client->retries = $retries;
		$client->script = $script;
		return $client;
	}

	public function testConnectTimeoutStaysConstantAcrossAttempts(): void {
		$client = $this->client(0, 2, array_fill(0, 3, [0, true]));
		$client->timeout = 1234;
		$client->run('get');

		// $timeout is the connect timeout: it is applied unchanged on every attempt.
		self::assertSame([1234, 1234, 1234], $client->connectTimeouts);
		// With no total budget, cURL gets no overall timeout (0) each attempt.
		self::assertSame([0, 0, 0], $client->attemptTimeouts);
	}

	public function testTotalTimeoutBoundsTheWholeRetrySequence(): void {
		$client = $this->client(3000, 5, array_merge([[3000, true]], array_fill(0, 5, [0, true])));
		$client->run();

		self::assertSame([3000], $client->attemptTimeouts);
		self::assertSame([], $client->sleeps);
		self::assertLessThanOrEqual(3000, $client->now);
	}

	public function testEachRetryOnlyGetsTheRemainingTime(): void {
		$client = $this->client(1000, 5, [[200, true], [300, true], [0, false]]);
		$client->run();

		// 1000 - 200 - 50ms back-off = 750; 750 - 300 - 100ms back-off = 350.
		self::assertSame([1000, 750, 350], $client->attemptTimeouts);
		self::assertSame([50, 100], $client->sleeps);
	}

	public function testNoRetryStartsWhenTheBackOffWouldPassTheDeadline(): void {
		$client = $this->client(1000, 5, [[960, true], [0, false]]);
		$client->run();

		self::assertSame([1000], $client->attemptTimeouts);
		self::assertSame([], $client->sleeps);
	}

	public function testRetryLimitStillApplies(): void {
		$client = $this->client(60000, 2, array_fill(0, 5, [0, true]));
		$client->run();

		self::assertCount(3, $client->attemptTimeouts);
		self::assertSame([50, 100], $client->sleeps);
	}

	public function testZeroRetriesSendsOnce(): void {
		$client = $this->client(3000, 0, [[0, true], [0, true]]);
		$client->run();

		self::assertCount(1, $client->attemptTimeouts);
	}

	public function testBackOffIsCappedForLargeRetryCounts(): void {
		$client = $this->client(0, 70, array_fill(0, 71, [0, true]));
		$client->run();

		self::assertCount(71, $client->attemptTimeouts);
		self::assertSame(0, $client->attemptTimeouts[0], 'A timeout of 0 means no limit');
		self::assertSame(1000, max($client->sleeps));
		self::assertSame(1000, end($client->sleeps));
	}

	public function testGetRetriesRetryableFailures(): void {
		$client = $this->client(60000, 5, [[0, true], [0, true], [0, false]]);
		$client->run('get');

		self::assertCount(3, $client->attemptTimeouts);
		self::assertSame([50, 100], $client->sleeps);
	}

	public function testPutIsNeverRetried(): void {
		$client = $this->client(60000, 5, array_fill(0, 6, [0, true]));
		$client->run('put');

		self::assertCount(1, $client->attemptTimeouts);
		self::assertSame([], $client->sleeps);
	}

	public function testPostIsNeverRetried(): void {
		$client = $this->client(60000, 5, array_fill(0, 6, [0, true]));
		$client->run('post');

		self::assertCount(1, $client->attemptTimeouts);
		self::assertSame([], $client->sleeps);
	}
}

<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\AsyncClientInterface;
use ABSmartly\SDK\Context\AsyncContextEventHandler;
use ABSmartly\SDK\Context\ContextEventHandler;
use ABSmartly\SDK\PublishEvent;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class AsyncContextEventHandlerTest extends TestCase {
	public function testExtendsContextEventHandler(): void {
		$client = $this->createMock(AsyncClientInterface::class);
		$handler = new AsyncContextEventHandler($client);

		self::assertInstanceOf(ContextEventHandler::class, $handler);
	}

	public function testPublishAsyncReturnsPromise(): void {
		$client = $this->createMock(AsyncClientInterface::class);

		$client->method('publishAsync')
			->willReturn(resolve(null));

		$handler = new AsyncContextEventHandler($client);
		$event = new PublishEvent();
		$promise = $handler->publishAsync($event);

		self::assertInstanceOf(PromiseInterface::class, $promise);
	}

	public function testPublishSyncStillWorks(): void {
		$client = $this->createMock(AsyncClientInterface::class);

		$client->expects($this->once())
			->method('publish');

		$handler = new AsyncContextEventHandler($client);
		$event = new PublishEvent();
		$handler->publish($event);
	}
}

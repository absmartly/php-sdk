<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\AsyncContextDataProvider;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;
use ABSmartly\SDK\PublishEvent;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use ABSmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy;
use ABSmartly\SDK\Utils;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

class RejectingAsyncProvider extends AsyncContextDataProvider {
	public function __construct() {
	}

	public function getContextDataAsync(): PromiseInterface {
		return reject(new \RuntimeException('Context load failed'));
	}
}

class EmptyProvider extends ContextDataProvider {
	public function __construct() {
	}

	public function getContextData(): ContextData {
		return new ContextData();
	}
}

class ContextConformanceTest extends TestCase {

	private function sdk(ContextDataProvider $provider): SDK {
		$client = new Client(new ClientConfig('https://example.com', 'k', 'a', 'e'));
		$config = new Config($client);
		$config->setContextDataProvider($provider);
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		return new SDK($config);
	}

	private function context(?MockContextEventLoggerProxy $logger = null): Context {
		$cfg = new ContextConfig();
		if ($logger) {
			$cfg->setEventLogger($logger);
		}
		$cfg->setUnit('session_id', 'abc');
		return $this->sdk(new EmptyProvider())->createContext($cfg);
	}

	private function settle(PromiseInterface $promise) {
		$out = null;
		$promise->then(function($v) use (&$out) { $out = $v; }, function($e) use (&$out) { $out = $e; });
		return $out;
	}

	public function testSetUnitAlreadySetMessageMatchesOtherSdks(): void {
		$context = $this->context();
		$this->expectExceptionMessage("Unit 'session_id' UID already set.");
		$context->setUnit('session_id', 'other');
	}

	public function testNumericUnitsKeepTheirType(): void {
		$cfg = new ContextConfig();
		$cfg->setUnits(['user_id' => 125, 'float_id' => 125.75, 'session_id' => 'abc']);
		$context = $this->sdk(new EmptyProvider())->createContext($cfg);
		$context->setUnit('other_id', 12345);

		self::assertSame(125, $context->getUnit('user_id'));
		self::assertSame(125.75, $context->getUnit('float_id'));
		self::assertSame(12345, $context->getUnit('other_id'));
		self::assertSame('abc', $context->getUnit('session_id'));
	}

	public function testStringUnitThatLooksNumericStaysAString(): void {
		$context = $this->context();
		$context->setUnit('user_id', '007');
		self::assertSame('007', $context->getUnit('user_id'));
	}

	public function testNumericUnitHashesLikeItsRoundedIntegerString(): void {
		// Expected values are the JavaScript SDK's hashUnit() output.
		self::assertSame('BpBZt--EDwx0qBTskje27A', Utils::hashUnit(125.75));
		self::assertSame('BpBZt--EDwx0qBTskje27A', Utils::hashUnit('126'));
		self::assertSame('815ubasUW2p7sFI440lKaw', Utils::hashUnit(-0.1));
		self::assertSame('BpBZt--EDwx0qBTskje27A', (new PublishEvent())->hashUnit(125.75));
		self::assertNotSame(Utils::hashUnit(125), Utils::hashUnit(125.75));
	}

	public function testNumericUnitStringFormMatchesJavascriptToFixed(): void {
		self::assertSame('126', Utils::unitToString(125.75));
		self::assertSame('125', Utils::unitToString(125));
		self::assertSame('-0', Utils::unitToString(-0.1));
		self::assertSame('0', Utils::unitToString(-0.0));
		self::assertSame('-1', Utils::unitToString(-0.5));
		self::assertSame('1', Utils::unitToString(0.5));
		self::assertSame('1e+21', Utils::unitToString(1e21));
		self::assertSame('1.5e+21', Utils::unitToString(1.5e21));
		self::assertSame('-1e+21', Utils::unitToString(-1e21));
		self::assertSame('999999999999999868928', Utils::unitToString(999999999999999868928.0));
	}

	public function testEqualNumericUnitsAreNotAConflict(): void {
		$cfg = new ContextConfig();
		$cfg->setUnit('user_id', 125);
		$context = $this->sdk(new EmptyProvider())->createContext($cfg);
		$context->setUnit('user_id', 125.0);
		self::assertSame(125, $context->getUnit('user_id'));

		$this->expectExceptionMessage("Unit 'user_id' UID already set.");
		$context->setUnit('user_id', '125');
	}

	public function testDistinctLargeIntegerUnitsConflict(): void {
		$context = $this->context();
		$context->setUnit('user_id', 9007199254740992);
		$this->expectExceptionMessage("Unit 'user_id' UID already set.");
		$context->setUnit('user_id', 9007199254740993);
	}

	public function testInvalidUnitTypeIsRejected(): void {
		$context = $this->context();
		$this->expectExceptionMessage("Unit 'x' must be a string or a number.");
		$context->setUnit('x', true);
	}

	public function testTrackRejectsNonObjectProperties(): void {
		foreach ([125.0, 'text', true, [1, 2], []] as $bad) {
			$context = $this->context();
			try {
				$context->track('goal', $bad);
				self::fail('expected an error for ' . json_encode($bad));
			} catch (\InvalidArgumentException $e) {
				self::assertSame("Goal 'goal' properties must be of type object.", $e->getMessage());
			}
			self::assertSame(0, $context->getPendingCount());
		}
	}

	public function testTrackAcceptsObjectsAndAssociativeArrays(): void {
		$context = $this->context();
		$context->track('a', (object) ['x' => 1]);
		$context->track('b', ['x' => 1]);
		$context->track('d');
		self::assertSame(3, $context->getPendingCount());
	}

	public function testRejectedAsyncLoadLeavesContextReadyAndFailed(): void {
		$logger = new MockContextEventLoggerProxy();
		$cfg = new ContextConfig();
		$cfg->setUnit('session_id', 'abc');
		$cfg->setEventLogger($logger);
		$result = $this->sdk(new RejectingAsyncProvider())->createContextPending($cfg);
		$settled = $this->settle($result['promise']);

		$context = $result['context'];
		self::assertSame($context, $settled);
		self::assertTrue($context->isReady());
		self::assertTrue($context->isFailed());
		self::assertSame('Context load failed', $context->readyError()->getMessage());
		self::assertSame(0, $context->getTreatment('any'));
		self::assertSame(ContextEventLoggerEvent::Error, $logger->events[0]->getEvent());
	}

	public function testRejectedAsyncLoadResolvesCreateContextAsyncWithFailedContext(): void {
		$logger = new MockContextEventLoggerProxy();
		$cfg = new ContextConfig();
		$cfg->setUnit('session_id', 'abc');
		$cfg->setEventLogger($logger);
		$context = $this->settle($this->sdk(new RejectingAsyncProvider())->createContextAsync($cfg));

		self::assertInstanceOf(Context::class, $context);
		self::assertTrue($context->isReady());
		self::assertTrue($context->isFailed());
		self::assertSame(ContextEventLoggerEvent::Error, $logger->events[0]->getEvent());
	}

	public function testUtilsMatchJavascriptHelpers(): void {
		self::assertSame([97, 195, 167, 98, 226, 134, 147, 99], Utils::utf8Bytes("a\u{e7}b\u{2193}c"));
		self::assertSame([], Utils::utf8Bytes(''));
		self::assertSame('AQID', Utils::base64UrlNoPadding("\x01\x02\x03"));
		self::assertSame('-_8', Utils::base64UrlNoPadding("\xfb\xff"));
		self::assertTrue(Utils::isNumeric(1));
		self::assertTrue(Utils::isNumeric(1.5));
		self::assertFalse(Utils::isNumeric('1'));
		self::assertTrue(Utils::isObject(new \stdClass()));
		self::assertFalse(Utils::isObject([]));
		self::assertFalse(Utils::isObject(new \ArrayObject()));
		self::assertTrue(Utils::isPromise(resolve(1)));
		self::assertFalse(Utils::isPromise((object) ['then' => 'noop']));
	}

	public function testFailedFinalPublishKeepsContextOpenAndRetryable(): void {
		$client = new Client(new ClientConfig('https://example.com', 'k', 'a', 'e'));
		$config = new Config($client);
		$config->setContextDataProvider(new EmptyProvider());
		$handler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($handler);
		$cfg = new ContextConfig();
		$cfg->setUnit('session_id', 'abc');
		$context = (new SDK($config))->createContext($cfg);

		$handler->prerun = static function() { throw new \RuntimeException('Publish failed'); };
		$context->track('goal');
		try {
			$context->close();
			self::fail('expected the publish error');
		} catch (\RuntimeException $e) {
			self::assertSame('Publish failed', $e->getMessage());
		}
		self::assertFalse($context->isClosed());
		self::assertFalse($context->isFinalizing());
		self::assertSame(1, $context->getPendingCount());

		$handler->prerun = null;
		$context->close();
		self::assertTrue($context->isClosed());
		self::assertSame(0, $context->getPendingCount());
	}
}

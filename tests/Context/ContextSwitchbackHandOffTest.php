<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
use ABSmartly\SDK\Context\ContextEventLogger;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;
use ABSmartly\SDK\SDK;
use PHPUnit\Framework\TestCase;

/** A callback that installs the first switchback mid-walk hands the rest of a variable walk to the switchback walk. */
class ContextSwitchbackHandOffTest extends TestCase {
	private static function ex(int $id, string $name, array $cfg): object {
		return (object) ['id' => $id, 'name' => $name, 'unitType' => 'user_id', 'iteration' => 1, 'seedHi' => -5, 'seedLo' => -7,
			'split' => [.5, .5], 'trafficSeedHi' => 1, 'trafficSeedLo' => 2, 'trafficSplit' => [0, 1], 'fullOnVariant' => 1,
			'variants' => [(object) ['config' => $cfg[0]], (object) ['config' => $cfg[1]]]];
	}

	private static function sw(object $e, string $type): object {
		$s = clone $e;
		$s->randomizationType = 'switchback';
		$s->switchbackClusters = (object) [$type => 'cluster'];
		$s->switchbackStartAt = 1000;
		$s->switchbackPeriodMs = 3600000;
		return $s;
	}

	private function readK(array $initial, array $next, array $holdouts): array {
		$provider = new class extends ContextDataProvider { public $next; public function __construct() {} public function getContextData(): ContextData { return $this->next; } };
		$provider->next = new ContextData($next, $holdouts);
		$logger = new class implements ContextEventLogger { public bool $done = false;
			public function handleEvent(Context $c, ContextEventLoggerEvent $e): void {
				if ($e->getEvent() === ContextEventLoggerEvent::Exposure && !$this->done) { $this->done = true; $c->refresh(); } } };
		$config = new Config(new Client(new ClientConfig('https://unused.invalid', 'key', 'app', 'env')));
		$config->setContextDataProvider($provider);
		$context = (new SDK($config))->createContextWithData((new ContextConfig())->setUnit('user_id', 'u1')->setEventLogger($logger), new ContextData($initial, $holdouts));
		$context->setAttribute('cluster', 'porto');
		return [$context->getVariableValue('k', -1), $context->getPendingCount()];
	}

	/** The held-out candidate is not re-read after the hand-off even when the refresh lifts its holdout (as 1.2.0). */
	public function testTheHandOffResumesAfterTheCurrentCandidate(): void {
		$h = (object) ['id' => 101, 'name' => 'h', 'iteration' => 1, 'seedHi' => 1, 'seedLo' => 2, 'split' => [1, 0]];
		$a = self::ex(1, 'a', ['{"k":10}', '{"k":11}']);
		$a->holdoutIds = [101];
		$b = self::ex(2, 'b', ['{"k":20}', '{"k":21}']);
		$lifted = clone $a;
		unset($lifted->holdoutIds);
		$sbx = self::sw(self::ex(9, 'sbx', ['{"z":1}', '{"z":2}']), 'user_id');
		self::assertSame([21, 2], $this->readK([$a, $b], [$lifted, $b, $sbx], [$h]));
	}

	/** After the hand-off, a later candidate that became a switchback is read by the switchback walk, warning-free. */
	public function testALaterCandidateThatBecameASwitchbackIsWalkedAsOne(): void {
		$h = (object) ['id' => 101, 'name' => 'h', 'iteration' => 1, 'seedHi' => 1, 'seedLo' => 2, 'split' => [1, 0]];
		$a = self::ex(1, 'a', ['{"k":10}', '{"k":11}']);
		$a->holdoutIds = [101];
		$b = self::ex(2, 'b', ['{"k":20}', '{"k":21}']);
		$c = self::ex(3, 'c', ['{"k":30}', '{"k":31}']);
		self::assertSame([31, 2], $this->readK([$a, $b, $c], [$a, self::sw($b, 'store_id'), $c], [$h]));
	}
	/**
	 * An audience getter that refreshes in the first switchback while a candidate is decided, and drops that candidate's
	 * key, still hands the rest of the walk over: no unit-walk warning reading the switchback's assignment.
	 */
	public function testAGetterRefreshThatSkipsTheCandidateStillHandsOff(): void {
		$audience = '{"filter":[{"eq":[{"var":"tier/value"},{"value":"gold"}]}]}';
		$a = self::ex(1, 'a', ['{"k":10}', '{"k":11}']);
		$a->audience = $audience;
		$a->audienceStrict = true;
		$b = self::ex(2, 'b', ['{"k":20}', '{"k":21}']);
		$covered = self::ex(3, 'covered', ['{"z":1}', '{"z":2}']);
		$covered->holdoutIds = [101];
		$h = (object) ['id' => 101, 'name' => 'h', 'iteration' => 1, 'seedHi' => 1, 'seedLo' => 2, 'split' => [0, 1]];
		$stripped = self::ex(1, 'a', ['{"other":10}', '{"other":11}']);
		$stripped->audience = $audience;
		$stripped->audienceStrict = true;
		$provider = new class extends ContextDataProvider { public $next; public function __construct() {} public function getContextData(): ContextData { return $this->next; } };
		$provider->next = new ContextData([$stripped, self::sw($b, 'store_id'), $covered], [$h]);
		$config = new Config(new Client(new ClientConfig('https://unused.invalid', 'key', 'app', 'env')));
		$config->setContextDataProvider($provider);
		$context = (new SDK($config))->createContextWithData((new ContextConfig())->setUnit('user_id', 'u1'),
			new ContextData([$a, $b, $covered], [$h]));
		$context->setAttributeValue('tier', new class($context) {
			private $value;
			private Context $context;
			private bool $done = false;
			public function __construct(Context $context) { $this->context = $context; }
			public function __isset($name) { return true; }
			public function __get($name) {
				if (!$this->done) {
					$this->done = true;
					$this->context->refresh();
				}
				return 'silver';
			}
		});
		// convertWarningsToExceptions: any unit-walk read of a switchback assignment fails this test.
		self::assertSame(-1, $context->getVariableValue('k', -1));
		self::assertSame(0, $context->getPendingCount());
	}

}

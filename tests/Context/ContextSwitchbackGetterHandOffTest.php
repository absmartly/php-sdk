<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
use ABSmartly\SDK\SDK;
use PHPUnit\Framework\TestCase;

/** a getter refresh that removes the current candidate and installs a switchback resumes from the walk. */
class ContextSwitchbackGetterHandOffTest extends TestCase {
	private static function ex(int $id, string $name, array $cfg): object {
		return (object) ['id' => $id, 'name' => $name, 'unitType' => 'user_id', 'iteration' => 1, 'seedHi' => 3, 'seedLo' => 4,
			'split' => [.5, .5], 'trafficSeedHi' => 1, 'trafficSeedLo' => 2, 'trafficSplit' => [0, 1], 'fullOnVariant' => 1,
			'variants' => [(object) ['config' => $cfg[0]], (object) ['config' => $cfg[1]]]];
	}

	/** @dataProvider reads */
	public function testAGetterRefreshThatRemovesTheCandidateResumesWithTheNextWalkCandidate(bool $expose): void {
		$audience = '{"filter":[{"eq":[{"var":"tier/value"},{"value":"gold"}]}]}';
		$a = self::ex(1, 'a', ['{"k":10}', '{"k":11}']);
		$a->audience = $audience;
		$a->audienceStrict = true;
		$b = self::ex(2, 'b', ['{"k":20}', '{"k":21}']);
		$c = self::ex(3, 'c', ['{"k":30}', '{"k":31}']);
		$covered = self::ex(4, 'covered', ['{"z":1}', '{"z":2}']);
		$covered->holdoutIds = [101];
		$h = (object) ['id' => 101, 'name' => 'h', 'iteration' => 1, 'seedHi' => 1, 'seedLo' => 2, 'split' => [0, 1]];
		$sbx = self::ex(9, 'sbx', ['{"q":1}', '{"q":2}']);
		$sbx->randomizationType = 'switchback';
		$sbx->switchbackClusters = (object) ['user_id' => 'store'];
		$sbx->switchbackStartAt = 1000;
		$sbx->switchbackPeriodMs = 3600000;
		$provider = new class extends ContextDataProvider { public $next; public function __construct() {} public function getContextData(): ContextData { return $this->next; } };
		$provider->next = new ContextData([$b, $c, $covered, $sbx], [$h]);
		$config = new Config(new Client(new ClientConfig('https://unused.invalid', 'key', 'app', 'env')));
		$config->setContextDataProvider($provider);
		$context = (new SDK($config))->createContextWithData((new ContextConfig())->setUnit('user_id', 'u1'),
			new ContextData([$a, $b, $c, $covered], [$h]));
		$context->setAttribute('store', 'porto');
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
		// As 1.2.0 (and as without the switchback): `b`, the next candidate of the walk, wins.
		self::assertSame(21, $expose ? $context->getVariableValue('k', -1) : $context->peekVariableValue('k', -1));
		self::assertSame($expose ? 1 : 0, $context->getPendingCount());
	}

	public function reads(): array {
		return ['getVariableValue' => [true], 'peekVariableValue' => [false]];
	}
}

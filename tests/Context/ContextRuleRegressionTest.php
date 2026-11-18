<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Tests\Mocks\ContextDataProviderMock;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use PHPUnit\Framework\TestCase;

class ContextRuleRegressionTest extends TestCase {
	private ContextEventHandlerMock $eventHandler;

	private static function experiment(array $fields = []): object {
		return (object) array_merge([
			'id' => 7,
			'name' => 'exp',
			'unitType' => 'session_id',
			'iteration' => 1,
			'seedHi' => 55006150,
			'seedLo' => 47189152,
			'split' => [0.34, 0.33, 0.33],
			'trafficSeedHi' => 705671872,
			'trafficSeedLo' => 212903484,
			'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => 0,
			'audience' => '',
			'audienceStrict' => false,
			'applications' => [(object) ['name' => 'website']],
			'variants' => [
				(object) ['name' => 'A', 'config' => null],
				(object) ['name' => 'B', 'config' => null],
				(object) ['name' => 'C', 'config' => null],
			],
		], $fields);
	}

	private static function rules(array ...$rules): string {
		return json_encode(['rules' => $rules]);
	}

	private static function eq(string $var, $value): array {
		return ['eq' => [['var' => $var], ['value' => $value]]];
	}

	private function createContext(object $experiment, string $uid = 'e791e240fcd3df7d238cfc285f475e8152fcc0ec', string $environment = 'production'): Context {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'website', $environment));
		$config = new Config($client);
		$config->setContextDataProvider(new ContextDataProviderMock($client));
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => $uid]);
		return (new SDK($config))->createContextWithData($contextConfig, new ContextData([$experiment]));
	}

	private function publishedExposures(Context $context): array {
		$context->publish();
		$exposures = [];
		foreach ($this->eventHandler->submitted as $event) {
			foreach ($event->exposures ?? [] as $exposure) {
				$exposures[] = [$exposure->variant, $exposure->ruleOverride];
			}
		}
		return $exposures;
	}

	/**
	 * The JavaScript SDK's VariantAssigner gives these variants for a 20/30/50 split hashed with the experiment seed
	 * (55006150, 47189152); with the traffic seed most of them would differ.
	 */
	public function splitVectorProvider(): array {
		return [
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', 2],
			['123456789', 2],
			['bleh@absmartly.com', 0],
			['0', 1],
			['a', 2],
			['user-42', 1],
			['4a42766ca6313d26f49985e799ff4f3790fb86efa0fce46edb3ea8fbf1ea3408', 0],
			['x9', 1],
		];
	}

	/**
	 * @dataProvider splitVectorProvider
	 */
	public function testSplitRuleMatchesTheJavaScriptVectors(string $uid, int $variant): void {
		$experiment = self::experiment(['assignmentRules' => self::rules(['type' => 'split', 'percentages' => '20/30/50'])]);

		self::assertSame($variant, $this->createContext($experiment, $uid)->peekTreatment('exp'));
	}

	public function testRulesAreScopedByTheConfiguredEnvironmentButConditionsSeeTheUserOne(): void {
		$experiment = self::experiment(['assignmentRules' => self::rules(
			['type' => 'assign', 'variant' => 1, 'environments' => ['staging']],
			['type' => 'assign', 'variant' => 0, 'environments' => ['production'], 'conditions' => self::eq('environment', 'staging')]
		)]);
		$context = $this->createContext($experiment);
		$context->setAttribute('environment', 'staging');

		self::assertSame(0, $context->peekTreatment('exp'));
	}

	public function testConditionsSeeTheConfiguredEnvironmentWithoutAUserOne(): void {
		$experiment = self::experiment(['assignmentRules' => self::rules(
			['type' => 'assign', 'variant' => 1, 'environments' => ['production'], 'conditions' => self::eq('environment', 'production')]
		)]);

		self::assertSame(1, $this->createContext($experiment)->peekTreatment('exp'));
	}

	public function testTheSdkFactoryWiresTheApplicationAndEnvironmentIntoAudiences(): void {
		$sdk = SDK::createSimple('https://demo.absmartly.io/v1', 'key', 'website', 'production');
		$audience = json_encode(['filter' => ['and' => [self::eq('application', 'website'), self::eq('environment', 'production')]]]);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => 'e791e240fcd3df7d238cfc285f475e8152fcc0ec']);
		$context = $sdk->createContextWithData($contextConfig, new ContextData([
			self::experiment(['audience' => $audience, 'audienceStrict' => true]),
		]));

		self::assertSame(2, $context->peekTreatment('exp'));
	}

	public function testAppVersionIsAbsentUntilTheUserSetsIt(): void {
		$client = new Client((new ClientConfig('https://demo.absmartly.io/v1', 'key', 'website', 'production'))->setApplicationVersion('7'));
		$config = new Config($client);
		$config->setContextDataProvider(new ContextDataProviderMock($client));
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => 'e791e240fcd3df7d238cfc285f475e8152fcc0ec']);
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData([
			self::experiment(['name' => 'absent', 'audienceStrict' => true, 'audience' => json_encode(['filter' => ['null' => ['var' => 'app_version']]])]),
			self::experiment(['name' => 'configured', 'audienceStrict' => true, 'audience' => json_encode(['filter' => self::eq('app_version', '7')])]),
			self::experiment(['name' => 'user', 'audienceStrict' => true, 'audience' => json_encode(['filter' => ['semver_gte' => [['var' => 'app_version'], ['value' => '2.4.0']]]])]),
		]));

		self::assertSame(2, $context->peekTreatment('absent'));
		self::assertSame(0, $context->peekTreatment('configured'));
		self::assertSame(0, $context->peekTreatment('user'));

		$context->setAttribute('app_version', '2.4.1');
		self::assertSame(0, $context->peekTreatment('absent'));
		self::assertSame(0, $context->peekTreatment('configured'));
		self::assertSame(2, $context->peekTreatment('user'));
	}

	public function testEachRuleTransitionIsExposedOnce(): void {
		$experiment = self::experiment(['assignmentRules' => self::rules(['type' => 'assign', 'variant' => 1, 'conditions' => self::eq('country', 'US')])]);
		$context = $this->createContext($experiment);

		$context->setAttribute('country', 'US');
		$context->getTreatment('exp');
		$context->getTreatment('exp');
		$context->setAttributeValue('unrelated', 1);
		$context->getTreatment('exp');
		$context->setAttribute('country', 'GB');
		$context->getTreatment('exp');
		$context->getTreatment('exp');
		$context->setAttribute('country', 'US');
		$context->getTreatment('exp');

		self::assertSame([[1, true], [2, false], [1, true]], $this->publishedExposures($context));
	}

	public function testUnknownNestedFieldsAreIgnored(): void {
		$experiment = self::experiment([
			'futureField' => (object) ['nested' => [1, (object) ['deeper' => true]]],
			'assignmentRules' => json_encode([
				'version' => 2,
				'meta' => ['owner' => ['team' => 'growth']],
				'rules' => [[
					'name' => 'us',
					'type' => 'assign',
					'variant' => 1,
					'environments' => [],
					'conditions' => self::eq('country', 'US'),
					'futureRuleField' => ['nested' => [['a' => 1]]],
				]],
			]),
			'variants' => [
				(object) ['name' => 'A', 'config' => null, 'futureVariantField' => (object) ['x' => [1]]],
				(object) ['name' => 'B', 'config' => '{"color":"blue"}', 'futureVariantField' => null],
				(object) ['name' => 'C', 'config' => null],
			],
		]);
		$context = $this->createContext($experiment);
		$context->setAttribute('country', 'US');

		self::assertFalse($context->isFailed());
		self::assertSame(1, $context->getTreatment('exp'));
		self::assertSame('blue', $context->getVariableValue('color', 'default'));
	}

	/**
	 * Cross-SDK scenario 323: a blank configured application is left out of the evaluation map, while the configured
	 * environment still applies.
	 */
	public function testABlankApplicationIsLeftOutOfTheEvaluationMap(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', '', 'sysattr-env'));
		$config = new Config($client);
		$config->setContextDataProvider(new ContextDataProviderMock($client));
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => 'svk-unit-1']);
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData([
			self::experiment(['name' => 'present', 'split' => [0.0, 1.0, 0.0], 'audienceStrict' => true,
				'audience' => json_encode(['filter' => [['not' => ['null' => ['var' => 'application']]]]])]),
			self::experiment(['name' => 'env', 'split' => [0.0, 1.0, 0.0], 'audienceStrict' => true,
				'audience' => json_encode(['filter' => [self::eq('environment', 'sysattr-env')]])]),
		]));

		self::assertSame(0, $context->peekTreatment('present'));
		self::assertSame(1, $context->peekTreatment('env'));
	}

	public function testUnknownTopLevelExperimentFieldsAreNotAddedToTheExperiment(): void {
		$context = $this->createContext(self::experiment(['futureField' => (object) ['nested' => true]]));

		self::assertFalse(property_exists($context->getExperiment('exp')->data, 'futureField'));
	}

	public function testAFailedRefreshKeepsTheRules(): void {
		$experiment = self::experiment(['assignmentRules' => self::rules(['type' => 'assign', 'variant' => 1])]);
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'key', 'website', 'production'));
		$config = new Config($client);
		$config->setContextDataProvider(new class($client) extends \ABSmartly\SDK\Context\ContextDataProvider {
			public function getContextData(): ContextData {
				// setData() throws on the second experiment (invalid audience JSON) after installing the first.
				return new ContextData([ContextRuleRegressionTest::validExperiment(), ContextRuleRegressionTest::brokenExperiment()]);
			}
		});
		$config->setContextEventHandler(new ContextEventHandlerMock($client));
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => 'e791e240fcd3df7d238cfc285f475e8152fcc0ec']);
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData([$experiment]));

		self::assertSame(1, $context->getTreatment('exp'));
		$context->refresh();
		self::assertSame(1, $context->getTreatment('exp'));
		$context->setAttributeValue('unrelated', true);
		self::assertSame(1, $context->getTreatment('exp'));
		self::assertSame(1, $context->getPendingCount());
	}

	public static function brokenExperiment(): object {
		return self::experiment(['name' => 'broken', 'id' => 9, 'audience' => '{not json']);
	}

	public static function validExperiment(): object {
		return self::experiment(['name' => 'other', 'id' => 8, 'assignmentRules' => self::rules(['type' => 'assign', 'variant' => 2])]);
	}
}

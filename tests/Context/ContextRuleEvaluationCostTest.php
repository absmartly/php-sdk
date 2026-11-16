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
use ABSmartly\SDK\Tests\Mocks\ContextDataProviderMock;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The evaluation attribute map (system attributes overlaid by every attribute set so far) is only built when a rule
 * or an audience needs it, and at most once per attribute change, as the JavaScript SDK's _getAttributesMap.
 * Assignment rules JSON is decoded at most once per data install.
 */
class ContextRuleEvaluationCostTest extends TestCase {
	private const US_AUDIENCE = '{"filter":[{"eq":[{"var":"country"},{"value":"US"}]}]}';
	private const EXPERIMENTS = ['exp_test_ab', 'exp_test_abc', 'exp_test_not_eligible', 'exp_test_fullon'];

	/** Each build of the evaluation attribute map reads the client's application once. */
	private int $attributeMapBuilds = 0;
	/** Each rule evaluation reads the client's environment once (as does each data install). */
	private int $environmentReads = 0;

	private static function usRule(): string {
		return json_encode(['rules' => [['name' => 'us', 'type' => 'assign', 'variant' => 1, 'environments' => [],
			'conditions' => ['eq' => [['var' => 'country'], ['value' => 'US']]]]]]);
	}

	private function createContext(?string $rules, ?string $audience, int $attributeCount = 100, bool $sameData = false): Context {
		$data = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'), false, 512, JSON_THROW_ON_ERROR);
		foreach ($data->experiments as $experiment) {
			if ($experiment->name === 'exp_test_abc') {
				if ($rules !== null) {
					$experiment->assignmentRules = $rules;
				}
				if ($audience !== null) {
					$experiment->audience = $audience;
				}
			}
		}

		$builds = &$this->attributeMapBuilds;
		$environmentReads = &$this->environmentReads;
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', 'key', 'website', 'production');
		$client = new class($clientConfig, $builds, $environmentReads) extends Client {
			private int $builds;
			private int $environmentReads;

			public function __construct(ClientConfig $clientConfig, int &$builds, int &$environmentReads) {
				parent::__construct($clientConfig);
				$this->builds = &$builds;
				$this->environmentReads = &$environmentReads;
			}

			public function getApplication(): string {
				++$this->builds;
				return parent::getApplication();
			}

			public function getEnvironment(): string {
				++$this->environmentReads;
				return parent::getEnvironment();
			}
		};
		$config = new Config($client);
		$config->setContextDataProvider($sameData
			? new class($client, $data) extends ContextDataProvider {
				private object $data;

				public function __construct(Client $client, object $data) {
					parent::__construct($client);
					$this->data = $data;
				}

				/** Serves the same experiments with the rule renamed: different rules JSON, the same outcome. */
				public function getContextData(): ContextData {
					$experiments = [];
					foreach ($this->data->experiments as $experiment) {
						$experiment = clone $experiment;
						if (isset($experiment->assignmentRules)) {
							$experiment->assignmentRules = str_replace('"name":"us"', '"name":"us-renamed"', $experiment->assignmentRules);
						}
						$experiments[] = $experiment;
					}
					return new ContextData($experiments);
				}
			}
			: new ContextDataProviderMock($client));
		$config->setContextEventHandler(new ContextEventHandlerMock($client));

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => 'e791e240fcd3df7d238cfc285f475e8152fcc0ec']);
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData($data->experiments));
		for ($i = 0; $i < $attributeCount; ++$i) {
			$context->setAttribute('attr' . $i, $i);
		}

		return $context;
	}

	private static function decodedRules(Context $context): array {
		$property = new ReflectionProperty(Context::class, 'parsedRules');
		$property->setAccessible(true);
		return $property->getValue($context);
	}

	public function testNoRuleAndNoAudienceBuildsNoAttributeMap(): void {
		$context = $this->createContext(null, null);

		foreach (self::EXPERIMENTS as $name) {
			$context->getTreatment($name);
		}
		$context->setAttribute('country', 'US');
		foreach (self::EXPERIMENTS as $name) {
			$context->getTreatment($name);
		}

		self::assertSame(0, $this->attributeMapBuilds);
	}

	public function unusableRulesProvider(): array {
		return [
			'empty rules list' => ['{"rules":[]}'],
			'invalid JSON' => ['not-valid-json{{{'],
			'no rules list' => ['{"other":[]}'],
		];
	}

	/**
	 * @dataProvider unusableRulesProvider
	 */
	public function testUnusableRulesBuildNoAttributeMap(string $rules): void {
		$context = $this->createContext($rules, null);

		self::assertSame(2, $context->getTreatment('exp_test_abc'));
		$context->setAttribute('country', 'US');
		self::assertSame(2, $context->getTreatment('exp_test_abc'));

		self::assertSame(0, $this->attributeMapBuilds);
	}

	public function testRulesAndAudienceShareOneAttributeMapPerAttributeChange(): void {
		$context = $this->createContext(self::usRule(), self::US_AUDIENCE);

		self::assertSame(2, $context->getTreatment('exp_test_abc'));
		self::assertSame(2, $context->getTreatment('exp_test_abc'));
		self::assertSame(1, $this->attributeMapBuilds);

		$context->setAttribute('country', 'US');
		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertSame(2, $this->attributeMapBuilds);

		$context->setAttribute('country', 'GB');
		self::assertSame(2, $context->getTreatment('exp_test_abc'));
		self::assertSame(3, $this->attributeMapBuilds);
	}

	public function testAudienceOnlyBuildsTheMapOncePerAttributeChange(): void {
		$context = $this->createContext(null, self::US_AUDIENCE);

		$context->getTreatment('exp_test_abc');
		$context->getTreatment('exp_test_abc');
		self::assertSame(1, $this->attributeMapBuilds);

		$context->setAttribute('country', 'US');
		$context->getTreatment('exp_test_abc');
		self::assertSame(2, $this->attributeMapBuilds);
	}

	public function testRulesJsonIsDecodedOncePerDataInstall(): void {
		$context = $this->createContext(self::usRule(), null);

		$context->getTreatment('exp_test_abc');
		$decoded = self::decodedRules($context)['exp_test_abc'];
		foreach (['US', 'GB', 'US'] as $country) {
			$context->setAttribute('country', $country);
			$context->getTreatment('exp_test_abc');
		}

		self::assertSame(['exp_test_abc'], array_keys(self::decodedRules($context)));
		self::assertSame($decoded, self::decodedRules($context)['exp_test_abc']);

		// The data served on refresh has no rules, so nothing decoded from the previous data is kept.
		$context->refresh();
		self::assertSame(2, $context->getTreatment('exp_test_abc'));
		self::assertSame([], self::decodedRules($context));
	}

	/**
	 * A same-outcome revalidation advances the assignment's attribute and rule stamps, so later reads reuse it
	 * without evaluating the rules again: after an unrelated attribute change and after a refresh whose rules JSON changed without changing the outcome.
	 */
	public function testSameOutcomeRevalidationIsNotRepeated(): void {
		$context = $this->createContext(self::usRule(), null, 100, true);
		$context->setAttribute('country', 'US');
		self::assertSame(1, $context->getTreatment('exp_test_abc'));

		foreach (['setAttribute', 'refresh'] as $change) {
			$change === 'refresh' ? $context->refresh() : $context->setAttribute('unrelated', true);
			self::assertSame(1, $context->getTreatment('exp_test_abc'));

			$reads = $this->environmentReads;
			self::assertSame(1, $context->getTreatment('exp_test_abc'));
			self::assertSame(1, $context->peekTreatment('exp_test_abc'));
			self::assertSame($reads, $this->environmentReads, $change);
		}

		self::assertSame(1, $context->getPendingCount());
	}
}

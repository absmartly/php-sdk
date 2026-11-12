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

/**
 * Ports the JavaScript SDK's "rules evaluation" context tests. Rules are attached to exp_test_abc from
 * context.json, whose normal assignment for the session unit below is variant 2.
 */
class ContextAssignmentRulesTest extends TestCase {
	private const NORMAL_VARIANT = 2;
	private const SESSION_ID = 'e791e240fcd3df7d238cfc285f475e8152fcc0ec';
	private const USER_ID = '123456789';

	private ContextEventHandlerMock $eventHandler;

	private static function usCondition(): array {
		return ['and' => [['eq' => [['var' => 'country'], ['value' => 'US']]]]];
	}

	private static function assignRule(int $variant, ?array $conditions, array $environments = []): array {
		return ['name' => 'rule', 'type' => 'assign', 'conditions' => $conditions, 'environments' => $environments, 'variant' => $variant];
	}

	private static function splitRule(string $percentages, ?array $conditions): array {
		return ['name' => 'split', 'type' => 'split', 'conditions' => $conditions, 'environments' => [], 'percentages' => $percentages];
	}

	private function createContext(
		array $rules,
		array $overrides = [],
		bool $withSession = true,
		string $environment = 'production'
	): Context {
		$data = json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/context.json'), false, 512, JSON_THROW_ON_ERROR);
		foreach ($data->experiments as $experiment) {
			if ($experiment->name === 'exp_test_abc') {
				$experiment->assignmentRules = json_encode(['rules' => $rules]);
				foreach ($overrides as $field => $value) {
					$experiment->{$field} = $value;
				}
			}
		}

		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', '', 'website', $environment));
		$config = new Config($client);
		$config->setContextDataProvider(new ContextDataProviderMock($client));
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($withSession ? ['session_id' => self::SESSION_ID, 'user_id' => self::USER_ID] : ['user_id' => self::USER_ID]);

		return (new SDK($config))->createContextWithData($contextConfig, new ContextData($data->experiments));
	}

	private function publishedExposures(Context $context): array {
		$context->publish();
		$exposures = [];
		foreach ($this->eventHandler->submitted as $event) {
			foreach ($event->exposures ?? [] as $exposure) {
				if ($exposure->name === 'exp_test_abc') {
					$exposures[] = $exposure;
				}
			}
		}
		return $exposures;
	}

	public function testMatchingRuleForcesItsVariant(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())]);
		$context->setAttribute('country', 'US');

		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertSame('blue', $context->getVariableValue('button.color', 'default'));
	}

	public function testNoMatchingRuleGivesTheNormalAssignment(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())]);
		$context->setAttribute('country', 'GB');

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_test_abc'));
	}

	public function testRuleExposureFlags(): void {
		$context = $this->createContext([self::assignRule(0, self::usCondition())]);
		$context->setAttribute('country', 'US');
		self::assertSame(0, $context->getTreatment('exp_test_abc'));

		$exposures = $this->publishedExposures($context);
		self::assertCount(1, $exposures);
		self::assertSame(0, $exposures[0]->variant);
		self::assertFalse($exposures[0]->assigned);
		self::assertTrue($exposures[0]->eligible);
		self::assertFalse($exposures[0]->overridden);
		self::assertFalse($exposures[0]->custom);
		self::assertTrue($exposures[0]->ruleOverride);
	}

	public function testNormalAssignmentExposureHasNoRuleOverride(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())]);
		$context->getTreatment('exp_test_abc');

		$exposures = $this->publishedExposures($context);
		self::assertTrue($exposures[0]->assigned);
		self::assertFalse($exposures[0]->ruleOverride);
	}

	public function testEnvironmentScopedRules(): void {
		$rules = [self::assignRule(1, self::usCondition(), ['production', 'staging'])];

		$staging = $this->createContext($rules, [], true, 'staging');
		$staging->setAttribute('country', 'US');
		self::assertSame(1, $staging->getTreatment('exp_test_abc'));

		$development = $this->createContext($rules, [], true, 'development');
		$development->setAttribute('country', 'US');
		self::assertSame(self::NORMAL_VARIANT, $development->getTreatment('exp_test_abc'));

		$unnamed = $this->createContext($rules, [], true, '');
		$unnamed->setAttribute('country', 'US');
		self::assertSame(self::NORMAL_VARIANT, $unnamed->getTreatment('exp_test_abc'));
	}

	public function testRuleConditionsSeeSystemAttributes(): void {
		$context = $this->createContext([self::assignRule(1, ['eq' => [['var' => 'application'], ['value' => 'website']]])]);

		self::assertSame(1, $context->getTreatment('exp_test_abc'));
	}

	public function testOverrideTakesPriorityOverRules(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())]);
		$context->setAttribute('country', 'US');
		$context->setOverride('exp_test_abc', 1);

		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		$exposures = $this->publishedExposures($context);
		self::assertTrue($exposures[0]->overridden);
		self::assertFalse($exposures[0]->ruleOverride);
	}

	public function testRuleWinsOverStrictAudienceWithoutEvaluatingIt(): void {
		$audience = ['audienceStrict' => true, 'audience' => '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}'];
		$context = $this->createContext([self::assignRule(1, self::usCondition())], $audience);
		$context->setAttribute('country', 'US');
		$context->setAttribute('age', 15);

		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertFalse($this->publishedExposures($context)[0]->audienceMismatch);
	}

	public function testStrictAudienceAppliesWhenNoRuleMatches(): void {
		$audience = ['audienceStrict' => true, 'audience' => '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}'];
		$context = $this->createContext([self::assignRule(1, self::usCondition())], $audience);
		$context->setAttribute('age', 15);

		self::assertSame(0, $context->getTreatment('exp_test_abc'));
		$exposures = $this->publishedExposures($context);
		self::assertTrue($exposures[0]->audienceMismatch);
		self::assertFalse($exposures[0]->ruleOverride);
	}

	public function testRuleWinsOverCustomAssignmentAndIsExposedOnce(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())]);
		$context->setAttribute('country', 'US');
		$context->setCustomAssignment('exp_test_abc', 2);

		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertCount(1, $this->publishedExposures($context));
	}

	public function testOutOfRangeRuleVariantFallsBackToNormalAssignment(): void {
		self::assertSame(self::NORMAL_VARIANT, $this->createContext([self::assignRule(99, null)])->getTreatment('exp_test_abc'));
		self::assertSame(self::NORMAL_VARIANT, $this->createContext([self::assignRule(-1, null)])->getTreatment('exp_test_abc'));
	}

	public function testInvalidRulesFallBackToNormalAssignment(): void {
		$context = $this->createContext([], ['assignmentRules' => 'not-valid-json{{{']);
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_test_abc'));

		$context = $this->createContext([], ['assignmentRules' => (object) ['rules' => [self::assignRule(1, null)]]]);
		self::assertFalse($context->isFailed());
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_test_abc'));
	}

	// Rule conditions are re-evaluated when attributes change after an assignment is cached.
	public function testCachedAssignmentIsRebuiltWhenARuleStartsMatching(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())]);
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_test_abc'));

		$context->setAttribute('country', 'US');
		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertCount(2, $this->publishedExposures($context));
	}

	public function testCachedAssignmentIsRebuiltWhenARuleStopsMatching(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())]);
		$context->setAttribute('country', 'US');
		self::assertSame(1, $context->getTreatment('exp_test_abc'));

		$context->setAttribute('country', 'GB');
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_test_abc'));
	}

	public function testCachedAssignmentFollowsTheFirstMatchingRule(): void {
		$rules = [
			self::assignRule(1, self::usCondition()),
			self::assignRule(2, ['and' => [['eq' => [['var' => 'country'], ['value' => 'GB']]]]]),
			self::assignRule(0, ['and' => [['eq' => [['var' => 'country'], ['value' => 'FR']]]]]),
		];
		$context = $this->createContext($rules);

		foreach (['US' => 1, 'GB' => 2, 'FR' => 0, 'DE' => self::NORMAL_VARIANT] as $country => $variant) {
			$context->setAttribute('country', $country);
			self::assertSame($variant, $context->getTreatment('exp_test_abc'), $country);
		}
	}

	public function testUnrelatedAttributeKeepsTheCachedRuleAssignment(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())]);
		$context->setAttribute('country', 'US');
		self::assertSame(1, $context->getTreatment('exp_test_abc'));

		$context->setAttribute('unrelated', true);
		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertCount(1, $this->publishedExposures($context));
	}

	// With the experiment seed the unit hashes to ~0.748, and with the traffic seed to ~0.682, so each way of
	// resolving the split lands on a different variant than the normal assignment.
	public function testSplitRuleHashesTheUnitWithTheExperimentSeed(): void {
		$context = $this->createContext([self::splitRule('70/10/20', self::usCondition())]);
		$context->setAttribute('country', 'US');

		self::assertSame(1, $context->getTreatment('exp_test_abc'));
	}

	public function testSplitRuleExposureFlags(): void {
		$context = $this->createContext([self::splitRule('0/100/0', self::usCondition())]);
		$context->setAttribute('country', 'US');
		$context->setCustomAssignment('exp_test_abc', 2);
		self::assertSame(1, $context->getTreatment('exp_test_abc'));

		$exposure = $this->publishedExposures($context)[0];
		self::assertSame(1, $exposure->variant);
		self::assertFalse($exposure->assigned);
		self::assertTrue($exposure->eligible);
		self::assertFalse($exposure->custom);
		self::assertTrue($exposure->ruleOverride);
	}

	public function testSplitNotCoveringEveryVariantStopsRuleEvaluation(): void {
		$context = $this->createContext([self::splitRule('0/100', self::usCondition()), self::assignRule(1, null)]);
		$context->setAttribute('country', 'US');

		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_test_abc'));
	}

	public function testSplitRuleWithoutItsUnitIsReResolvedOnceTheUnitIsSet(): void {
		$context = $this->createContext([self::splitRule('0/100/0', self::usCondition()), self::assignRule(2, null)], [], false);
		$context->setAttribute('country', 'US');
		self::assertSame(0, $context->peekTreatment('exp_test_abc'));

		$context->setUnit('session_id', self::SESSION_ID);
		self::assertSame(1, $context->peekTreatment('exp_test_abc'));
	}

	public function testExposedSplitRuleWithoutItsUnitStaysPinned(): void {
		$context = $this->createContext([self::splitRule('0/100/0', self::usCondition())], [], false);
		$context->setAttribute('country', 'US');
		$context->setCustomAssignment('exp_test_abc', 2);
		self::assertSame(0, $context->getTreatment('exp_test_abc'));

		$context->setUnit('session_id', self::SESSION_ID);
		$context->setAttribute('unrelated', true);
		self::assertSame(0, $context->getTreatment('exp_test_abc'));

		$variants = array_map(static fn($exposure) => $exposure->variant, $this->publishedExposures($context));
		self::assertSame([0], $variants);
	}

	public function testSplitRuleWithoutItsUnitGivesTheFullOnVariant(): void {
		$context = $this->createContext([self::splitRule('0/100/0', null)], ['fullOnVariant' => 2], false);
		self::assertSame(2, $context->peekTreatment('exp_test_abc'));

		$context->setUnit('session_id', self::SESSION_ID);
		self::assertSame(1, $context->peekTreatment('exp_test_abc'));
	}

	public function testAssignRuleIsKeptWhenTheUnitIsSetLater(): void {
		$context = $this->createContext([self::assignRule(1, self::usCondition())], [], false);
		$context->setAttribute('country', 'US');
		self::assertSame(1, $context->getTreatment('exp_test_abc'));

		$context->setUnit('session_id', self::SESSION_ID);
		self::assertSame(1, $context->getTreatment('exp_test_abc'));
		self::assertCount(1, $this->publishedExposures($context));
	}

	public function testRefreshRemovingRulesRebuildsTheAssignment(): void {
		$context = $this->createContext([self::assignRule(1, null)]);
		self::assertSame(1, $context->getTreatment('exp_test_abc'));

		// The data provider serves context.json, which has no assignment rules.
		$context->refresh();
		self::assertSame(self::NORMAL_VARIANT, $context->getTreatment('exp_test_abc'));
	}
}

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
 * Shared variable keys, as in the Java SDK's Context.getVariableAssignment: the experiments whose variants define the
 * key are the candidates, in ascending id order. The first candidate the unit is assigned to, overridden into or
 * rule-assigned to wins, and only the winner is exposed. When the winner's variant lacks the key the caller default
 * is returned; resolution never falls through to a later candidate because of the arm the unit got.
 */
class ContextSharedVariableKeyTest extends TestCase {
	private const SESSION_ID = 'e791e240fcd3df7d238cfc285f475e8152fcc0ec';

	private ContextEventHandlerMock $eventHandler;

	/**
	 * A two-variant experiment on session_id, full-on to variant 1 unless $fields says otherwise. $configs holds the
	 * variant configs (null means no variables).
	 */
	private static function experiment(int $id, string $name, array $configs, array $fields = []): object {
		$variants = [];
		foreach ($configs as $i => $config) {
			$variants[] = (object) ['name' => chr(65 + $i), 'config' => $config === null ? null : json_encode($config)];
		}

		$experiment = (object) [
			'id' => $id,
			'name' => $name,
			'iteration' => 1,
			'unitType' => 'session_id',
			'seedHi' => $id * 11,
			'seedLo' => $id * 13,
			'split' => array_fill(0, count($configs), 1 / count($configs)),
			'trafficSeedHi' => $id * 17,
			'trafficSeedLo' => $id * 19,
			'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => 1,
			'applications' => [(object) ['name' => 'website']],
			'variants' => $variants,
			'audience' => null,
		];
		foreach ($fields as $field => $value) {
			$experiment->{$field} = $value;
		}

		return $experiment;
	}

	/** A strict audience that a unit without an "age" attribute of at least 100 does not match. */
	private static function strictAudience(): array {
		return [
			'audienceStrict' => true,
			'audience' => json_encode(['filter' => [['gte' => [['var' => 'age'], ['value' => 100]]]]]),
		];
	}

	/** A lowest-id candidate the unit is never in (strict audience mismatch): checked first, it must never be exposed. */
	private static function loser(): object {
		return self::experiment(0, 'exp_loser', [['shared' => 'loser-control'], ['shared' => 'loser']], self::strictAudience());
	}

	/** An assign rule that gives $variant to every unit. */
	private static function assignRule(int $variant): array {
		return ['assignmentRules' => json_encode(['rules' => [[
			'name' => 'rule', 'type' => 'assign', 'conditions' => null, 'environments' => [], 'variant' => $variant,
		]]])];
	}

	private function createContext(array $experiments): Context {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', '', 'website', 'production'));
		$config = new Config($client);
		$config->setContextDataProvider(new ContextDataProviderMock($client));
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::SESSION_ID]);

		return (new SDK($config))->createContextWithData($contextConfig, new ContextData($experiments));
	}

	/** @return string[] the names of the published exposures, in order */
	private function publishedExposureNames(Context $context): array {
		$context->publish();
		$names = [];
		foreach ($this->eventHandler->submitted as $event) {
			foreach ($event->exposures ?? [] as $exposure) {
				$names[] = $exposure->name;
			}
		}

		return $names;
	}

	public function testStrictAudienceMismatchedLowerIdIsNotExposedAndTheHigherIdWins(): void {
		// The higher id is listed first: candidates are ordered by id, not by payload order.
		$context = $this->createContext([
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['shared' => 'low']], self::strictAudience()),
		]);

		self::assertSame('high', $context->getVariableValue('shared', 'def'));
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(['exp_high'], $this->publishedExposureNames($context));
	}

	public function testMissingUnitLowerIdIsNotExposedAndTheHigherIdWins(): void {
		$context = $this->createContext([
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['shared' => 'low']], ['unitType' => 'device_id', 'fullOnVariant' => 0]),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);

		self::assertSame('high', $context->getVariableValue('shared', 'def'));
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(['exp_high'], $this->publishedExposureNames($context));
	}

	public function testWinnerVariantWithoutTheKeyReturnsTheDefaultAndExposesOnlyTheWinner(): void {
		// exp_low is full-on to variant 1, which does not define the key; variant 0 does, so it is a candidate.
		$context = $this->createContext([
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['other' => 'x']]),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);

		self::assertSame('def', $context->getVariableValue('shared', 'def'));
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testWinnerVariantWithNoVariablesReturnsTheDefaultAndExposesOnlyTheWinner(): void {
		$context = $this->createContext([
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], null]),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);

		self::assertSame('def', $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testAnOverrideToAVariantTheExperimentDoesNotHaveReturnsTheDefaultAndExposesOnlyIt(): void {
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['shared' => 'low']]),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);
		$context->setOverride('exp_low', 7);

		self::assertSame('def', $context->peekVariableValue('shared', 'def'));
		self::assertSame(0, $context->getPendingCount());
		self::assertSame('def', $context->getVariableValue('shared', 'def'));
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testATrafficExcludedCandidateStillWinsWithItsControlValue(): void {
		// Outside the traffic split the unit is still assigned (not eligible, variant 0), so it is in the experiment.
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['shared' => 'low']], ['trafficSplit' => [1.0, 0.0], 'fullOnVariant' => 0]),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);

		self::assertSame('low-control', $context->getVariableValue('shared', 'def'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();
		$exposures = $this->eventHandler->submitted[0]->exposures;
		self::assertCount(1, $exposures);
		self::assertSame('exp_low', $exposures[0]->name);
		self::assertTrue($exposures[0]->assigned);
		self::assertFalse($exposures[0]->eligible);
	}

	public function testNoCandidateAssignedReturnsTheDefaultAndExposesNothing(): void {
		$context = $this->createContext([
			self::experiment(1, 'exp_strict', [['shared' => 'a-control'], ['shared' => 'a']], self::strictAudience()),
			self::experiment(2, 'exp_no_unit', [['shared' => 'b-control'], ['shared' => 'b']], ['unitType' => 'device_id', 'fullOnVariant' => 0]),
		]);

		self::assertSame('def', $context->getVariableValue('shared', 'def'));
		self::assertSame(0, $context->getPendingCount());
		self::assertSame('def', $context->peekVariableValue('shared', 'def'));
		self::assertSame(0, $context->getPendingCount());
	}

	public function testSingleStrictAudienceMismatchReturnsTheDefaultAndExposesNothing(): void {
		$context = $this->createContext([
			self::experiment(1, 'exp_strict', [['shared' => 'control'], ['shared' => 'a']], self::strictAudience()),
		]);

		self::assertSame('def', $context->getVariableValue('shared', 'def'));
		self::assertSame(0, $context->getPendingCount());
	}

	public function testPeekReturnsTheWinnerValueAndExposesNothing(): void {
		$context = $this->createContext([
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['shared' => 'low']], self::strictAudience()),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
			self::experiment(3, 'exp_lacks', [['c' => 'control'], ['other' => 'x']]),
			self::experiment(4, 'exp_c', [null, ['c' => 'later']]),
		]);

		self::assertSame('high', $context->peekVariableValue('shared', 'def'));
		self::assertSame('def', $context->peekVariableValue('c', 'def'));
		self::assertSame(0, $context->getPendingCount());

		// A later get exposes the winner only once.
		self::assertSame('high', $context->getVariableValue('shared', 'def'));
		self::assertSame('high', $context->getVariableValue('shared', 'def'));
		self::assertSame(1, $context->getPendingCount());
		self::assertSame(['exp_high'], $this->publishedExposureNames($context));
	}

	public function testBothAssignedTheLowestIdWinsAndOnlyItIsExposed(): void {
		$context = $this->createContext([
			self::loser(),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
			self::experiment(1, 'exp_low', [null, ['shared' => 'low']]),
		]);

		self::assertSame('low', $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testOverrideOnAnUnassignedLowerIdWins(): void {
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['shared' => 'low']], self::strictAudience()),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);
		$context->setOverride('exp_low', 1);

		self::assertSame('low', $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testOverrideOnTheLowerIdToAVariantWithoutTheKeyReturnsTheDefault(): void {
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [['other' => 'x'], ['shared' => 'low']], self::strictAudience()),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);
		$context->setOverride('exp_low', 0);

		self::assertSame('def', $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testOverrideOnTheHigherIdDoesNotBeatAnAssignedLowerId(): void {
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [null, ['shared' => 'low']]),
			self::experiment(2, 'exp_high', [['shared' => 'high-control'], ['shared' => 'high']]),
		]);
		$context->setOverride('exp_high', 0);

		self::assertSame('low', $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testRuleAssignmentOnAStrictMismatchedLowerIdWins(): void {
		// A matching rule wins over the strict audience, so the lower id is rule-assigned and wins.
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['shared' => 'low']], self::strictAudience() + self::assignRule(0)),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);

		self::assertSame('low-control', $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testRuleAssignmentToAVariantWithoutTheKeyReturnsTheDefault(): void {
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [['other' => 'x'], ['shared' => 'low']], self::assignRule(0)),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);

		self::assertSame('def', $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testOverrideWinsOverARuleOnTheSameCandidate(): void {
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [['shared' => 'low-control'], ['shared' => 'low']], self::assignRule(0)),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);
		$context->setOverride('exp_low', 1);

		self::assertSame('low', $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function presentFalsyValues(): array {
		return [
			'null' => [null],
			'false' => [false],
			'zero' => [0],
			'empty string' => [''],
			'string zero' => ['0'],
			'empty array' => [[]],
		];
	}

	/**
	 * @dataProvider presentFalsyValues
	 */
	public function testAPresentFalsyValueOnTheWinnerIsReturnedNotTheDefault($value): void {
		$context = $this->createContext([
			self::loser(),
			self::experiment(1, 'exp_low', [null, ['shared' => $value]]),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);

		self::assertSame($value, $context->peekVariableValue('shared', 'def'));
		self::assertSame($value, $context->getVariableValue('shared', 'def'));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testAMissingKeyOnTheWinnerIsTheDefaultEvenWhenTheDefaultIsNull(): void {
		$context = $this->createContext([
			self::experiment(1, 'exp_low', [['shared' => 'control'], ['other' => null]]),
			self::experiment(2, 'exp_high', [null, ['shared' => 'high']]),
		]);

		self::assertNull($context->getVariableValue('shared'));
		self::assertSame(0, $context->getVariableValue('shared', 0));
		self::assertSame(['exp_low'], $this->publishedExposureNames($context));
	}

	public function testAnUnknownKeyReturnsTheDefaultAndExposesNothing(): void {
		$context = $this->createContext([self::experiment(1, 'exp_low', [null, ['shared' => 'low']])]);

		self::assertSame('def', $context->getVariableValue('unknown', 'def'));
		self::assertSame(0, $context->getPendingCount());
	}
}

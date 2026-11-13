<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\AudienceMatcher;
use PHPUnit\Framework\TestCase;
use stdClass;

class AudienceMatcherTest extends TestCase {
	private AudienceMatcher $matcher;

	protected function setUp(): void {
		$this->matcher = new AudienceMatcher();
	}

	public function testShouldReturnNullOnEmptyAudience(): void {
		$audience = new stdClass();
		self::assertNull($this->matcher->evaluate($audience, []));
	}

	public function testShouldReturnNullIfFilterNotObjectOrArray(): void {
		$audience = (object) ['filter' => null];
		self::assertNull($this->matcher->evaluate($audience, []));

		self::assertNull($this->matcher->evaluate((object) ['filter' => 'invalid'], []));
		self::assertNull($this->matcher->evaluate((object) ['filter' => 1], []));

		$audience2 = new stdClass();
		self::assertNull($this->matcher->evaluate($audience2, []));
	}

	public function testShouldReturnBoolean(): void {
		$audience = (object) [
			'filter' => [
				(object) ['gte' => [
					(object) ['var' => ['path' => 'age']],
					(object) ['value' => 20],
				]],
			],
		];

		self::assertTrue($this->matcher->evaluate($audience, ['age' => 25]));
		self::assertFalse($this->matcher->evaluate($audience, ['age' => 15]));
	}

	private function evaluateRules(array $rules, ?string $environment = 'production', array $attributes = []): ?array {
		return $this->matcher->evaluateRules(json_encode(['rules' => $rules]), $environment, $attributes);
	}

	private static function assignRule(int $variant, $conditions = null, array $environments = []): array {
		return ['name' => 'rule', 'type' => 'assign', 'conditions' => $conditions, 'environments' => $environments, 'variant' => $variant];
	}

	private static function splitRule($percentages): array {
		return [
			'name' => 'split',
			'type' => 'split',
			'conditions' => ['and' => [['eq' => [['var' => 'country'], ['value' => 'US']]]]],
			'environments' => [],
			'percentages' => $percentages,
		];
	}

	public function testRulesReturnTheFirstMatchingAction(): void {
		$us = ['and' => [['eq' => [['var' => 'country'], ['value' => 'US']]]]];
		$gb = ['and' => [['eq' => [['var' => 'country'], ['value' => 'GB']]]]];
		$rules = [self::assignRule(1, $gb), self::assignRule(2, $us), self::assignRule(0, $us)];

		self::assertSame(['variant' => 2], $this->evaluateRules($rules, 'production', ['country' => 'US']));
		self::assertNull($this->evaluateRules($rules, 'production', ['country' => 'FR']));
		self::assertSame(['variant' => 0], $this->evaluateRules([self::assignRule(0)]));
	}

	public function testMalformedRulesGiveNull(): void {
		self::assertNull($this->matcher->evaluateRules('not-valid-json{{{', 'production', []));
		self::assertNull($this->matcher->evaluateRules('{"rules":"not an array"}', 'production', []));
		self::assertNull($this->matcher->evaluateRules('{"rules":[null]}', 'production', []));
		self::assertNull($this->matcher->evaluateRules('[]', 'production', []));
	}

	public function testInvalidRulesAreSkipped(): void {
		$fallback = self::assignRule(2);
		$rule = static fn(array $fields): array => array_merge(['name' => 'bad', 'environments' => []], $fields);

		self::assertSame(['variant' => 2], $this->evaluateRules([$rule(['type' => 'assign', 'variant' => 'bad']), $fallback]));
		self::assertSame(['variant' => 2], $this->evaluateRules([$rule(['type' => 'assign']), $fallback]));
		self::assertSame(['variant' => 2], $this->evaluateRules([$rule(['type' => 'assign', 'variant' => 1.5]), $fallback]));
		self::assertSame(['variant' => 2], $this->evaluateRules([$rule(['type' => 'other', 'variant' => 1]), $fallback]));
		self::assertSame(['variant' => 2], $this->evaluateRules([$rule(['variant' => 1]), $fallback]));
		self::assertSame(['variant' => 2], $this->evaluateRules([$rule(['type' => 'assign', 'variant' => 1, 'conditions' => 'invalid']), $fallback]));
		self::assertNull($this->evaluateRules([$rule(['type' => 'assign', 'variant' => 1, 'environments' => 'production'])]));
	}

	public function testIntegralFloatVariantIsAnInteger(): void {
		self::assertSame(['variant' => 2], $this->matcher->evaluateRules('{"rules":[{"type":"assign","variant":2.0}]}', 'production', []));
	}

	public function testOutOfRangeVariantIsLeftToTheCaller(): void {
		self::assertSame(['variant' => -1], $this->evaluateRules([self::assignRule(-1)]));
	}

	public function testEnvironmentsScopeRules(): void {
		$rules = [self::assignRule(1, null, ['production', 'staging'])];

		self::assertSame(['variant' => 1], $this->evaluateRules($rules, 'staging'));
		self::assertNull($this->evaluateRules($rules, 'development'));
		self::assertNull($this->evaluateRules($rules, null));
		self::assertNull($this->evaluateRules([self::assignRule(1, null, ['Production'])], 'production'));
		self::assertSame(['variant' => 1], $this->evaluateRules([self::assignRule(1)], null));
	}

	public function testSplitRulesReturnFractions(): void {
		$us = ['country' => 'US'];

		self::assertSame(['split' => [0.2, 0.3, 0.5]], $this->evaluateRules([self::splitRule('20/30/50')], 'production', $us));
		self::assertSame(['split' => [0.3333, 0.3333, 0.3333]], $this->evaluateRules([self::splitRule('33.33/33.33/33.33')], 'production', $us));
		self::assertNull($this->evaluateRules([self::splitRule('50/50')], 'production', ['country' => 'GB']));
	}

	public function invalidSplitProvider(): array {
		return [
			'not a string' => [[50, 50]],
			'not numeric' => ['50/abc'],
			'negative' => ['150/-50'],
			'below 100' => ['50/49'],
			'gap rounds to 0.02 above' => ['50/50.015'],
			'gap rounds to 0.02 below' => ['50/49.985'],
		];
	}

	/**
	 * @dataProvider invalidSplitProvider
	 */
	public function testInvalidSplitRulesAreSkipped($percentages): void {
		$rules = [self::splitRule($percentages), self::assignRule(2)];
		self::assertSame(['variant' => 2], $this->evaluateRules($rules, 'production', ['country' => 'US']));
	}

	public function testSplitSumToleranceIsRoundedToTwoDecimals(): void {
		foreach (['50/50.014', '50/49.986'] as $percentages) {
			$expected = array_map(static fn(string $value): float => (float) $value / 100, explode('/', $percentages));
			self::assertSame(['split' => $expected], $this->evaluateRules([self::splitRule($percentages)], 'production', ['country' => 'US']));
		}
	}

	public function testSplitPercentagesParseLikeJavaScriptParseFloat(): void {
		self::assertSame([0.5, 0.5], AudienceMatcher::parseSplitPercentages(' 50/50abc'));
		self::assertSame([1.0], AudienceMatcher::parseSplitPercentages('1e2'));
		self::assertNull(AudienceMatcher::parseSplitPercentages('50/'));
	}
}

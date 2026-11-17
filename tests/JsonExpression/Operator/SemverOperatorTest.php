<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Expression;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\JsonExpression\Operator\OperatorInterface;
use ABSmartly\SDK\JsonExpression\Operator\SemverEqOperator;
use ABSmartly\SDK\JsonExpression\Operator\SemverGteOperator;
use ABSmartly\SDK\JsonExpression\Operator\SemverGtOperator;
use ABSmartly\SDK\JsonExpression\Operator\SemverLteOperator;
use ABSmartly\SDK\JsonExpression\Operator\SemverLtOperator;
use ABSmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class SemverOperatorTest extends TestCase {
	private Evaluator $evaluator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
	}

	public function operatorProvider(): array {
		// [operator, result for 2.0.0 vs 1.0.0, 1.0.0 vs 1.0.0, 1.0.0 vs 2.0.0]
		return [
			'semver_eq' => [new SemverEqOperator(), false, true, false],
			'semver_gt' => [new SemverGtOperator(), true, false, false],
			'semver_gte' => [new SemverGteOperator(), true, true, false],
			'semver_lt' => [new SemverLtOperator(), false, false, true],
			'semver_lte' => [new SemverLteOperator(), false, true, true],
		];
	}

	/**
	 * @dataProvider operatorProvider
	 */
	public function testEvaluation(OperatorInterface $operator, bool $greater, bool $equal, bool $less): void {
		self::assertSame($greater, $operator->evaluate($this->evaluator, ['2.0.0', '1.0.0']));
		self::assertSame($equal, $operator->evaluate($this->evaluator, ['1.0.0', '1.0.0']));
		self::assertSame($less, $operator->evaluate($this->evaluator, ['1.0.0', '2.0.0']));
	}

	/**
	 * @dataProvider operatorProvider
	 */
	public function testNullAndInvalidOperandsGiveNull(OperatorInterface $operator): void {
		self::assertNull($operator->evaluate($this->evaluator, [null, '1.0.0']));
		self::assertNull($operator->evaluate($this->evaluator, ['1.0.0', null]));
		self::assertNull($operator->evaluate($this->evaluator, ['', '1.0.0']));
		self::assertNull($operator->evaluate($this->evaluator, ['v', '1.0.0']));
	}

	public function testOperatorsAreRegisteredForAudienceExpressions(): void {
		$expression = new Expression();
		$vars = (object) ['app_version' => '2.4.1'];
		$evaluate = static function (string $operator, string $version) use ($expression, $vars): bool {
			$expr = json_decode(sprintf('{"%s":[{"var":"app_version"},{"value":"%s"}]}', $operator, $version), false);
			return $expression->evaluateBooleanExpr($expr, $vars);
		};

		self::assertTrue($evaluate('semver_gte', '2.4.0'));
		self::assertTrue($evaluate('semver_lt', '2.10.0'));
		self::assertFalse($evaluate('semver_eq', '2.4.1-beta'));
		self::assertTrue($evaluate('semver_gt', 'v2.4.1-rc.1'));
		self::assertTrue($evaluate('semver_lte', '2.4.1+build.7'));
	}
}

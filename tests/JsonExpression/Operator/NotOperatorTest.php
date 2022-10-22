<?php

namespace Absmartly\SDK\Tests\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;
use Absmartly\SDK\JsonExpression\Operator\NotOperator;
use Absmartly\SDK\JsonExpression\Operator\NullOperator;
use Absmartly\SDK\JsonExpression\Operator\OperatorCollection;
use Absmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class NotOperatorTest extends TestCase {
	public function testTest(): void {
		$evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$operator = new NotOperator();

		self::assertTrue($operator->evaluate($evaluator, null));
		self::assertTrue($operator->evaluate($evaluator, 0));
		self::assertFalse($operator->evaluate($evaluator, []));
		self::assertFalse($operator->evaluate($evaluator, new \stdClass()));
		self::assertTrue($operator->evaluate($evaluator, ""));
		self::assertTrue($operator->evaluate($evaluator, "false"));
		self::assertFalse($operator->evaluate($evaluator, true));
		self::assertFalse($operator->evaluate($evaluator, 1));
	}
}

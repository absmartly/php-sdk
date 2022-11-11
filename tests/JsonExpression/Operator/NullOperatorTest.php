<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Operator\NullOperator;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class NullOperatorTest extends TestCase {
	public function testTest(): void {
		$evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$operator = new NullOperator();
		self::assertTrue($operator->evaluate($evaluator, null));
		self::assertFalse($operator->evaluate($evaluator, ""));
	}
}

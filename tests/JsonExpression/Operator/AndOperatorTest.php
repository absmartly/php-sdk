<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Operator\AndOperator;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\JsonExpression\Operator\OperatorInterface;
use ABSmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class AndOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new AndOperator();
	}

	public function testEvaluation(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, [true]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [false]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [null]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [true, false, true]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [true, true]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [true, true, true]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [true, false]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [false, false]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [false, false, false]));
	}
}

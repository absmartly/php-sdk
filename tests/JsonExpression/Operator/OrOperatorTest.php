<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\JsonExpression\Operator\OperatorInterface;
use ABSmartly\SDK\JsonExpression\Operator\OrOperator;
use ABSmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class OrOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new OrOperator();
	}

	public function testEvaluation(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, [true]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [false]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [null]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [null, true]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [true, false, true])); /** @todo check call times from the mock */
		self::assertTrue($this->operator->evaluate($this->evaluator, [true, true]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [true, true, true]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [true, false]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [false, false]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [false, false, false]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [false, false, false, false, false, false, true]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [false, false, false, false, false, false, false]));
	}
}

<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Operator\LtOperator;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\JsonExpression\Operator\OperatorInterface;
use ABSmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class LtOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new LtOperator();
	}

	public function testEvaluation(): void {
		self::assertFalse($this->operator->evaluate($this->evaluator, [0, 0]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [1, 0]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [0, 1]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [null, null])); /** @todo Deviation */

		self::assertNull($this->operator->evaluate($this->evaluator, [null, 1]));
		self::assertNull($this->operator->evaluate($this->evaluator, [1, null]));
		self::assertNull($this->operator->evaluate($this->evaluator, [0, null]));
		self::assertNull($this->operator->evaluate($this->evaluator, [null, 0]));
	}
}

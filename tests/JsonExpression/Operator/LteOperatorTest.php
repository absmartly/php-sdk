<?php

namespace Absmartly\SDK\Tests\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;
use Absmartly\SDK\JsonExpression\Operator\LteOperator;
use Absmartly\SDK\JsonExpression\Operator\OperatorCollection;
use Absmartly\SDK\JsonExpression\Operator\OperatorInterface;
use Absmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class LteOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new LteOperator();
	}

	public function testEvaluation(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, [0, 0]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [1, 0]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [0, 1]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [null, null])); /** @todo Deviation */

		self::assertNull($this->operator->evaluate($this->evaluator, [null, 1]));
		self::assertNull($this->operator->evaluate($this->evaluator, [1, null]));
		self::assertNull($this->operator->evaluate($this->evaluator, [0, null]));
		self::assertNull($this->operator->evaluate($this->evaluator, [null, 0]));
	}
}

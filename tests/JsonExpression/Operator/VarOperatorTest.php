<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\JsonExpression\Operator\OperatorInterface;
use ABSmartly\SDK\JsonExpression\Operator\VarOperator;
use ABSmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class VarOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
	$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
	$this->operator = new VarOperator();
}

	public function testEvaluationReturnsVar(): void {
		$this->assertSame('abc', $this->operator->evaluate($this->evaluator, 'a/b/c')); // Comes from the Mock
	}
}

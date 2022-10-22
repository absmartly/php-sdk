<?php

namespace Absmartly\SDK\Tests\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;
use Absmartly\SDK\JsonExpression\Operator\OperatorCollection;
use Absmartly\SDK\JsonExpression\Operator\OperatorInterface;
use Absmartly\SDK\JsonExpression\Operator\VarOperator;
use Absmartly\SDK\Tests\JsonExpression\MockEvaluator;
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

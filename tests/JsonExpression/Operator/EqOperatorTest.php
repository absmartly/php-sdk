<?php

namespace Absmartly\SDK\Tests\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;
use Absmartly\SDK\JsonExpression\Operator\EqOperator;
use Absmartly\SDK\JsonExpression\Operator\OperatorCollection;
use Absmartly\SDK\JsonExpression\Operator\OperatorInterface;
use Absmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class EqOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new EqOperator();
	}

	public function testEvaluation(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, [0, 0]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [1, 0]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [0, 1]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [null, null])); /** @todo Deviation */
		self::assertTrue($this->operator->evaluate($this->evaluator, [[1, 2], [1, 2]]));
		self::assertNull($this->operator->evaluate($this->evaluator, [[1, 2], [3, 4]]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [(object)['a' => 1, 'b' => 2], (object)['a' => 1, 'b' => 2]]));
		self::assertNull($this->operator->evaluate($this->evaluator, [(object)['a' => 1, 'b' => 2], (object)['a' => 3, 'b' => 4]]));
	}
}

<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Operator\InOperator;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\JsonExpression\Operator\OperatorInterface;
use ABSmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class InOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new InOperator();
	}

	public function testStringInString(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abc", "abcdefghijk"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["def", "abcdefghijk"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["xxx", "abcdefghijk"]));

		self::assertNull($this->operator->evaluate($this->evaluator, [null, "abcdefghijk"]));
	}

	public function testReturnFalseOnEmptyArray(): void {
		self::assertFalse($this->operator->evaluate($this->evaluator, [false, []]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["1", []]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [true, []]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [false, []]));

		self::assertNull($this->operator->evaluate($this->evaluator, [null, []]));
	}

	public function testArrayContainsValue(): void {
		$haystack01 = [0, 1];
		$haystack12 = [1, 2];
		$haystackabKeys = ['a' => 5, 'b' => 6];

		self::assertFalse($this->operator->evaluate($this->evaluator, [2, $haystack01]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [0, $haystack12]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [1, $haystack12]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [2, $haystack12]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ['a', $haystackabKeys]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ['b', $haystackabKeys]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [5, $haystackabKeys]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [6, $haystackabKeys]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [7, $haystackabKeys]));
	}

	public function testObjectContainsProperty(): void {
		$haystackab = (object) ['a' => 1, 'b' => 2 ];
		$haystackbc = (object) ['b' => 2, 'c' => 3, 0 => 100];

		self::assertFalse($this->operator->evaluate($this->evaluator, ['c', $haystackab]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ['b', $haystackab]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ['b', $haystackbc]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [0, $haystackbc]));
	}

	public function testArrayDiffNull(): void {
		self::assertFalse($this->operator->evaluate($this->evaluator, [[2, 3], [1, 2, 3]]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [[5, 6], [1, 2, 3]]));
	}
}

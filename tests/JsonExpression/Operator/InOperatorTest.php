<?php

namespace Absmartly\SDK\Tests\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;
use Absmartly\SDK\JsonExpression\Operator\InOperator;
use Absmartly\SDK\JsonExpression\Operator\OperatorCollection;
use Absmartly\SDK\JsonExpression\Operator\OperatorInterface;
use Absmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class InOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new InOperator();
	}

	public function testStringInString(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "abc"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "def"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["abcdefghijk", "xxx"]));

		self::assertNull($this->operator->evaluate($this->evaluator, ["abcdefghijk", null]));
	}

	public function testReturnFalseOnEmptyArray(): void {
		self::assertFalse($this->operator->evaluate($this->evaluator, [[], false]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [[], "1"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [[], true]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [[], false]));

		self::assertNull($this->operator->evaluate($this->evaluator, [[], null]));
	}

	public function testArrayContainsValue(): void {
		$haystack01 = [0, 1];
		$haystack12 = [1, 2];
		$haystackabKeys = ['a' => 5, 'b' => 6];

		self::assertFalse($this->operator->evaluate($this->evaluator, [$haystack01, 2]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [$haystack12, 0]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [$haystack12, 1]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [$haystack12, 2]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [$haystackabKeys, 'a']));
		self::assertFalse($this->operator->evaluate($this->evaluator, [$haystackabKeys, 'b']));
		self::assertTrue($this->operator->evaluate($this->evaluator, [$haystackabKeys, 5]));
		self::assertTrue($this->operator->evaluate($this->evaluator, [$haystackabKeys, 6]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [$haystackabKeys, 7]));
	}

	public function testObjectContainsProperty(): void {
		$haystackab = (object) ['a' => 1, 'b' => 2 ];
		$haystackbc = (object) ['b' => 2, 'c' => 3, 0 => 100];

		self::assertFalse($this->operator->evaluate($this->evaluator, [$haystackab, 'c']));
		self::assertTrue($this->operator->evaluate($this->evaluator, [$haystackab, 'b']));
		self::assertTrue($this->operator->evaluate($this->evaluator, [$haystackbc, 'b']));
		self::assertTrue($this->operator->evaluate($this->evaluator, [$haystackbc, 0]));
	}

	public function testArrayDiffNull(): void {
		self::assertFalse($this->operator->evaluate($this->evaluator, [[1, 2, 3], [2, 3]]));
		self::assertFalse($this->operator->evaluate($this->evaluator, [[1, 2, 3], [5, 6]]));
	}
}

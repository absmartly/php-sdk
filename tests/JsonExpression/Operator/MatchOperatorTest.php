<?php

namespace Absmartly\SDK\Tests\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;
use Absmartly\SDK\JsonExpression\Operator\MatchOperator;
use Absmartly\SDK\JsonExpression\Operator\OperatorCollection;
use Absmartly\SDK\JsonExpression\Operator\OperatorInterface;
use Absmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class MatchOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new MatchOperator();
	}

	public function testRegexMatches(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", ""]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "abc"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "ijk"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "ijk"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "^abc"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "ijk$"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "def"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "b.*j"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["abcdefghijk", "xyz"]));
	}

	public function testRegexAutoBoundaries(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "//"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/abc/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/ijk/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/ijk/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/^abc/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/ijk$/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/def/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/b.*j/"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/xyz/"]));
	}
}

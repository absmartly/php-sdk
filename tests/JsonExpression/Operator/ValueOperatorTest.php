<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use PHPUnit\Framework\TestCase;

class ValueOperatorTest extends TestCase {
	public function testTest(): void {
		$evaluator = new Evaluator(new OperatorCollection(), new \stdClass());
		$expression = (object) [
			'value' => 'foo',
		];

		self::assertSame('foo', $evaluator->evaluate($expression));

		$expression->value = 'bar';
		self::assertSame('bar', $evaluator->evaluate($expression));

		$expression->value = null;
		self::assertNull($evaluator->evaluate($expression));
	}
}

<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

class NotOperator extends UnaryOperator implements OperatorInterface {
	public function unary(Evaluator $evaluator, $arg): bool {
		return !$evaluator::booleanConvert($arg);
	}
}

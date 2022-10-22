<?php

namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

class NotOperator extends UnaryOperator implements OperatorInterface {
	public function unary(Evaluator $evaluator, $arg): bool {
		return !$evaluator::booleanConvert($arg);
	}
}

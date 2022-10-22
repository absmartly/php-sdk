<?php

namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

class NullOperator extends UnaryOperator implements OperatorInterface {
	public function unary(Evaluator $evaluator, $arg): bool {
		return $arg === null;
	}
}

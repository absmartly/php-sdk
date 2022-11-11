<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

class ValueOperator implements OperatorInterface {

	public function evaluate(Evaluator $evaluator, $arg = null) {
		return $arg;
	}
}

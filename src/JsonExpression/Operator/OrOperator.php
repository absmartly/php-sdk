<?php

namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

class OrOperator extends BooleanCombinator {
	public function combine(Evaluator $evaluator, $args): bool {
		foreach ($args as $arg) {
			if ($evaluator->booleanConvert($evaluator->evaluate($arg))) {
				return true;
			}
		}

		return false;
	}
}

<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

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

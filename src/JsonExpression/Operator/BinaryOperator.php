<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

use function count;
use function is_array;

abstract class BinaryOperator implements OperatorInterface {
	abstract public function binary(Evaluator $evaluator, $lhs, $rhs): ?bool;

	public function evaluate(Evaluator $evaluator, $args = null): ?bool {
		if (!is_array($args) || count($args) < 2) {
			return null;
		}

		$lhs = $evaluator->evaluate($args[0]);
		$rhs = $evaluator->evaluate($args[1]);

		return $this->binary($evaluator, $lhs, $rhs);
	}
}

<?php
declare(strict_types=1);
namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

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

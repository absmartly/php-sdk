<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

abstract class UnaryOperator {
	abstract public function unary(Evaluator $evaluator, $arg): bool;

	public function evaluate(Evaluator $evaluator, $arg = null): ?bool {
		$arg = $evaluator->evaluate($arg);
		return $this->unary($evaluator, $arg);
	}
}

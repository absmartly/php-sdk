<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

use function is_array;

abstract class BooleanCombinator implements OperatorInterface {
	public function evaluate(Evaluator $evaluator, $args = null): ?bool {
		if (!is_array($args)) {
			return null;
		}

		return $this->combine($evaluator, $args);
	}

	abstract public function combine(Evaluator $evaluator, array $args);
}

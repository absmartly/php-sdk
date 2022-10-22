<?php

namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

abstract class BooleanCombinator implements OperatorInterface {
	public function evaluate(Evaluator $evaluator, $args = null) {
		if (!is_array($args)) {
			return null;
		}

		return $this->combine($evaluator, $args);
	}

	abstract public function combine(Evaluator $evaluator, array $args);
}

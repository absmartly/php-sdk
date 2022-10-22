<?php

namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

class GteOperator extends BinaryOperator {
	public function binary(Evaluator $evaluator, $lhs, $rhs): ?bool {
		$result = $evaluator->compare($lhs, $rhs);
		if ($result === null) {
			return null;
		}

		return $result >= 0;
	}
}

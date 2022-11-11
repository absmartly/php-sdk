<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

class EqOperator extends BinaryOperator {
	public function binary(Evaluator $evaluator, $lhs, $rhs): ?bool {
		$result = $evaluator->compare($lhs, $rhs);
		if ($result === null) {
			return null;
		}

		return $result === 0;
	}
}

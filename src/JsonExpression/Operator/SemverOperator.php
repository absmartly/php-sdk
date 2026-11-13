<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\SemverComparator;

abstract class SemverOperator extends BinaryOperator {
	abstract protected function matches(int $comparison): bool;

	public function binary(Evaluator $evaluator, $lhs, $rhs): ?bool {
		$comparison = SemverComparator::compare($lhs, $rhs);
		if ($comparison === null) {
			return null;
		}

		return $this->matches($comparison);
	}
}

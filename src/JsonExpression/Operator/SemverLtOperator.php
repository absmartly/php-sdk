<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

class SemverLtOperator extends SemverOperator {
	protected function matches(int $comparison): bool {
		return $comparison < 0;
	}
}

<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

use function function_exists;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function property_exists;
use function str_contains;
use function strpos;

class InOperator extends BinaryOperator {

	public function binary(Evaluator $evaluator, $lhs, $rhs): ?bool {
		if ($lhs === null) {
			return null;
		}

		if (is_array($rhs)) {
			return in_array($lhs, $rhs, false);
		}

		if (is_string($rhs)) {
			if (!is_string($lhs)) {
				return null;
			}
			//@codeCoverageIgnoreStart
			// due to version-dependent code
			if (function_exists('str_contains')) {
				return str_contains($rhs, $lhs);
			}
			return strpos($rhs, $lhs) !== false;
			// @codeCoverageIgnoreEnd
		}

		if (is_object($rhs)) {
			return property_exists($rhs, (string) $lhs);
		}

		return null;
	}
}

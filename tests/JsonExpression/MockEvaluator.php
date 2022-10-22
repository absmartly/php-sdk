<?php

namespace Absmartly\SDK\Tests\JsonExpression;

use Absmartly\SDK\JsonExpression\Evaluator;

class MockEvaluator extends Evaluator {
	public function evaluate($expr) {
		return $expr;
	}

/*	public static function booleanConvert($value): ?bool {
		return $value;
	}*/

	public static function numberConvert($value): ?float {
		return $value;
	}

	public static function stringConvert($value): ?string {
		return $value;
	}

	public function extractVar(string $path) {
		if ($path === 'a/b/c') {
			return 'abc';
		}

		return null;
	}

	/*public function compare($lhs, $rhs): ?int {
		switch (gettype($lhs)) {
			case "boolean":
			case "integer":
			case "double":
			case "string":
				if ($lhs === $rhs) {
					return 0;
				}
				return $lhs > $rhs ? 1 : -1;
			case "NULL":
				return null;
				//return $rhs === null ? 0 : null;
			default:
				if ((is_object($lhs) || is_array($lhs)) && static::isEqualsDeep($lhs, $rhs)) {
					return 0;
				}
				return null;
		}
	}*/

}


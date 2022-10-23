<?php

namespace Absmartly\SDK\Tests\JsonExpression;

use Absmartly\SDK\JsonExpression\Evaluator;

class MockEvaluator extends Evaluator {
	public function evaluate($expr) {
		return $expr;
	}

	public static function numberConvert($value): ?float {
		return $value;
	}

	public static function stringConvert($value): ?string {
		return $value;
	}

	public function extractVar(string $path): ?string {
		if ($path === 'a/b/c') {
			return 'abc';
		}

		return null;
	}
}


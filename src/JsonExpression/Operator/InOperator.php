<?php

namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

class InOperator extends BinaryOperator {

	public function binary(Evaluator $evaluator, $haystack, $needle): ?bool {
		if ($needle === null) {
			return null;
		}

		if (is_array($haystack)) {
			return in_array($needle, $haystack, true);
		}

		if (is_string($haystack)) {
			//@codeCoverageIgnoreStart
			// due to version-dependent code
			if (function_exists('str_contains')) {
				return str_contains($haystack, $needle); // Allows empty strings
			}
			return strpos($haystack, $needle) !== false;
			// @codeCoverageIgnoreEnd
		}

		if (is_object($haystack)) {
			return property_exists($haystack, $needle); // Not using isset() to account for possible null values.
		}

		return null;
	}
}

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
			return property_exists($haystack, (string) $needle); // Not using isset() to account for possible null values.
		}

		return null;
	}
}

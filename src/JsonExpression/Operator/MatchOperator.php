<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

use function preg_last_error;
use function preg_match;
use function trim;

use const PREG_NO_ERROR;

class MatchOperator extends BinaryOperator {

	public function binary(Evaluator $evaluator, $text, $pattern): ?bool {
		if ($text === null) {
			return false;
		}
		$pattern = $evaluator::stringConvert($pattern);
		if ($pattern === null) {
			return null;
		}

		return $this->runRegexBounded($text, $pattern);
	}

	private function runRegexBounded(string $text, string $pattern): ?bool {
		/*
		 * If the user-provided $pattern has forward slash delimiters, accept them. Any other patterns will
		 * automatically get forward slashes as delimiters.
		 *
		 * This is not ideal, because unlike JS, regexps are strings, and working with user-provided patterns is
		 * prone to either security issues (too eager regexps, or simply Regexp errors), or having to enforce delimiters
		 * at source.
		 */
		$matches = preg_match('/'. trim($pattern, '/') . '/', $text);

		if (preg_last_error() !== PREG_NO_ERROR) {
			return null;
		}

		return !empty($matches);
	}
}

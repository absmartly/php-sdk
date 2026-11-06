<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

use function preg_last_error;
use function preg_match;
use function preg_replace;
use function rtrim;
use function strlen;
use function trim;

use const PREG_NO_ERROR;

class MatchOperator extends BinaryOperator {

	public function binary(Evaluator $evaluator, $text, $pattern): ?bool {
		if ($text === null) {
			return false;
		}
		$text = $evaluator::stringConvert($text);
		if ($text === null) {
			return null;
		}
		$pattern = $evaluator::stringConvert($pattern);
		if ($pattern === null) {
			return null;
		}

		return $this->runRegexBounded($text, $pattern);
	}

	private function runRegexBounded(string $text, string $pattern): ?bool {
		$flags = '';
		if (preg_match('/^\/(.*)\/([imsxu]*)$/s', $pattern, $parts) === 1
			&& (strlen($parts[1]) - strlen(rtrim($parts[1], '\\'))) % 2 === 0) {
			// Conventional "/pattern/flags" form: keep the flags instead of folding them into the pattern.
			// An odd number of backslashes before the last "/" escapes it, so it is not a closing delimiter.
			[, $pattern, $flags] = $parts;
		}
		else {
			$pattern = trim($pattern, '/');
		}

		$pattern = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\~', $pattern);

		$matches = @preg_match('~' . $pattern . '~' . $flags, $text);

		if (preg_last_error() !== PREG_NO_ERROR || $matches === false) {
			return null;
		}

		return $matches === 1;
	}
}

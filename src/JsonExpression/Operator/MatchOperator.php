<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

use function preg_last_error;
use function preg_match;
use function strpos;
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
		// v1.0.3 and the JS SDK treat slashes literally: "/pattern/flags" is not parsed, it is matched as-is
		// after stripping leading/trailing slashes, so no flags are extracted.
		$pattern = trim($pattern, '/');

		$delimiter = self::unusedDelimiter($pattern);
		if ($delimiter === null) {
			return null;
		}

		$matches = @preg_match($delimiter . $pattern . $delimiter, $text);

		if (preg_last_error() !== PREG_NO_ERROR || $matches === false) {
			return null;
		}

		return $matches === 1;
	}

	private static function unusedDelimiter(string $pattern): ?string {
		// Escaping a delimiter that occurs in the pattern would mean re-implementing PCRE tokenization
		// (\Q...\E, comments, extended mode), so use a byte the pattern does not contain and pass it through untouched.
		// Bracket-style delimiters are excluded because PHP pairs them with a different closing character.
		$candidates = '~#%@!,;`=' . "\x01\x02\x03\x04\x05\x06\x07\x08\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";
		$length = strlen($candidates);
		for ($i = 0; $i < $length; ++$i) {
			if (strpos($pattern, $candidates[$i]) === false) {
				return $candidates[$i];
			}
		}

		return null;
	}
}

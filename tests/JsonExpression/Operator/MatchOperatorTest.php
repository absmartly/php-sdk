<?php

namespace ABSmartly\SDK\Tests\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Operator\MatchOperator;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\JsonExpression\Operator\OperatorInterface;
use ABSmartly\SDK\Tests\JsonExpression\MockEvaluator;
use PHPUnit\Framework\TestCase;

class MatchOperatorTest extends TestCase {
	public Evaluator $evaluator;
	public OperatorInterface $operator;

	public function setUp(): void {
		$this->evaluator = new MockEvaluator(new OperatorCollection(), new \stdClass());
		$this->operator = new MatchOperator();
	}

	public function testRegexMatches(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", ""]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "abc"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "ijk"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "ijk"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "^abc"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "ijk$"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "def"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "b.*j"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["abcdefghijk", "xyz"]));
	}

	public function testRegexAutoBoundaries(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "//"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/abc/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/ijk/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/ijk/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/^abc/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/ijk$/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/def/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/b.*j/"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["abcdefghijk", "/xyz/"]));
	}

	public function testRegexFlags(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["ABCDEF", "/abc/i"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["ABCDEF", "/abc/"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["foo/i", "/xyz/i"]));
	}

	public function testRegexWithDelimiterCharacter(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["foo~bar", "foo~bar"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["foo~bar", "/o~b/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["foo~bar", "foo\\~bar"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["foobar", "foo~bar"]));
	}

	public function testDelimiterCharacterInsideQuotedLiteral(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["foo~bar", "\\Qfoo~bar\\E"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["foo\\~bar", "^\\Qfoo~bar\\E$"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["a.b~c", "^\\Qa.b~c\\E$"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["axb~c", "^\\Qa.b~c\\E$"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["foo~bar", "/^\\Qfoo~\\Ebar$/"]));
		self::assertTrue($this->operator->evaluate($this->evaluator, ["x~y~z", "^x~\\Qy~\\Ez$"]));
		// An unterminated quote runs to the end of the pattern.
		self::assertTrue($this->operator->evaluate($this->evaluator, ["a~b*", "^\\Qa~b*"]));
		// Inside a quote a backslash is literal, so \~ there matches a backslash followed by "~".
		self::assertTrue($this->operator->evaluate($this->evaluator, ["a\\~b", "^\\Qa\\~b\\E$"]));
		// \E ends the quote even right after a literal backslash.
		self::assertTrue($this->operator->evaluate($this->evaluator, ["a\\~", "^\\Qa\\\\E~$"]));
	}

	public function testEscapedClosingSlashIsNotADelimiter(): void {
		self::assertTrue($this->operator->evaluate($this->evaluator, ["foo/i", "/foo\\/i"]));
		self::assertFalse($this->operator->evaluate($this->evaluator, ["FOO", "/foo\\/i"]));
		// Two backslashes are an escaped backslash, so the slash after them still closes the pattern.
		self::assertTrue($this->operator->evaluate($this->evaluator, ["FOO\\", "/foo\\\\/i"]));
	}

	public function testInvalidRegexReturnsNull(): void {
		self::assertNull($this->operator->evaluate($this->evaluator, ["abc", "("]));
	}

	public function testNonStringTextIsConvertedToString(): void {
		$evaluator = new Evaluator(new OperatorCollection(), new \stdClass());

		self::assertTrue($this->operator->binary($evaluator, 25, "^2"));
		self::assertFalse($this->operator->binary($evaluator, 35, "^2"));
		self::assertTrue($this->operator->binary($evaluator, 2.5, "^2\\.5$"));
		self::assertTrue($this->operator->binary($evaluator, true, "^true$"));
		self::assertNull($this->operator->binary($evaluator, [25], "^2"));
		self::assertNull($this->operator->binary($evaluator, (object) ['a' => 1], "^2"));
	}
}

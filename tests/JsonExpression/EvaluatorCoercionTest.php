<?php

namespace ABSmartly\SDK\Tests\JsonExpression;

use ABSmartly\SDK\JsonExpression\Evaluator;
use PHPUnit\Framework\TestCase;

class EvaluatorCoercionTest extends TestCase {
	public function testBooleanCoercion(): void {
		self::assertTrue(Evaluator::booleanConvert([]));
		self::assertTrue(Evaluator::booleanConvert(new \stdClass()));
		self::assertTrue(Evaluator::booleanConvert((object) []));
		self::assertTrue(Evaluator::booleanConvert(true));
		self::assertTrue(Evaluator::booleanConvert(1));
		self::assertTrue(Evaluator::booleanConvert(2));
		self::assertTrue(Evaluator::booleanConvert("abc"));
		self::assertTrue(Evaluator::booleanConvert("1"));

		self::assertFalse(Evaluator::booleanConvert(false));
		self::assertFalse(Evaluator::booleanConvert(null));
		self::assertFalse(Evaluator::booleanConvert(0));
		self::assertFalse(Evaluator::booleanConvert(""));
		self::assertFalse(Evaluator::booleanConvert("0"));
		self::assertFalse(Evaluator::booleanConvert("false"));
	}

	public function testNumberCoercion(): void {
		// Non-numerics return null
		self::assertNull(Evaluator::numberConvert(null));
		self::assertNull(Evaluator::numberConvert(new \stdClass()));
		self::assertNull(Evaluator::numberConvert([]));
		self::assertNull(Evaluator::numberConvert(""));
		self::assertNull(Evaluator::numberConvert("abc"));
		self::assertNull(Evaluator::numberConvert("x1234"));

		// Bool return float
		self::assertSame(1.0, Evaluator::numberConvert(true));
		self::assertSame(0.0, Evaluator::numberConvert(false));

		// Float returns float
		self::assertSame(-1.0, Evaluator::numberConvert(-1.0));
		self::assertSame(0.0,  Evaluator::numberConvert( 0.0));
		self::assertSame(1.0,  Evaluator::numberConvert( 1.0));
		self::assertSame(1.5,  Evaluator::numberConvert( 1.5));
		self::assertSame(2.0,  Evaluator::numberConvert( 2.0));
		self::assertSame(3.0,  Evaluator::numberConvert( 3.0));

		// Ints return flaots
		self::assertSame(-1.0,  Evaluator::numberConvert(-1));
		self::assertSame(0.0,   Evaluator::numberConvert( 0));
		self::assertSame(1.0,   Evaluator::numberConvert( 1));
		self::assertSame(2.0,   Evaluator::numberConvert( 2));
		self::assertSame(3.0,   Evaluator::numberConvert( 3));

		// Numeric string literals
		self::assertSame(-1.0,   Evaluator::numberConvert( "-1"));
		self::assertSame( 0.0,   Evaluator::numberConvert(  "0"));
		self::assertSame( 1.0,   Evaluator::numberConvert(  "1"));
		self::assertSame( 1.5,   Evaluator::numberConvert(  "1.5"));
		self::assertSame( 2.0,   Evaluator::numberConvert(  "2"));
		self::assertSame( 3.0,   Evaluator::numberConvert(  "3.0"));

		// Int boundaries
		self::assertSame( 2147483647.0, Evaluator::numberConvert(0x7fffffff));
		self::assertSame(-2147483647.0, Evaluator::numberConvert(-0x7fffffff));
		self::assertSame( 9007199254740991.0, Evaluator::numberConvert((2 << 52) - 1)); // PHP equivalent to JS Number.MAX_SAFE_INTEGER
		self::assertSame(-9007199254740991.0, Evaluator::numberConvert(-((2 << 52) - 1))); // PHP equivalent to JS Number.MIN_SAFE_INTEGER
		self::assertSame((float) PHP_INT_MAX, Evaluator::numberConvert(PHP_INT_MAX));
		self::assertSame((float) PHP_INT_MIN, Evaluator::numberConvert(PHP_INT_MIN));
	}

	public function testStringCoercion(): void {
		// Unsupported types coerced to null
		self::assertNull(Evaluator::stringConvert(null));
		self::assertNull(Evaluator::stringConvert([]));
		self::assertNull(Evaluator::stringConvert(new \stdClass()));

		// Booleans coerced to literal values
		self::assertSame("true", Evaluator::stringConvert(true));
		self::assertSame("false", Evaluator::stringConvert(false));

		// Empty and regular strings are preserved
		self::assertSame("", Evaluator::stringConvert(""));
		self::assertSame("abc", Evaluator::stringConvert("abc"));

		// Numbers and changed to strings.
		self::assertSame("-1", Evaluator::stringConvert(-1.0));
		self::assertSame("0", Evaluator::stringConvert(0.0));
		self::assertSame("1", Evaluator::stringConvert(1.0));
		self::assertSame("2", Evaluator::stringConvert(2.0));
		self::assertSame("3", Evaluator::stringConvert(3.0));
		self::assertSame("-2147483647", Evaluator::stringConvert(-2147483647.0));
		self::assertSame("9007199254740991", Evaluator::stringConvert(9007199254740991));
		self::assertSame("-9007199254740991", Evaluator::stringConvert(-9007199254740991));
		self::assertSame("-1", Evaluator::stringConvert(-1));
		self::assertSame("0", Evaluator::stringConvert(0));
		self::assertSame("1", Evaluator::stringConvert(1));
		self::assertSame("2", Evaluator::stringConvert(2));
		self::assertSame("3", Evaluator::stringConvert(3));
		self::assertSame("2147483647", Evaluator::stringConvert(2147483647));
		self::assertSame("-2147483647", Evaluator::stringConvert(-2147483647));
		self::assertSame("9007199254740991", Evaluator::stringConvert(9007199254740991));
		self::assertSame("-9007199254740991", Evaluator::stringConvert(-9007199254740991));

		// Handling when numbers lose precision.
		self::assertSame(number_format(0.9007199254740991, ini_get('precision') - 1), Evaluator::stringConvert(0.9007199254740991));
		self::assertSame(number_format(-0.9007199254740991, ini_get('precision') - 1), Evaluator::stringConvert(-0.9007199254740991));
	}
}

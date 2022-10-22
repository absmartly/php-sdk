<?php
declare(strict_types=1);
namespace Absmartly\SDK\Tests\JsonExpression;

use Absmartly\SDK\JsonExpression\Evaluator;
use Absmartly\SDK\JsonExpression\Operator\OperatorCollection;
use PHPUnit\Framework\TestCase;

class EvaluatorComparisonTest extends TestCase {
	private Evaluator $evaluator;

	public function setUp(): void {
		$this->evaluator = new Evaluator(new OperatorCollection(), new \stdClass());
	}

	public function testCompareNull(): void {
		self::assertSame(0, $this->evaluator->compare(null, null));

		self::assertNull($this->evaluator->compare(null, 0));
		self::assertNull($this->evaluator->compare(null, 1));
		self::assertNull($this->evaluator->compare(null, true));
		self::assertNull($this->evaluator->compare(null, false));
		self::assertNull($this->evaluator->compare(null, ""));
		self::assertNull($this->evaluator->compare(null, "abc"));
		self::assertNull($this->evaluator->compare(null, []));
		self::assertNull($this->evaluator->compare(null, new \stdClass()));
	}

	public function testCompareBooleans(): void {
		self::assertSame(0, $this->evaluator->compare(false, 0));
		self::assertSame(-1, $this->evaluator->compare(false, 1));
		self::assertSame(-1, $this->evaluator->compare(false, true));
		self::assertSame(0, $this->evaluator->compare(false, false));
		self::assertSame(0, $this->evaluator->compare(false, ""));
		self::assertSame(-1, $this->evaluator->compare(false, "abc"));
		self::assertSame(-1, $this->evaluator->compare(false, []));
		self::assertSame(-1, $this->evaluator->compare(false, new \stdClass()));

		self::assertSame(1, $this->evaluator->compare(true, 0));
		self::assertSame(0, $this->evaluator->compare(true, 1));
		self::assertSame(0, $this->evaluator->compare(true, true));
		self::assertSame(1, $this->evaluator->compare(true, false));
		self::assertSame(1, $this->evaluator->compare(true, ""));
		self::assertSame(0, $this->evaluator->compare(true, "abc"));
		self::assertSame(0, $this->evaluator->compare(true, []));
		self::assertSame(0, $this->evaluator->compare(true, new \stdClass()));
	}

	public function testCompareString(): void {
		self::assertSame(0, $this->evaluator->compare("", ""));
		self::assertSame(0, $this->evaluator->compare("abc", "abc"));
		self::assertSame(0, $this->evaluator->compare("0", 0));
		self::assertSame(0, $this->evaluator->compare("1", 1));
		self::assertSame(0, $this->evaluator->compare("true", true));
		self::assertSame(0, $this->evaluator->compare("false", false));

		self::assertNull($this->evaluator->compare("", []));
		self::assertNull($this->evaluator->compare("abc", []));
		self::assertNull($this->evaluator->compare("", new \stdClass()));
		self::assertNull($this->evaluator->compare("abs", new \stdClass()));

		self::assertSame(-1, $this->evaluator->compare("abc", "bcd"));
		self::assertSame(1, $this->evaluator->compare("bcd", "abc"));
		self::assertSame(-1, $this->evaluator->compare("0", "1"));
		self::assertSame(1, $this->evaluator->compare("1", "0"));
		self::assertSame(-1, $this->evaluator->compare("100", "9")); //
		self::assertSame(1, $this->evaluator->compare("9", "100"));
		self::assertSame(1, $this->evaluator->compare("9", 100));
	}

	public function testCompareNumbers(): void {
		// Zero comparisons
		self::assertSame(0, $this->evaluator->compare(0, 0));
		self::assertSame(0, $this->evaluator->compare(0, -0));
		self::assertSame(0, $this->evaluator->compare(-0, 0));
		self::assertSame(0, $this->evaluator->compare(-0, -0));

		// Basic
		self::assertSame(-1, $this->evaluator->compare(0, 1));

		// Booleans
		self::assertSame(-1, $this->evaluator->compare(0, true));
		self::assertSame(0, $this->evaluator->compare(0, false));

		// Uncoerced values
		self::assertNull($this->evaluator->compare(0, ""));
		self::assertNull($this->evaluator->compare(0, "abc"));
		self::assertNull($this->evaluator->compare(0, []));
		self::assertNull($this->evaluator->compare(0, new \stdClass()));

		// Falsy/truthy
		self::assertSame(1, $this->evaluator->compare(1, 0));
		self::assertSame(0, $this->evaluator->compare(1, 1));
		self::assertSame(0, $this->evaluator->compare(1, true));
		self::assertSame(1, $this->evaluator->compare(1, false));

		// Uncoerced values
		self::assertNull($this->evaluator->compare(1, ""));
		self::assertNull($this->evaluator->compare(1, "abc"));
		self::assertNull($this->evaluator->compare(1, []));
		self::assertNull($this->evaluator->compare(1, new \stdClass()));

		// LHS floats
		self::assertSame(0, $this->evaluator->compare(1.0, 1));
		self::assertSame(1, $this->evaluator->compare(1.5, 1));
		self::assertSame(1, $this->evaluator->compare(2.0, 1));
		self::assertSame(1, $this->evaluator->compare(3.0, 1));

		// RHS floats
		self::assertSame(0, $this->evaluator->compare(1, 1.0));
		self::assertSame(-1, $this->evaluator->compare(1, 1.5));
		self::assertSame(-1, $this->evaluator->compare(1, 2.0));
		self::assertSame(-1, $this->evaluator->compare(1, 3.0));

		// Number formats
		self::assertSame(0, $this->evaluator->compare(9_007_199_254_740_991, 9_007_199_254_740_991));
		self::assertSame(-1, $this->evaluator->compare(0, 9_007_199_254_740_991));
		self::assertSame(1, $this->evaluator->compare(9_007_199_254_740_991, 0));

		// Floats
		self::assertSame(0, $this->evaluator->compare(9007199254740991.0, 9007199254740991.0));
		self::assertSame(-1, $this->evaluator->compare(0, 9007199254740991.0));
		self::assertSame(-1, $this->evaluator->compare(0.0, 9007199254740991.0));
		self::assertSame(1, $this->evaluator->compare(9007199254740991.0, 0));
		self::assertSame(1, $this->evaluator->compare(9007199254740991.0, 0.0));
	}
}


<?php

namespace ABSmartly\SDK\Tests\JsonExpression;

use ABSmartly\SDK\JsonExpression\SemverComparator;
use PHPUnit\Framework\TestCase;

class SemverComparatorTest extends TestCase {
	public function testEqualVersions(): void {
		self::assertSame(0, SemverComparator::compare('1.0.0', '1.0.0'));
		self::assertSame(0, SemverComparator::compare('0.0.0', '0.0.0'));
		self::assertSame(0, SemverComparator::compare('999.999.999', '999.999.999'));
	}

	public function testMajorMinorPatch(): void {
		self::assertSame(1, SemverComparator::compare('2.0.0', '1.0.0'));
		self::assertSame(-1, SemverComparator::compare('1.0.0', '2.0.0'));
		self::assertSame(1, SemverComparator::compare('1.2.0', '1.1.0'));
		self::assertSame(-1, SemverComparator::compare('1.1.0', '1.2.0'));
		self::assertSame(1, SemverComparator::compare('1.0.2', '1.0.1'));
		self::assertSame(-1, SemverComparator::compare('1.0.1', '1.0.2'));
	}

	public function testNumericPartsAreNotComparedLexicographically(): void {
		self::assertSame(1, SemverComparator::compare('1.10.0', '1.9.0'));
		self::assertSame(-1, SemverComparator::compare('1.9.0', '1.10.0'));
		self::assertSame(1, SemverComparator::compare('10.0.0', '9.0.0'));
	}

	public function testMissingPartsAreZero(): void {
		self::assertSame(0, SemverComparator::compare('1.2', '1.2.0'));
		self::assertSame(0, SemverComparator::compare('1', '1.0.0'));
		self::assertSame(0, SemverComparator::compare('1.2.0', '1.2'));
		self::assertSame(0, SemverComparator::compare('1.0.0', '1'));
	}

	public function testLeadingZerosAreIgnored(): void {
		self::assertSame(0, SemverComparator::compare('1.02.0', '1.2.0'));
		self::assertSame(0, SemverComparator::compare('1.002.030', '1.2.30'));
		self::assertSame(0, SemverComparator::compare('01.0.0', '1.0.0'));
		self::assertSame(-1, SemverComparator::compare('1.02.0', '1.3.0'));
		self::assertSame(0, SemverComparator::compare('1.00.0', '1.0.0'));
	}

	public function testPreRelease(): void {
		self::assertSame(1, SemverComparator::compare('1.0.0', '1.0.0-alpha'));
		self::assertSame(-1, SemverComparator::compare('1.0.0-alpha', '1.0.0'));
		self::assertSame(0, SemverComparator::compare('1.0.0-alpha', '1.0.0-alpha'));
		self::assertSame(-1, SemverComparator::compare('1.0.0-alpha', '1.0.0-beta'));
		self::assertSame(1, SemverComparator::compare('1.0.0-beta', '1.0.0-alpha'));
		self::assertSame(-1, SemverComparator::compare('1.0.0-alpha.1', '1.0.0-alpha.2'));
		self::assertSame(1, SemverComparator::compare('1.0.0-alpha.2', '1.0.0-alpha.1'));
		self::assertSame(-1, SemverComparator::compare('1.0.0-1', '1.0.0-2'));
		self::assertSame(1, SemverComparator::compare('1.0.0-2', '1.0.0-1'));
		self::assertSame(0, SemverComparator::compare('1.0.0-rc-1', '1.0.0-rc-1'));
		self::assertSame(-1, SemverComparator::compare('1.0.0-1', '1.0.0-alpha'));
		self::assertSame(1, SemverComparator::compare('1.0.0-alpha', '1.0.0-1'));
		self::assertSame(-1, SemverComparator::compare('1.0.0-alpha', '1.0.0-alpha.1'));
		self::assertSame(1, SemverComparator::compare('1.0.0-alpha.1', '1.0.0-alpha'));
	}

	public function testEmptyPreReleaseIsARelease(): void {
		self::assertSame(0, SemverComparator::compare('1.0.0-', '1.0.0'));
	}

	public function testVPrefixIsIgnored(): void {
		self::assertSame(0, SemverComparator::compare('v1.0.0', '1.0.0'));
		self::assertSame(0, SemverComparator::compare('V1.0.0', '1.0.0'));
		self::assertSame(0, SemverComparator::compare('v1.0.0', 'V1.0.0'));
	}

	public function testBuildMetadataIsIgnored(): void {
		self::assertSame(0, SemverComparator::compare('1.0.0+build1', '1.0.0+build2'));
		self::assertSame(0, SemverComparator::compare('1.0.0+build1', '1.0.0'));
		self::assertSame(0, SemverComparator::compare('1.0.0-alpha+build1', '1.0.0-alpha+build2'));
		self::assertSame(-1, SemverComparator::compare('1.0.0-alpha+build1', '1.0.0-beta'));
		self::assertSame(-1, SemverComparator::compare('1.0.0-alpha+build1', '1.0.0'));
	}

	public function testNumbersAndBooleansAreCoerced(): void {
		self::assertSame(0, SemverComparator::compare(1, '1.0.0'));
		self::assertSame(0, SemverComparator::compare('1.0.0', 1));
		self::assertSame(0, SemverComparator::compare(true, 'true'));
		self::assertSame(0, SemverComparator::compare(2.5, '2.5.0'));
		self::assertSame(0, SemverComparator::compare(10.0, '10'));
	}

	public function testUnconvertibleInputsGiveNull(): void {
		self::assertNull(SemverComparator::compare(null, '1.0.0'));
		self::assertNull(SemverComparator::compare('1.0.0', null));
		self::assertNull(SemverComparator::compare(null, null));
		self::assertNull(SemverComparator::compare(new \stdClass(), '1.0.0'));
		self::assertNull(SemverComparator::compare('1.0.0', []));
	}

	public function testEmptyVersionsGiveNull(): void {
		self::assertNull(SemverComparator::compare('', '1.0.0'));
		self::assertNull(SemverComparator::compare('1.0.0', ''));
		self::assertNull(SemverComparator::compare('', ''));
		self::assertNull(SemverComparator::compare('v', '1.0.0'));
		self::assertNull(SemverComparator::compare('V', '1.0.0'));
		self::assertNull(SemverComparator::compare('+build', '1.0.0'));
		self::assertNull(SemverComparator::compare('v+build', '1.0.0'));
		self::assertNull(SemverComparator::compare('1.0.0', 'v'));
		self::assertNull(SemverComparator::compare('-alpha', '1.0.0'));
	}

	/**
	 * Expected values come from the JavaScript SDK's versionCompare, whose stringConvert is
	 * x.toFixed(15).replace(/\.?0{0,15}$/, ""): toFixed falls back to String(x) from 1e21 up and rounds an exact
	 * half up.
	 */
	public function javaScriptNumberConversionProvider(): array {
		return [
			'1e21 is "1e+21"' => [1e21, '1e+21', 0],
			'1e21 is not its full decimal' => [1e21, '1000000000000000000000', 1],
			'-1e21 has an empty core' => [-1e21, '1.0.0', null],
			'1.2345e21' => [1.2345e21, '1.2345e+21', 0],
			'below 1e21 is the full decimal' => [1e20, '100000000000000000000', 0],
			'exact half rounds up' => [1 / 65536, '0.000015258789063', 0],
			'exact half rounds up, not to even' => [5 / 65536, '0.000076293945313', 0],
			'non-tie keeps the nearest' => [3 / 65536, '0.000045776367188', 0],
			'rounding carries' => [9.9999999999999995, '10', 0],
		];
	}

	/**
	 * @dataProvider javaScriptNumberConversionProvider
	 */
	public function testNumbersConvertLikeJavaScript(float $number, string $version, ?int $expected): void {
		self::assertSame($expected, SemverComparator::compare($number, $version));
	}

	/**
	 * The JavaScript SDK's stringConvert output for each number. compare() drops "+..." as build metadata, so the
	 * exponent forms are checked on the conversion itself.
	 */
	public function javaScriptNumberStringProvider(): array {
		return [
			[1e21, '1e+21'],
			[-1e21, '-1e+21'],
			[1.2345e21, '1.2345e+21'],
			[1e210, '1e+21'],
			[1.5e300, '1.5e+3'],
			[1.7976931348623157e308, '1.7976931348623157e+308'],
			[1e20, '100000000000000000000'],
			[1 / 65536, '0.000015258789063'],
			[5 / 65536, '0.000076293945313'],
			[-1 / 65536, '-0.000015258789063'],
			[0.1, '0.1'],
			[2.5, '2.5'],
			[10.0, '10'],
			[1e-7, '0.0000001'],
			[1e-16, '0'],
		];
	}

	/**
	 * @dataProvider javaScriptNumberStringProvider
	 */
	public function testNumbersConvertToTheJavaScriptString(float $number, string $expected): void {
		$method = new \ReflectionMethod(SemverComparator::class, 'stringConvert');
		$method->setAccessible(true);

		self::assertSame($expected, $method->invoke(null, $number));
	}

	/**
	 * JavaScript compares identifiers by UTF-16 code unit, so a character above U+FFFF (a surrogate pair, from
	 * U+D800) sorts before U+E000-U+FFFF; digits are ASCII only.
	 */
	public function testIdentifiersCompareByUtf16CodeUnitLikeJavaScript(): void {
		self::assertSame(1, SemverComparator::compare("1.0.0-\u{FF5E}", "1.0.0-\u{1F600}"));
		self::assertSame(-1, SemverComparator::compare("1.0.0-\u{1F600}", "1.0.0-\u{FF5E}"));
		self::assertSame(1, SemverComparator::compare("1.0.0-a\u{E000}", "1.0.0-a\u{10000}"));
		self::assertSame(-1, SemverComparator::compare("1.0.0-\u{D7FF}", "1.0.0-\u{10000}"));
		self::assertSame(1, SemverComparator::compare("1.0.0-\u{E9}", '1.0.0-z'));
		self::assertSame(1, SemverComparator::compare("1.0.0-\u{661}", '1.0.0-2'));
		self::assertSame(1, SemverComparator::compare("1.\u{661}.0", '1.1.0'));
		self::assertSame(1, SemverComparator::compare("1.0.0-\u{FF11}", '1.0.0-1'));
		self::assertSame(1, SemverComparator::compare(' 1.0.0', '1.0.0'));
	}
}

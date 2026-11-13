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
}

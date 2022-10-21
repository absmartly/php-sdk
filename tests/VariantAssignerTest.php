<?php

namespace Absmartly\SDK\Tests;

use Absmartly\SDK\VariantAssigner;
use PHPUnit\Framework\TestCase;

class VariantAssignerTest extends TestCase {
	public function getAssignmentTestValues(): array {
		return [
			[
				"bleh@absmartly.com",
				[
					[[0.5, 0.5], 0x00000000, 0x00000000, 0],
					[[0.5, 0.5], 0x00000000, 0x00000001, 1],
					[[0.5, 0.5], 0x8015406f, 0x7ef49b98, 0],
					[[0.5, 0.5], 0x3b2e7d90, 0xca87df4d, 0],
					[[0.5, 0.5], 0x52c1f657, 0xd248bb2e, 0],
					[[0.5, 0.5], 0x865a84d0, 0xaa22d41a, 0],
					[[0.5, 0.5], 0x27d1dc86, 0x845461b9, 1],
					[[0.33, 0.33, 0.34], 0x00000000, 0x00000000, 0],
					[[0.33, 0.33, 0.34], 0x00000000, 0x00000001, 2],
					[[0.33, 0.33, 0.34], 0x8015406f, 0x7ef49b98, 0],
					[[0.33, 0.33, 0.34], 0x3b2e7d90, 0xca87df4d, 0],
					[[0.33, 0.33, 0.34], 0x52c1f657, 0xd248bb2e, 0],
					[[0.33, 0.33, 0.34], 0x865a84d0, 0xaa22d41a, 1],
					[[0.33, 0.33, 0.34], 0x27d1dc86, 0x845461b9, 1],
				],
			],
			[
				"123456789",
				[
					[[0.5, 0.5], 0x00000000, 0x00000000, 1],
					[[0.5, 0.5], 0x00000000, 0x00000001, 0],
					[[0.5, 0.5], 0x8015406f, 0x7ef49b98, 1],
					[[0.5, 0.5], 0x3b2e7d90, 0xca87df4d, 1],
					[[0.5, 0.5], 0x52c1f657, 0xd248bb2e, 1],
					[[0.5, 0.5], 0x865a84d0, 0xaa22d41a, 0],
					[[0.5, 0.5], 0x27d1dc86, 0x845461b9, 0],
					[[0.33, 0.33, 0.34], 0x00000000, 0x00000000, 2],
					[[0.33, 0.33, 0.34], 0x00000000, 0x00000001, 1],
					[[0.33, 0.33, 0.34], 0x8015406f, 0x7ef49b98, 2],
					[[0.33, 0.33, 0.34], 0x3b2e7d90, 0xca87df4d, 2],
					[[0.33, 0.33, 0.34], 0x52c1f657, 0xd248bb2e, 2],
					[[0.33, 0.33, 0.34], 0x865a84d0, 0xaa22d41a, 0],
					[[0.33, 0.33, 0.34], 0x27d1dc86, 0x845461b9, 0],
				],
			],
			[
				"e791e240fcd3df7d238cfc285f475e8152fcc0ec",
				[
					[[0.5, 0.5], 0x00000000, 0x00000000, 1],
					[[0.5, 0.5], 0x00000000, 0x00000001, 0],
					[[0.5, 0.5], 0x8015406f, 0x7ef49b98, 1],
					[[0.5, 0.5], 0x3b2e7d90, 0xca87df4d, 1],
					[[0.5, 0.5], 0x52c1f657, 0xd248bb2e, 0],
					[[0.5, 0.5], 0x865a84d0, 0xaa22d41a, 0],
					[[0.5, 0.5], 0x27d1dc86, 0x845461b9, 0],
					[[0.33, 0.33, 0.34], 0x00000000, 0x00000000, 2],
					[[0.33, 0.33, 0.34], 0x00000000, 0x00000001, 0],
					[[0.33, 0.33, 0.34], 0x8015406f, 0x7ef49b98, 2],
					[[0.33, 0.33, 0.34], 0x3b2e7d90, 0xca87df4d, 1],
					[[0.33, 0.33, 0.34], 0x52c1f657, 0xd248bb2e, 0],
					[[0.33, 0.33, 0.34], 0x865a84d0, 0xaa22d41a, 0],
					[[0.33, 0.33, 0.34], 0x27d1dc86, 0x845461b9, 1],
					[[0.0, 0.01, 0.02], 0x27d1dc86, 0x845461b9, 2],
				],
			],
		];
	}

	public function getFailingAssignmentTestValues(): array {
		return [
			[
				"e791e240fcd3df7d238cfc285f475e8152fcc0ec",
				[
					[[0.0, 0.01, 0.02], 0x27d1dc86, 0x845461b9, 5],
				],
			],
		];
	}

	/**
	 * @dataProvider getAssignmentTestValues
	 */
	public function testVariantAssignerIsDeterministic(string $hash, array $testCases): void {
		$assigner = new VariantAssigner($hash);
		foreach ($testCases as $testCase) {
			$value = $assigner->assign($testCase[0], $testCase[1], $testCase[2]);
			static::assertSame($testCase[3], $value);
		}
	}

	/**
	 * @dataProvider getFailingAssignmentTestValues
	 */
	public function testVariantAssignerMutation(string $hash, array $testCases): void {
		$assigner = new VariantAssigner($hash);
		foreach ($testCases as $testCase) {
			$value = $assigner->assign($testCase[0], $testCase[1], $testCase[2]);
			static::assertNotSame($testCase[3], $value);
		}
	}

	public function testChooseVariantGenericValidation(): void {
		$assigner = new VariantAssigner('test');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('VariantAssigner::chooseVariant($split) must be an array of float values. Encountered: "banana" of type string at key position 0');
		$assigner->assign(['banana'], 0, 0);
	}
}

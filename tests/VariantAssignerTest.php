<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\VariantAssigner;
use PHPUnit\Framework\TestCase;

class VariantAssignerTest extends TestCase {
	public static function assignmentProvider(): array {
		return [
			['bleh@absmartly.com', [0.5, 0.5], 0x00000000, 0x00000000, 0],
			['bleh@absmartly.com', [0.5, 0.5], 0x00000000, 0x00000001, 1],
			['bleh@absmartly.com', [0.5, 0.5], 0x8015406f, 0x7ef49b98, 0],
			['bleh@absmartly.com', [0.5, 0.5], 0x3b2e7d90, 0xca87df4d, 0],
			['bleh@absmartly.com', [0.5, 0.5], 0x52c1f657, 0xd248bb2e, 0],
			['bleh@absmartly.com', [0.5, 0.5], 0x865a84d0, 0xaa22d41a, 0],
			['bleh@absmartly.com', [0.5, 0.5], 0x27d1dc86, 0x845461b9, 1],
			['bleh@absmartly.com', [0.33, 0.33, 0.34], 0x00000000, 0x00000000, 0],
			['bleh@absmartly.com', [0.33, 0.33, 0.34], 0x00000000, 0x00000001, 2],
			['bleh@absmartly.com', [0.33, 0.33, 0.34], 0x8015406f, 0x7ef49b98, 0],
			['bleh@absmartly.com', [0.33, 0.33, 0.34], 0x3b2e7d90, 0xca87df4d, 0],
			['bleh@absmartly.com', [0.33, 0.33, 0.34], 0x52c1f657, 0xd248bb2e, 0],
			['bleh@absmartly.com', [0.33, 0.33, 0.34], 0x865a84d0, 0xaa22d41a, 1],
			['bleh@absmartly.com', [0.33, 0.33, 0.34], 0x27d1dc86, 0x845461b9, 1],
			['123456789', [0.5, 0.5], 0x00000000, 0x00000000, 1],
			['123456789', [0.5, 0.5], 0x00000000, 0x00000001, 0],
			['123456789', [0.5, 0.5], 0x8015406f, 0x7ef49b98, 1],
			['123456789', [0.5, 0.5], 0x3b2e7d90, 0xca87df4d, 1],
			['123456789', [0.5, 0.5], 0x52c1f657, 0xd248bb2e, 1],
			['123456789', [0.5, 0.5], 0x865a84d0, 0xaa22d41a, 0],
			['123456789', [0.5, 0.5], 0x27d1dc86, 0x845461b9, 0],
			['123456789', [0.33, 0.33, 0.34], 0x00000000, 0x00000000, 2],
			['123456789', [0.33, 0.33, 0.34], 0x00000000, 0x00000001, 1],
			['123456789', [0.33, 0.33, 0.34], 0x8015406f, 0x7ef49b98, 2],
			['123456789', [0.33, 0.33, 0.34], 0x3b2e7d90, 0xca87df4d, 2],
			['123456789', [0.33, 0.33, 0.34], 0x52c1f657, 0xd248bb2e, 2],
			['123456789', [0.33, 0.33, 0.34], 0x865a84d0, 0xaa22d41a, 0],
			['123456789', [0.33, 0.33, 0.34], 0x27d1dc86, 0x845461b9, 0],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.5, 0.5], 0x00000000, 0x00000000, 1],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.5, 0.5], 0x00000000, 0x00000001, 0],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.5, 0.5], 0x8015406f, 0x7ef49b98, 1],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.5, 0.5], 0x3b2e7d90, 0xca87df4d, 1],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.5, 0.5], 0x52c1f657, 0xd248bb2e, 0],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.5, 0.5], 0x865a84d0, 0xaa22d41a, 0],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.5, 0.5], 0x27d1dc86, 0x845461b9, 0],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.33, 0.33, 0.34], 0x00000000, 0x00000000, 2],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.33, 0.33, 0.34], 0x00000000, 0x00000001, 0],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.33, 0.33, 0.34], 0x8015406f, 0x7ef49b98, 2],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.33, 0.33, 0.34], 0x3b2e7d90, 0xca87df4d, 1],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.33, 0.33, 0.34], 0x52c1f657, 0xd248bb2e, 0],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.33, 0.33, 0.34], 0x865a84d0, 0xaa22d41a, 0],
			['e791e240fcd3df7d238cfc285f475e8152fcc0ec', [0.33, 0.33, 0.34], 0x27d1dc86, 0x845461b9, 1],
		];
	}

	/**
	 * @dataProvider assignmentProvider
	 */
	public function testAssignShouldBeDeterministic(string $unit, array $split, int $seedHi, int $seedLo, int $expectedVariant): void {
		$assigner = new VariantAssigner($unit);
		$variant = $assigner->assign($split, $seedHi, $seedLo);
		self::assertSame($expectedVariant, $variant);
	}

	public function testChooseVariantGenericValidation(): void {
		$assigner = new VariantAssigner('test');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('VariantAssigner::chooseVariant($split) must be an array of float values. Encountered: "banana" of type string at key position 0');
		$assigner->assign(['banana'], 0, 0);
	}
}

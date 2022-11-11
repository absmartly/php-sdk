<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\VariableParser;
use PHPUnit\Framework\TestCase;

class VariableParserTest extends TestCase {
	public function testParserHappyPath(): void {
		$json = file_get_contents(__DIR__ . '/Fixtures/json/variables.json');
		$actual = (new VariableParser())->parse('test', $json);
		$expected = (object) [
			'a' => 1,
			'b' => 'test',
			'c' =>
				(object) [
					'test' => 2,
					'double' => 19.123,
					'list' =>
						[
							0 => 'x',
							1 => 'y',
							2 => 'z',
						],
					'point' =>
						(object) [
							'x' => -1.0,
							'y' => 0.0,
							'z' => 1.0,
						],
				],
			'd' => true,
			'f' =>
				[
					0 => 9234567890,
					1 => 'a',
					2 => true,
					3 => false,
				],
			'g' => 9.123,
		];

		self::assertEquals($expected, $actual);
	}

	public function testVariableParserReturnsNull(): void {
		$json = file_get_contents(__DIR__ . '/Fixtures/json/variables.json');
		$json = str_replace('"', ';', $json);
		self::assertNull((new VariableParser())->parse('test', $json));
	}
}

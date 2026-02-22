<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\AudienceMatcher;
use PHPUnit\Framework\TestCase;
use stdClass;

class AudienceMatcherTest extends TestCase {
	private AudienceMatcher $matcher;

	protected function setUp(): void {
		$this->matcher = new AudienceMatcher();
	}

	public function testShouldReturnNullOnEmptyAudience(): void {
		$audience = new stdClass();
		self::assertNull($this->matcher->evaluate($audience, []));
	}

	public function testShouldReturnNullIfFilterNotObjectOrArray(): void {
		$audience = (object) ['filter' => null];
		self::assertNull($this->matcher->evaluate($audience, []));

		$audience2 = new stdClass();
		self::assertNull($this->matcher->evaluate($audience2, []));
	}

	public function testShouldReturnBoolean(): void {
		$audience = (object) [
			'filter' => [
				(object) ['gte' => [
					(object) ['var' => ['path' => 'age']],
					(object) ['value' => 20],
				]],
			],
		];

		self::assertTrue($this->matcher->evaluate($audience, ['age' => 25]));
		self::assertFalse($this->matcher->evaluate($audience, ['age' => 15]));
	}
}

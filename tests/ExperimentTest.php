<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\Experiment;
use PHPUnit\Framework\TestCase;

class ExperimentTest extends TestCase {
	private function createExperimentData(array $overrides = []): object {
		$defaults = [
			'id' => 1,
			'name' => 'test_exp',
			'unitType' => 'session_id',
			'iteration' => 1,
			'seedHi' => 12345,
			'seedLo' => 67890,
			'split' => [0.5, 0.5],
			'trafficSeedHi' => 11111,
			'trafficSeedLo' => 22222,
			'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => 0,
			'applications' => [['name' => 'website']],
			'variants' => [
				(object) ['name' => 'A', 'config' => null],
				(object) ['name' => 'B', 'config' => null],
			],
			'audience' => '',
		];

		return (object) array_merge($defaults, $overrides);
	}

	public function testAudienceStrictDefaultsToFalse(): void {
		$data = $this->createExperimentData();
		$experiment = new Experiment($data);
		self::assertFalse($experiment->audienceStrict);
	}

	public function testAudienceStrictSetFromData(): void {
		$data = $this->createExperimentData(['audienceStrict' => true]);
		$experiment = new Experiment($data);
		self::assertTrue($experiment->audienceStrict);
	}

	public function testMissingRequiredFieldThrows(): void {
		$data = (object) ['id' => 1, 'name' => 'test'];
		$this->expectException(\ABSmartly\SDK\Exception\RuntimeException::class);
		$this->expectExceptionMessage('Missing required field');
		new Experiment($data);
	}
}

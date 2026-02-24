<?php

namespace ABSmartly\SDK;

use function get_object_vars as get_object_varsAlias;
use function json_decode;

use function property_exists;

use const JSON_THROW_ON_ERROR;

class Experiment {
	public int $id;
	public string $name;
	public string $unitType;
	public int $iteration;
	public int $seedHi;
	public int $seedLo;
	public array $split;
	public int $trafficSeedHi;
	public int $trafficSeedLo;
	public array $trafficSplit;
	public int $fullOnVariant;
	public ?object $audience;
	public bool $audienceStrict;
	public array $applications;
	public array $variants;
	public ?object $customFieldValues = null;

	public function __construct(object $data) {
		$requiredFields = ['id', 'name', 'unitType', 'iteration', 'seedHi', 'seedLo', 'split',
			'trafficSeedHi', 'trafficSeedLo', 'trafficSplit', 'fullOnVariant', 'applications', 'variants'];

		foreach ($requiredFields as $field) {
			if (!property_exists($data, $field)) {
				throw new \ABSmartly\SDK\Exception\RuntimeException(
					sprintf('Missing required field "%s" in experiment data', $field)
				);
			}
		}

		if (!empty($data->audience)) {
			$this->audience = json_decode($data->audience, false, 512, JSON_THROW_ON_ERROR);
		}
		else {
			$this->audience = null;
		}


		$data = get_object_varsAlias($data);
		foreach ($data as $field => $value) {
			if (property_exists($this, $field) && $field !== 'audience') {
				$this->{$field} = $value;
			}
		}
	}
}

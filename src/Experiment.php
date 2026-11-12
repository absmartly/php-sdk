<?php

namespace ABSmartly\SDK;

use function get_object_vars as get_object_varsAlias;
use function is_array;
use function is_string;
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
	public bool $audienceStrict = false;
	public array $applications;
	public array $variants;
	/** @var object[]|null The collector's array of {name, type, value} records. */
	public ?array $customFieldValues = null;
	/** JSON of the experiment's assignment rules ({"rules": [...]}), or null when it has none. */
	public ?string $assignmentRules = null;

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
			if ($field === 'customFieldValues') {
				$this->customFieldValues = is_array($value) ? $value : null;
			}
			elseif ($field === 'assignmentRules') {
				// An object or array sent in place of the JSON string is ignored rather than failing the whole context.
				$this->assignmentRules = is_string($value) && $value !== '' ? $value : null;
			}
			elseif (property_exists($this, $field) && $field !== 'audience') {
				$this->{$field} = $value;
			}
		}
	}
}

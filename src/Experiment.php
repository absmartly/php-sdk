<?php

namespace ABSmartly\SDK;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function floor;
use function get_object_vars as get_object_varsAlias;
use function is_array;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function property_exists;
use function range;

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
	/** @var int[]|null Ids of the holdouts in ContextData::$holdouts that cover this experiment. */
	public ?array $holdoutIds = null;
	/** The holdout's type ("full", "all_full_on") when this entry is a holdout; never read, arity comes from split. */
	public ?string $holdoutType = null;
	/** "unit" or "switchback"; null (absent) means a unit experiment. */
	public ?string $randomizationType = null;
	/**
	 * @var array<string, mixed>|null A switchback's linked unit types and the attribute path holding each one's
	 * cluster value, in the served order. The order is semantic: the first unit, in this order, with a value serves.
	 */
	public ?array $switchbackClusters = null;
	/** @var string[]|null Allowed cluster values; null or [] admits every value. */
	public ?array $switchbackClusterValues = null;
	/** The switchback grid's start (anchor), epoch milliseconds. */
	public ?int $switchbackStartAt = null;
	public ?int $switchbackPeriodMs = null;
	/** Analysis-only; carried, never read by assignment. */
	public ?int $switchbackCarryoverDurationMs = null;
	/** Analysis-only; carried, never read by assignment. */
	public ?int $switchbackBurnInDurationMs = null;
	/** Analysis-only; carried, never read by assignment. */
	public ?int $switchbackBurnOutDurationMs = null;
	public ?string $switchbackDesignType = null;
	public ?string $switchbackEstimand = null;

	/** Switchback fields by name, and the kind of value each holds. */
	private const SWITCHBACK_FIELDS = [
		'randomizationType' => 'string',
		'switchbackClusters' => 'map',
		'switchbackClusterValues' => 'list',
		'switchbackStartAt' => 'int',
		'switchbackPeriodMs' => 'int',
		'switchbackCarryoverDurationMs' => 'int',
		'switchbackBurnInDurationMs' => 'int',
		'switchbackBurnOutDurationMs' => 'int',
		'switchbackDesignType' => 'string',
		'switchbackEstimand' => 'string',
	];

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
			elseif ($field === 'holdoutIds') {
				// Ids that aren't integers (integral floats such as 11.0 count) can't name a holdout, so they are dropped
				// rather than failing the whole context.
				$this->holdoutIds = is_array($value)
					? array_values(array_filter(array_map(static function ($id) {
						return is_float($id) && floor($id) === $id && $id >= -9.2233720368547758E18 && $id < 9.2233720368547758E18 ? (int) $id : $id;
					}, $value), 'is_int'))
					: null;
			}
			elseif ($field === 'holdoutType') {
				$this->holdoutType = is_string($value) ? $value : null;
			}
			elseif (isset(self::SWITCHBACK_FIELDS[$field])) {
				$this->setSwitchbackField($field, $value);
			}
			elseif (property_exists($this, $field) && $field !== 'audience') {
				$this->{$field} = $value;
			}
		}
	}

	/**
	 * A malformed switchback value is dropped rather than failing the whole context: a cluster map that is not an
	 * object takes the legacy path, and an instant or duration that is not a whole number leaves the schedule
	 * unservable. Integral floats (1.0) count as integers, as for holdoutIds.
	 *
	 * @param mixed $value
	 */
	private function setSwitchbackField(string $field, $value): void {
		switch (self::SWITCHBACK_FIELDS[$field]) {
			case 'string':
				$this->{$field} = is_string($value) ? $value : null;
				return;
			case 'int':
				$this->{$field} = is_int($value) ? $value
					: (is_float($value) && floor($value) === $value && abs($value) < 9.2233720368547758E18 ? (int) $value : null);
				return;
			case 'map':
				// Iterating keeps the served key order; never sort it.
				$clusters = null;
				// A JSON array is not a cluster map; an associative PHP array (a hand-built payload) is.
				if (is_object($value) || (is_array($value) && ($value === [] || array_keys($value) !== range(0, count($value) - 1)))) {
					$clusters = [];
					foreach ($value as $unitType => $path) {
						$clusters[$unitType] = $path;
					}
				}
				$this->switchbackClusters = $clusters;
				return;
			default:
				// Whole numbers are kept as their decimal text, as Jackson reads them into java-sdk's String[]; any other
				// non-string entry is kept and never matches, so a malformed list never widens to "every value".
				$this->switchbackClusterValues = is_array($value)
					? array_map(static function ($allowed) {
						return is_int($allowed) ? (string) $allowed : $allowed;
					}, array_values($value))
					: null;
		}
	}
}

<?php

namespace ABSmartly\SDK;

use JsonSerializable;

class PublishEvent implements JsonSerializable {
	public bool $hashed;
	public array $units;
	public int $publishedAt;
	public array $exposures = [];
	public array $goals = [];
	public array $attributes;

	public function jsonSerialize(): object {
		$object = (object) [
			'publishedAt' => $this->publishedAt,
		];

		foreach (['hashed', 'exposures', 'attributes'] as $key) {
			if (!empty($this->{$key})) {
				$object->{$key} = $this->{$key};
			}
		}

		if (isset($this->units)) {
			$units = [];
			foreach ($this->units as $unit => $value) {
				$units[] = ['type' => $unit, 'uid' => $value];
			}

			$object->units = $units;
		}

		foreach (['goals', 'exposures'] as $key) {
			if (!empty($this->{$key})) {
				$object->{$key} = [];
				foreach ($this->{$key} as $item) {
					// Double cast is used to cast the object to an array, then an object of stdClass
					$object->{$key}[] = (object) (array) $item;
				}
			}
		}

		return $object;
	}
}

<?php

namespace ABSmartly\SDK;

use ABSmartly\SDK\Context\Context;
use JsonSerializable;
use stdClass;

class PublishEvent implements JsonSerializable {
	public bool $hashed;
	public array $units;
	public int $publishedAt;
	public array $exposures = [];
	public array $goals = [];
	public array $attributes;

	public function jsonSerialize(): object {
		$object = new stdClass();

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

		$object->publishedAt = Context::getTime();
		return $object;
	}
}

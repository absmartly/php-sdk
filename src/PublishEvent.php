<?php

namespace ABSmartly\SDK;

use ABSmartly\SDK\Context\Context;
use JsonSerializable;
use stdClass;

class PublishEvent implements JsonSerializable {
	public int $publishedAt;
	public bool $hashed = false;
	protected array $units = [];
	public array $exposures = [];
	public array $goals = [];
	protected array $attributes = [];

	public function __construct() {
		$this->publishedAt = Context::getTime();
	}

	/**
	 * @param string|int|float $unit
	 */
	public function hashUnit($unit): string {
		return Utils::hashUnit($unit);
	}

	public function setUnits(array $units): void {
		$this->hashed = true;
		foreach ($units as $unit => $value) {
			$unitObject = new stdClass();
			$unitObject->type = $unit;
			$unitObject->uid = $this->hashUnit($value);

			$this->units[] = $unitObject;
		}
	}

	public function setAttributes(array $attributes): void {
		$this->attributes = $attributes;
	}
	public function jsonSerialize(): object {
		$this->publishedAt = Context::getTime();
		$object = new stdClass();
		$object->hashed = $this->hashed;
		$object->publishedAt = $this->publishedAt;

		foreach (['goals', 'exposures', 'units'] as $key) {
			if (!empty($this->{$key})) {
				$object->{$key} = [];
				foreach ($this->{$key} as $item) {
					// Double cast is used to cast the object to an array, then an object of stdClass
					$object->{$key}[] = (object) (array) $item;
				}
			}
		}

		$object->attributes = $this->attributes;

		return $object;
	}
}

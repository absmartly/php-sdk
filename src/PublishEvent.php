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
	public array $attributes;

	public function hashUnit(string $unit): string {
		$hash = hash('md5', $unit, true);

		// Removing padding and +/ characters in the base64 encoded string.
		$hash = strtr(base64_encode($hash), [
			'+' => '-',
			'/' => '_',
			'=' => '',
		]);

		return substr($hash, 0, 22);
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
	public function jsonSerialize(): object {
		$object = new stdClass();
		$object->publishedAt = $this->publishedAt ?? Context::getTime();

		foreach (['goals', 'exposures', 'attributes', 'units'] as $key) {
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

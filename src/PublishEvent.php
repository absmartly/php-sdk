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

		foreach (['units', 'hashed', 'exposures', 'attributes'] as $key) {
			if (!empty($this->{$key})) {
				$object->{$key} = $this->{$key};
			}
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

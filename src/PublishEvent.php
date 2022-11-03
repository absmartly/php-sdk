<?php

namespace Absmartly\SDK;

use JsonSerializable;

use function time;

class PublishEvent implements JsonSerializable {
	public bool $hashed;
	public array $units;
	public ?int $publishedAt = null;
	public array $exposures;
	public array $goals;
	public array $attributes;

	public function jsonSerialize(): object {
		$object = (object) [
			'publishedAt' => $this->publishedAt ?? time(),
		];

		foreach (['units', 'hashed', 'publishedAt', 'goals', 'exposures', 'attributes'] as $key) {
			if (!empty($this->{$key})) {
				$object->{$key} = $this->{$key};
			}
		}

		return $object;
	}
}

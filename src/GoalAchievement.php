<?php

namespace ABSmartly\SDK;

use JsonSerializable;
use stdClass;

class GoalAchievement implements JsonSerializable {
	public string $name;
	public int $achievedAt;
	public ?object $properties;

	public function __construct(string $name, int $achievedAt, ?object $properties) {
		$this->name = $name;
		$this->achievedAt = $achievedAt;
		$this->properties = $properties;
	}

	public function jsonSerialize(): stdClass {
		$object = new stdClass();
		$object->name = $this->name;
		$object->achievedAt = $this->achievedAt;
		$object->properties = $this->properties;

		return $object;
	}
}

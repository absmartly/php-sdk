<?php

namespace Absmartly\SDK;

use function time;

class Exposure {
	public int $id;
	public string $name;
	public ?string $unit;
	public int $variant;
	public float $exposedAt;
	public bool $assigned;
	public bool $eligible;
	public bool $overridden;
	public bool $fullOn;
	public bool $custom;
	public bool $audienceMismatch;

	public function ingestAssignment(Assignment $assignment): void {
		$this->id = $assignment->id;
		$this->name = $assignment->name;
		$this->exposedAt = static::getTime();

		if (!isset($assignment->unitType)) {
			return; // Shirt circuit to prevent accessing the rest of the $assignment properties which are unset.
		}

		$this->unit = $assignment->unitType;
		$this->variant = $assignment->variant;
		$this->eligible = $assignment->eligible;
		$this->overridden = $assignment->overridden;
		$this->fullOn = $assignment->fullOn;
		$this->custom = $assignment->custom;
		$this->audienceMismatch = $assignment->audienceMismatch;
	}

	protected static function getTime(): int {
		return time();
	}
}

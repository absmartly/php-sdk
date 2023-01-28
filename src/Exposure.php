<?php

namespace ABSmartly\SDK;

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
		$this->unit = $assignment->unitType ?? null;
		$this->variant = $assignment->variant;
		$this->eligible = $assignment->eligible;
		$this->overridden = $assignment->overridden;
		$this->fullOn = $assignment->fullOn;
		$this->custom = $assignment->custom;
		$this->audienceMismatch = $assignment->audienceMismatch;
	}

	public static function create(
		int $id, string $name, ?string $unit, int $variant, float $exposedAt, bool $assigned, bool $eligible,
		bool $overridden, bool $fullOn, bool $custom, bool $audienceMismatch
	): self {
		$exposure = new self();
		$exposure->id = $id;
		$exposure->name = $name;
		$exposure->unit = $unit;
		$exposure->variant = $variant;
		$exposure->exposedAt = $exposedAt;
		$exposure->assigned = $assigned;
		$exposure->eligible = $eligible;
		$exposure->overridden = $overridden;
		$exposure->fullOn = $fullOn;
		$exposure->custom = $custom;
		$exposure->audienceMismatch = $audienceMismatch;

		return $exposure;
	}

	protected static function getTime(): int {
		return (int) (microtime(true) * 1000);
	}
}

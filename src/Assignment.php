<?php

namespace Absmartly\SDK;

use stdClass;

class Assignment {
	public int $id = 0;
	public int $iteration = 0;
	public int $fullOnVariant = 0;
	public string $name = '';
	public ?string $unitType;
	public array $trafficSplit;
	public int $variant = 0;
	public bool $assigned = false;
	public bool $overridden = false;
	public bool $eligible = false;
	public bool $fullOn = false;
	public bool $custom = false;

	public bool $audienceMismatch = false;
	public stdClass $variables;

	public bool $exposed;
}


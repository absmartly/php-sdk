<?php

namespace ABSmartly\SDK;

use stdClass;

class Assignment {
	public int $id = 0;
	public int $iteration = 0;
	public int $fullOnVariant = 0;
	public string $name = '';
	public ?string $unitType = null;
	public array $trafficSplit = [];
	public int $variant = 0;
	public bool $assigned = false;
	public bool $overridden = false;
	public bool $eligible = false;
	public bool $fullOn = false;
	public bool $custom = false;
	public bool $ruleOverride = false;
	public ?int $ruleVariant = null;
	public bool $isRuleMissingUnit = false;
	/** Exposed while resolved without its unit; unlike $exposed, not cleared when the queued exposure is evicted. */
	public bool $isUnitPinnedMissing = false;
	public string $ruleKey = '';

	public bool $audienceMismatch = false;
	public stdClass $variables;

	public bool $exposed = false;
	public int $attrsSeq = 0;
}


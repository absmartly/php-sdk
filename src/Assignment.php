<?php

namespace ABSmartly\SDK;

use stdClass;

#[\AllowDynamicProperties]
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
	/**
	 * Exposed, or passed over by an exposing variable read: a later unit leaves it as it was, as without holdouts.
	 * Unlike $exposed, not cleared when the queued exposure is evicted, so a retry can't change what was served.
	 */

	public int $attrsSeq = 0;

	/** Held out by one of the experiment's holdouts and not won by an override or rule: control, no own exposure. */

	/** @var object[]|null The experiment's holdouts when this was resolved, sorted by id. */

	/** @var (Assignment|null)[]|null Each holdout's arm, aligned with $holdouts; null where the unit was missing. */

	/** For a holdout's own arm: count($split) when the arm was drawn, so a same-iteration refresh can't re-mean it. */

}


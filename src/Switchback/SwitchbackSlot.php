<?php
declare(strict_types=1);

namespace ABSmartly\SDK\Switchback;

/**
 * The switchback state of one cached assignment slot: its period and the serving resolution read for it (read once per
 * slot).
 *
 * @internal
 */
final class SwitchbackSlot {
	/** The period index, or null for the experiment's single pre-start/unservable/legacy slot. */
	public ?int $period;
	/** The serving resolution; null only for an override that never needed one. */
	public ?SwitchbackServing $serving = null;
	/** The resolution records nothing: no linked unit, an ineligible cluster, or a pre-start/unservable draw. */
	public bool $silent = false;
	/** The custom assignment read while resolving, so a later setCustomAssignment() is detected as stale. */
	public ?int $custom = null;

	public function __construct(?int $period) {
		$this->period = $period;
	}
}

<?php
declare(strict_types=1);

namespace ABSmartly\SDK\Switchback;

/**
 * The switchback state of one cached assignment slot: its period and the serving resolution read for it (read once per
 * slot), and the reading of the call that last resolved it.
 *
 * @internal
 */
final class SwitchbackSlot {
	/** The period index, or null for the experiment's single pre-start/unservable/legacy slot. */
	public ?int $period;
	public SwitchbackServing $serving;
	/** The clock reading of the latest call that resolved this slot; an exposure the call queues carries it. */
	public int $readAt = 0;
	/** The resolution records nothing: no linked unit, an ineligible cluster, or a pre-start/unservable draw. */
	public bool $silent = false;
	/** The custom assignment read while resolving, so a later setCustomAssignment() is detected as stale. */
	public ?int $custom = null;
	/**
	 * The context units when the serving was read. The Collector resolves the serving unit again from a publish's units,
	 * so an exposure is published with these, not with units set after it.
	 */
	public array $units = [];

	public function __construct(?int $period) {
		$this->period = $period;
	}
}

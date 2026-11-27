<?php
declare(strict_types=1);

namespace ABSmartly\SDK\Switchback;

use ABSmartly\SDK\Experiment;

use function count;

/**
 * What the context derives from an installed switchback experiment: its cluster sources split in declared order, the
 * grid a cached assignment is pinned to, and the switchback generation (see Context::setData()).
 *
 * @internal
 */
final class SwitchbackSources {
	/** The Collector's anchor bounds (Registry): an anchor must be hour aligned and in [0, 10000-01-01T00:00:00Z). */
	private const ANCHOR_ALIGNMENT_MS = 3600000;
	private const MAX_ANCHOR_MS = 253402300800000;

	/** @var string[] The linked unit types, in declared order. */
	public array $types = [];
	/** @var SwitchbackAttributePath[]|null Parallel to $types; null for a missing, empty or unreadable map (legacy). */
	public ?array $paths = null;
	/**
	 * Every input to the period grid and the cluster key: the ordered cluster map, the allow-list, startAt, periodMs
	 * and the design. Part of the switchback identity (Context::installSwitchback()).
	 */
	public array $config;
	/** Whether the schedule can be drawn: see servable(). */
	public bool $servable;
	public int $id;
	public int $iteration;
	public string $unitType;
	/** Carried over by a refresh while the switchback identity is unchanged; a fresh value otherwise. */
	public int $generation = 0;

	public function __construct(Experiment $experiment) {
		$this->id = $experiment->id;
		$this->iteration = $experiment->iteration;
		$this->unitType = $experiment->unitType;
		$this->config = [
			($experiment->switchbackClusters ?? null),
			($experiment->switchbackClusterValues ?? null),
			($experiment->switchbackStartAt ?? null),
			($experiment->switchbackPeriodMs ?? null),
			($experiment->switchbackDesignType ?? null),
		];
		$this->servable = self::servable($experiment);

		if (($experiment->switchbackClusters ?? null) === null || ($experiment->switchbackClusters ?? null) === []) {
			return;
		}
		$paths = [];
		foreach (($experiment->switchbackClusters ?? null) as $unitType => $path) {
			$parsed = SwitchbackAttributePath::parseSupported($path);
			if ($parsed === null) {
				$this->types = [];
				return;
			}
			// An array key that looks like an integer ("123") is an int in PHP; unit types are strings.
			$this->types[] = (string) $unitType;
			$paths[] = $parsed;
		}
		$this->paths = $paths;
	}

	/**
	 * Whether the Collector's Registry can serve this schedule: a start that is set, hour aligned and in
	 * [0, 10000-01-01) (it is used as served, never rounded), a period > 0 and at least two arms. An unservable schedule
	 * only replaces the draw: assignment rules, the audience and full-on still apply.
	 */
	private static function servable(Experiment $experiment): bool {
		$startAt = ($experiment->switchbackStartAt ?? null);
		$periodMs = ($experiment->switchbackPeriodMs ?? null);
		return $startAt !== null && $startAt >= 0 && $startAt < self::MAX_ANCHOR_MS && $startAt % self::ANCHOR_ALIGNMENT_MS === 0
			&& $periodMs !== null && $periodMs > 0
			&& count($experiment->split) >= 2;
	}
}

<?php
declare(strict_types=1);

namespace ABSmartly\SDK\Switchback;

use function in_array;

/**
 * Which unit serves a switchback experiment and under which cluster key. Port of the Collector's
 * ContextAPI.switchbackServing (R33, cluster-defined switchbacks), as java-sdk's SwitchbackServing:
 *
 * - The experiment's switchbackClusters object, in its declared order, lists the linked unit types and, for each, the
 *   attribute path holding its cluster value.
 * - No context unit of a linked type: the experiment does not apply (ABSENT).
 * - Otherwise each linked unit, in source order, reads the attribute at its source's path, normalised by
 *   SwitchbackClusterKeys. The first unit yielding a value is the serving unit and the value is the cluster key; units
 *   yielding no value are skipped.
 * - INELIGIBLE, served under the first linked unit: no unit yields a value, two units yield different values, or an
 *   allow-list is set and lacks the key.
 * - ELIGIBLE otherwise.
 * - A missing, empty or unreadable cluster map takes the Collector's legacy path instead (legacy()).
 *
 * @internal
 */
final class SwitchbackServing {
	public const ABSENT = 0;
	public const INELIGIBLE = 1;
	public const ELIGIBLE = 2;

	public int $status;
	/** The serving unit's type when ELIGIBLE, the first linked unit's when INELIGIBLE, null when ABSENT. */
	public ?string $unitType;
	/** The raw normalised cluster key when ELIGIBLE; never hashed. */
	public ?string $clusterKey;

	private function __construct(int $status, ?string $unitType, ?string $clusterKey) {
		$this->status = $status;
		$this->unitType = $unitType;
		$this->clusterKey = $clusterKey;
	}

	/**
	 * A context holds one uid per unit type, so each source has at most one unit; the declared source order alone
	 * decides which unit serves, whatever order the units were set in.
	 *
	 * @param string[] $sourceTypes the cluster map's unit types, in declared order
	 * @param SwitchbackAttributePath[] $sourcePaths parallel to $sourceTypes
	 * @param string[]|null $allowedValues
	 * @param array<string, string> $units the context's units by type
	 * @param callable(SwitchbackAttributePath): mixed $read the attribute value at a path, or null
	 */
	public static function resolve(array $sourceTypes, array $sourcePaths, ?array $allowedValues, array $units, callable $read): self {
		$first = null;
		$serving = null;
		$key = null;
		$disagreement = false;
		foreach ($sourceTypes as $s => $type) {
			if (!isset($units[$type])) {
				continue;
			}
			if ($first === null) {
				$first = $type;
			}
			$value = SwitchbackClusterKeys::normalize($read($sourcePaths[$s]));
			if ($value === null) {
				continue;
			}
			if ($key === null) {
				$key = $value;
				$serving = $type;
			}
			elseif ($key !== $value) {
				$disagreement = true;
			}
		}

		if ($first === null) {
			return new self(self::ABSENT, null, null);
		}
		if ($key === null || $disagreement || !self::allowed($allowedValues, $key)) {
			return new self(self::INELIGIBLE, $first, null);
		}
		return new self(self::ELIGIBLE, $serving, $key);
	}

	/**
	 * The Collector's compatibility path for a missing, empty or unreadable cluster map: the experiment's declared
	 * unitType only identifies an ineligible control response (never an assignment or a cluster); without a unit of
	 * that type the experiment does not apply.
	 *
	 * @param array<string, string> $units
	 */
	public static function legacy(string $unitType, array $units): self {
		return isset($units[$unitType]) ? new self(self::INELIGIBLE, $unitType, null) : new self(self::ABSENT, null, null);
	}

	/**
	 * A null or empty allow-list admits every value: the Collector serves an experiment that has no allow-list rows
	 * with this field absent, and builds its filter set only when the list is non-empty.
	 *
	 * @param string[]|null $allowedValues
	 */
	public static function allowed(?array $allowedValues, string $key): bool {
		return $allowedValues === null || $allowedValues === [] || in_array($key, $allowedValues, true);
	}
}

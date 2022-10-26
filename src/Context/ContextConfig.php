<?php

namespace Absmartly\SDK\Context;

use Absmartly\SDK\ContextEventLogger;

class ContextConfig {
	private array $units = [];
	private array $attributes = [];
	private array $overrides = [];
	private array $assignments = [];

	private int $publishDelay = 100;
	private int $refreshInterval = 0;
	private ContextEventLogger $eventLogger;


	public function setPublishDelay(int $publishDelay): ContextConfig {
		$this->publishDelay = $publishDelay;

		return $this;
	}

	public function getPublishDelay(): int {
		return $this->publishDelay;
	}

	public function setRefreshInterval(int $refreshInterval): ContextConfig {
		$this->refreshInterval = $refreshInterval;

		return $this;
	}

	public function getRefreshInterval(): int {
		return $this->refreshInterval;
	}

	public function getEventLogger(): ContextEventLogger {
		return $this->eventLogger;
	}

	public function setEventLogger(ContextEventLogger $eventLogger): ContextConfig {
		$this->eventLogger = $eventLogger;
		return $this;
	}



	public function setUnit(string $unitType, string $uid): ContextConfig {
		$this->units[$unitType] = $uid;
		return $this;
	}

	public function setUnits(array $units): ContextConfig {
		// It could have been possible to simply array_merge here, but we need
		// to verify strict-types, hence the foreach loop.
		foreach ($units as $key => $value) {
			if (!is_scalar($value)) {
				throw new \InvalidArgumentException(sprintf('Unit set value with key "%s" must be of type string, %s passed', $key, gettype($value)));
			}

			$this->units[$key] = (string) $value;
		}

		return $this;
	}

	public function getUnit(string $unitType): ?string {
		return $this->units[$unitType] ?? null;
	}

	public function getUnits(): array {
		return $this->units;
	}







	public function setAttribute(string $name, object $value): ContextConfig {
		$this->attributes[$name] = $value;
		return $this;
	}

	public function setAttributes(array $attributes): ContextConfig {
		// See note in ContextConfig::setUnits
		foreach ($attributes as $key => $value) {
			if (!is_object($value)) {
				throw new \InvalidArgumentException(sprintf('Attribute set value with key "%s" must be of type object, %s passed', $key, gettype($value)));
			}

			$this->attributes[$key] = $value;
		}

		return $this;
	}

	public function getAttribute(string $name): ?object {
		return $this->attributes[$name] ?? null;
	}

	public function getAttributes(): array {
		return $this->attributes;
	}









	public function setOverride(string $experimentName, int $variant): ContextConfig {
		$this->overrides[$experimentName] = $variant;
		return $this;
	}

	public function setOverrides(array $overrides): ContextConfig {
		// See note in ContextConfig::setUnits
		foreach ($overrides as $key => $value) {
			if (!is_integer($value)) {
				throw new \InvalidArgumentException(sprintf('Override set value with key "%s" must be of type integer, %s passed', $key, gettype($value)));
			}

			$this->overrides[$key] = $value;
		}

		return $this;
	}

	public function getOverride(string $experimentName): ?int {
		return $this->overrides[$experimentName] ?? null;
	}

	public function getOverrides(): array {
		return $this->overrides;
	}









	public function setCustomAssignment(string $experimentName, int $variant): ContextConfig {
		$this->assignments[$experimentName] = $variant;
		return $this;
	}

	public function setCustomAssignments(array $customAssignments): ContextConfig {
		// See note in ContextConfig::setUnits
		foreach ($customAssignments as $key => $value) {
			if (!is_integer($value)) {
				throw new \InvalidArgumentException(sprintf('Custom assignment set value with key "%s" must be of type integer, %s passed', $key, gettype($value)));
			}

			$this->assignments[$key] = (int) $value;
		}

		return $this;
	}

	public function getCustomAssignment(string $experimentName): ?int {
		return $this->assignments[$experimentName] ?? null;
	}

	public function getCustomAssignments(): array {
		return $this->assignments;
	}

}

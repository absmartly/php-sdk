<?php

namespace ABSmartly\SDK\Context;

use InvalidArgumentException;

use function gettype;
use function is_int;
use function is_scalar;
use function sprintf;

class ContextConfig {
	private array $units = [];
	private array $attributes = [];
	private array $overrides = [];
	private array $assignments = [];

	private int $publishDelay = 100;
	private int $refreshInterval = 0;

	private ContextEventLogger $eventLogger;
	private ContextEventHandler $eventHandler;

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

	public function getEventLogger(): ?ContextEventLogger {
		return $this->eventLogger ?? null;
	}

	public function setEventLogger(ContextEventLogger $eventLogger): ContextConfig {
		$this->eventLogger = $eventLogger;
		return $this;
	}

	public function setEventHandler(ContextEventHandler $eventHandler): ContextConfig {
		$this->eventHandler = $eventHandler;
		return $this;
	}

	public function setUnit(string $unitType, string $uid): ContextConfig {
		if ($uid === '') {
			throw new InvalidArgumentException(sprintf('Unit "%s" UID must not be blank', $unitType));
		}

		$this->units[$unitType] = $uid;
		return $this;
	}

	public function setUnits(array $units): ContextConfig {
		// It could have been possible to simply array_merge here, but we need
		// to verify strict-types, hence the foreach loop.
		foreach ($units as $unitType => $uid) {
			if (!is_scalar($uid)) {
				throw new InvalidArgumentException(
					sprintf('Unit set value with key "%s" must be of type string, %s passed', $unitType, gettype($uid)));
			}

			$this->setUnit($unitType, (string) $uid);
		}

		return $this;
	}

	public function getUnit(string $unitType): ?string {
		return $this->units[$unitType] ?? null;
	}

	public function getUnits(): array {
		return $this->units;
	}

	public function setAttribute(string $name, $value): ContextConfig {
		$this->attributes[] = (object) [
			'name' => $name,
			'value' => $value,
			'setAt' => Context::getTime(),
		];

		return $this;
	}

	public function setAttributes(array $attributes): ContextConfig {
		foreach ($attributes as $key => $value) {
			$this->setAttribute($key, $value);
		}

		return $this;
	}

	public function getAttribute(string $name) {
		foreach (array_reverse($this->attributes) as $attribute) {
			if ($attribute->name === $name) {
				return $attribute->value;
			}
		}

		return null;
	}

	public function getAttributes(): array {
		$result = [];
		foreach ($this->attributes as $attribute) {
			$result[$attribute->name] = $attribute->value;
		}

		return $result;
	}

	public function setOverride(string $experimentName, int $variant): ContextConfig {
		$this->overrides[$experimentName] = $variant;
		return $this;
	}

	public function setOverrides(array $overrides): ContextConfig {
		// See note in ContextConfig::setUnits
		foreach ($overrides as $experimentName => $variant) {
			if (!is_int($variant)) {
				throw new InvalidArgumentException(
					sprintf('Override set value with key "%s" must be of type integer, %s passed', $experimentName, gettype($variant)));
			}
			$this->setOverride($experimentName, $variant);
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
			if (!is_int($value)) {
				throw new InvalidArgumentException(
					sprintf('Custom assignment set value with key "%s" must be of type integer, %s passed', $key, gettype($value)));
			}

			$this->assignments[$key] = $value;
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
